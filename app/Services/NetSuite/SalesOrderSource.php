<?php

namespace App\Services\NetSuite;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class SalesOrderSource
{
    public function __construct(private SuiteQlClient $client, private CustomerSource $customers) {}

    /** @return array<string, mixed> */
    public function customer(int $customerId): array
    {
        return $this->customers->find($customerId);
    }

    public function currentTime(): CarbonImmutable
    {
        $page = $this->client->query("SELECT TO_CHAR(SYS_EXTRACT_UTC(CURRENT_TIMESTAMP), 'YYYY-MM-DD HH24:MI:SS') AS current_time FROM DUAL");

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new RuntimeException('NetSuite did not return a reliable source clock.');
        }

        Validator::make($page['items'][0], [
            'current_time' => ['required', 'date_format:Y-m-d H:i:s'],
        ])->validate();

        return CarbonImmutable::parse($page['items'][0]['current_time'], 'UTC');
    }

    /** @return Generator<int, array<string, mixed>> */
    public function orders(int $customerId, ?CarbonImmutable $modifiedSince = null, ?CarbonImmutable $modifiedUntil = null): Generator
    {
        $this->assertPositiveId($customerId);
        $lastId = 0;
        $window = '';

        if (($modifiedSince === null) !== ($modifiedUntil === null) || ($modifiedSince !== null && $modifiedSince->greaterThan($modifiedUntil))) {
            throw new InvalidArgumentException('Provide an ordered pair of source timestamps for incremental sync.');
        }

        if ($modifiedSince !== null) {
            $since = $modifiedSince->setTimezone('UTC')->format('Y-m-d H:i:s');
            $until = $modifiedUntil->setTimezone('UTC')->format('Y-m-d H:i:s');
            $window = " AND SYS_EXTRACT_UTC(lastmodifieddate) >= TO_TIMESTAMP('{$since}', 'YYYY-MM-DD HH24:MI:SS')"
                ." AND SYS_EXTRACT_UTC(lastmodifieddate) <= TO_TIMESTAMP('{$until}', 'YYYY-MM-DD HH24:MI:SS')";
        }

        do {
            $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'SalesOrd' AND id > {$lastId}{$window} ORDER BY id");

            foreach ($page['items'] as $order) {
                $this->validateOrder($order, $customerId);

                if ($modifiedSince !== null && ! CarbonImmutable::parse($order['updated_at'], 'UTC')->betweenIncluded($modifiedSince, $modifiedUntil)) {
                    throw new RuntimeException('NetSuite returned an order outside the requested modification window.');
                }

                if ((int) $order['id'] <= $lastId) {
                    throw new RuntimeException('NetSuite sales-order pagination did not advance.');
                }

                $lastId = (int) $order['id'];
                yield $order;
            }
        } while ($page['hasMore']);
    }

    /** @return array<string, mixed> */
    public function order(int $customerId, int $orderId): array
    {
        $this->assertPositiveId($customerId);
        $this->assertPositiveId($orderId);
        $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'SalesOrd' AND id = {$orderId}");

        if (count($page['items']) !== 1 || $page['hasMore'] || (int) ($page['items'][0]['id'] ?? 0) !== $orderId) {
            throw new RuntimeException('The sales order disappeared or moved while it was being imported. Retry the sync.');
        }

        $this->validateOrder($page['items'][0], $customerId);

        return $page['items'][0];
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $customerId, int $orderId): array
    {
        return $this->linesForOrders($customerId, [$orderId])[$orderId];
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array<string, mixed>>
     */
    public function ordersByIds(int $customerId, array $orderIds): array
    {
        $ids = $this->orderIdList($customerId, $orderIds);
        $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'SalesOrd' AND id IN ({$ids}) ORDER BY id");
        $orders = [];

        foreach ($page['items'] as $order) {
            $this->validateOrder($order, $customerId);
            $id = (int) $order['id'];

            if (! in_array($id, $orderIds, true) || isset($orders[$id])) {
                throw new RuntimeException('NetSuite returned an unexpected or duplicate sales order.');
            }

            $orders[$id] = $order;
        }

        if ($page['hasMore'] || count($orders) !== count($orderIds)) {
            throw new RuntimeException('A sales order disappeared or moved during import. Retry the sync.');
        }

        return $orders;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesForOrders(int $customerId, array $orderIds): array
    {
        $ids = $this->orderIdList($customerId, $orderIds);
        $lastOrderId = 0;
        $lastLineId = -1;
        $lines = array_fill_keys($orderIds, []);

        do {
            $page = $this->client->query(<<<SQL
                SELECT transactionline.transaction AS transaction_id, transactionline.id AS line_id,
                    transactionline.item AS item_id, item.itemid AS item_number,
                    transactionline.quantity, transactionline.rate, transactionline.netamount AS amount,
                    transactionline.memo, transactionline.mainline, transactionline.taxline,
                    transactionline.transactiondiscount AS discount_line,
                    transactionline.transactionlinetype AS line_type
                FROM transactionline
                JOIN transaction ON transaction.id = transactionline.transaction
                LEFT JOIN item ON item.id = transactionline.item
                WHERE transaction.entity = {$customerId} AND transaction.type = 'SalesOrd'
                    AND transactionline.transaction IN ({$ids})
                    AND (transactionline.transaction > {$lastOrderId}
                        OR (transactionline.transaction = {$lastOrderId} AND transactionline.id > {$lastLineId}))
                ORDER BY transactionline.transaction, transactionline.id
                SQL);

            foreach ($page['items'] as $line) {
                Validator::make($line, [
                    'transaction_id' => ['required', 'integer', 'in:'.$ids],
                    'line_id' => ['required', 'integer', 'min:0'],
                    'item_id' => ['nullable', 'integer'],
                    'item_number' => ['nullable', 'string', 'max:255'],
                    'memo' => ['nullable', 'string'],
                    'quantity' => ['nullable', 'numeric'],
                    'rate' => ['nullable', 'numeric'],
                    'amount' => ['nullable', 'numeric'],
                    'mainline' => ['required', 'in:T,F'],
                    'taxline' => ['required', 'in:T,F'],
                    'discount_line' => ['required', 'in:T,F'],
                    'line_type' => ['nullable', 'string', 'max:255'],
                ])->validate();
                $orderId = (int) $line['transaction_id'];
                $lineId = (int) $line['line_id'];

                if ($orderId < $lastOrderId || ($orderId === $lastOrderId && $lineId <= $lastLineId)) {
                    throw new RuntimeException('NetSuite line pagination did not advance.');
                }

                $lastOrderId = $orderId;
                $lastLineId = $lineId;
                $lines[$orderId][] = $line;
            }
        } while ($page['hasMore']);

        foreach ($lines as $orderLines) {
            if ($orderLines === []) {
                throw new RuntimeException('NetSuite returned no lines for a sales order; existing data was retained.');
            }
        }

        return $lines;
    }

    /** @param list<int> $orderIds */
    private function orderIdList(int $customerId, array $orderIds): string
    {
        $this->assertPositiveId($customerId);

        if ($orderIds === [] || count($orderIds) > 50 || count(array_unique($orderIds)) !== count($orderIds)) {
            throw new InvalidArgumentException('Request between 1 and 50 distinct sales-order IDs.');
        }

        foreach ($orderIds as $id) {
            if (! is_int($id)) {
                throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
            }

            $this->assertPositiveId($id);
        }

        return implode(',', $orderIds);
    }

    /** @return array{orders: list<array<string, mixed>>, lines: list<array<string, mixed>>} */
    public function controlTotals(int $customerId): array
    {
        $this->assertPositiveId($customerId);
        $orders = $this->client->query(<<<SQL
            SELECT currency AS currency_id, COUNT(*) AS order_count,
                TO_CHAR(SUM(total)) AS total, TO_CHAR(SUM(foreigntotal)) AS foreign_total
            FROM transaction
            WHERE entity = {$customerId} AND type = 'SalesOrd'
            GROUP BY currency
            ORDER BY currency
            SQL);
        $lines = $this->client->query(<<<SQL
            SELECT transaction.currency AS currency_id, COUNT(*) AS line_count,
                COUNT(transactionline.quantity) AS quantity_count,
                COUNT(transactionline.netamount) AS amount_count,
                TO_CHAR(NVL(SUM(transactionline.quantity), 0)) AS quantity,
                TO_CHAR(NVL(SUM(transactionline.netamount), 0)) AS amount,
                TO_CHAR(NVL(SUM(CASE WHEN transactionline.mainline = 'F' THEN transactionline.netamount ELSE 0 END), 0)) AS detail_amount
            FROM transactionline
            JOIN transaction ON transaction.id = transactionline.transaction
            WHERE transaction.entity = {$customerId} AND transaction.type = 'SalesOrd'
            GROUP BY transaction.currency
            ORDER BY transaction.currency
            SQL);

        if ($orders['hasMore'] || $lines['hasMore']) {
            throw new RuntimeException('NetSuite control totals were truncated; reconciliation cannot complete.');
        }

        Validator::make($orders, [
            'items.*.currency_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.order_count' => ['required', 'integer', 'min:1'],
            'items.*.total' => ['required', 'numeric'],
            'items.*.foreign_total' => ['required', 'numeric'],
        ])->validate();
        Validator::make($lines, [
            'items.*.currency_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.line_count' => ['required', 'integer', 'min:1'],
            'items.*.quantity_count' => ['required', 'integer', 'min:0'],
            'items.*.amount_count' => ['required', 'integer', 'min:0'],
            'items.*.quantity' => ['required', 'numeric'],
            'items.*.amount' => ['required', 'numeric'],
            'items.*.detail_amount' => ['required', 'numeric'],
        ])->validate();

        return ['orders' => $orders['items'], 'lines' => $lines['items']];
    }

    private function headerSql(): string
    {
        return 'SELECT id, entity AS customer_id, type, tranid AS number, otherrefnum AS purchase_order_number, '
            ."TO_CHAR(trandate, 'YYYY-MM-DD') AS transaction_date, status, BUILTIN.DF(status) AS status_name, "
            .'currency AS currency_id, total, foreigntotal AS foreign_total, memo, '
            ."TO_CHAR(SYS_EXTRACT_UTC(lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at FROM transaction";
    }

    /** @param array<string, mixed> $order */
    private function validateOrder(array $order, int $customerId): void
    {
        Validator::make($order, [
            'id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'in:'.$customerId],
            'type' => ['required', 'in:SalesOrd'],
            'number' => ['required', 'string', 'max:255'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'string', 'max:255'],
            'status_name' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['required', 'integer', 'min:1'],
            'total' => ['required', 'numeric'],
            'foreign_total' => ['required', 'numeric'],
            'memo' => ['nullable', 'string'],
            'updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ])->validate();
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }
    }
}

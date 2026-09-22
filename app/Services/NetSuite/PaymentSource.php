<?php

namespace App\Services\NetSuite;

use Generator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class PaymentSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @return array<string, mixed> */
    public function payment(int $customerId, int $paymentId): array
    {
        $this->assertPositiveId($customerId);
        $this->assertPositiveId($paymentId);
        $page = $this->client->query(
            $this->headerSql()." WHERE entity = {$customerId} AND type = 'CustPymt' AND id = {$paymentId}"
        );

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new RuntimeException('Payment was not found for this customer or is not accessible.');
        }

        $payment = $page['items'][0];
        $this->validatePayment($payment, $customerId);
        Validator::make($payment, ['id' => ['in:'.$paymentId]])->validate();

        return $payment;
    }

    /**
     * @param  list<int>  $paymentIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesForPayments(int $customerId, array $paymentIds): array
    {
        $ids = $this->paymentIdList($customerId, $paymentIds);
        $lastPaymentId = 0;
        $lastLineId = -1;
        $lines = array_fill_keys($paymentIds, []);

        do {
            $page = $this->client->query(<<<SQL
                SELECT transactionline.transaction AS transaction_id, transactionline.id AS line_id,
                    transactionline.item AS item_id, item.itemid AS item_number,
                    transactionline.createdfrom AS source_transaction_id,
                    transactionline.quantity, transactionline.rate, transactionline.netamount AS amount,
                    transactionline.memo, transactionline.mainline, transactionline.taxline,
                    transactionline.transactiondiscount AS discount_line,
                    transactionline.transactionlinetype AS line_type
                FROM transactionline
                JOIN transaction ON transaction.id = transactionline.transaction
                LEFT JOIN item ON item.id = transactionline.item
                WHERE transaction.entity = {$customerId} AND transaction.type = 'CustPymt'
                    AND transactionline.transaction IN ({$ids})
                    AND (transactionline.transaction > {$lastPaymentId}
                        OR (transactionline.transaction = {$lastPaymentId} AND transactionline.id > {$lastLineId}))
                ORDER BY transactionline.transaction, transactionline.id
                SQL);

            foreach ($page['items'] as $line) {
                Validator::make($line, [
                    'transaction_id' => ['required', 'integer', 'in:'.$ids],
                    'line_id' => ['required', 'integer', 'min:0'],
                    'item_id' => ['nullable', 'integer'],
                    'source_transaction_id' => ['nullable', 'integer', 'min:1'],
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
                $paymentId = (int) $line['transaction_id'];
                $lineId = (int) $line['line_id'];

                if ($paymentId < $lastPaymentId || ($paymentId === $lastPaymentId && $lineId <= $lastLineId)) {
                    throw new RuntimeException('NetSuite line pagination did not advance.');
                }

                $lastPaymentId = $paymentId;
                $lastLineId = $lineId;
                $lines[$paymentId][] = $line;
            }
        } while ($page['hasMore']);

        foreach ($lines as $paymentLines) {
            if ($paymentLines === []) {
                throw new RuntimeException('NetSuite returned no lines for a payment; existing data was retained.');
            }
        }

        return $lines;
    }

    /** @param list<int> $paymentIds */
    private function paymentIdList(int $customerId, array $paymentIds): string
    {
        $this->assertPositiveId($customerId);

        if ($paymentIds === [] || count($paymentIds) > 50 || count(array_unique($paymentIds)) !== count($paymentIds)) {
            throw new InvalidArgumentException('Request between 1 and 50 distinct payment IDs.');
        }

        foreach ($paymentIds as $id) {
            if (! is_int($id)) {
                throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
            }

            $this->assertPositiveId($id);
        }

        return implode(',', $paymentIds);
    }

    /** @param array<string, mixed> $payment */
    private function validatePayment(array $payment, int $customerId): void
    {
        Validator::make($payment, [
            'id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'in:'.$customerId],
            'type' => ['required', 'in:CustPymt'],
            'number' => ['required', 'string', 'max:255'],
            'purchase_order_number' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'string', 'max:255'],
            'status_name' => ['nullable', 'string', 'max:255'],
            'currency_id' => ['required', 'integer', 'min:1'],
            'total' => ['required', 'numeric'],
            'foreign_total' => ['required', 'numeric'],
            'memo' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'foreign_amount_paid' => ['nullable', 'numeric'],
            'foreign_amount_unpaid' => ['nullable', 'numeric'],
            'updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ])->validate();
    }

    /** @return Generator<int, array<string, mixed>> */
    public function payments(int $customerId): Generator
    {
        $this->assertPositiveId($customerId);
        $lastId = 0;
        do {
            $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'CustPymt' AND id > {$lastId} ORDER BY id");
            foreach ($page['items'] as $payment) {
                $this->validatePayment($payment, $customerId);
                if ((int) $payment['id'] <= $lastId) {
                    throw new RuntimeException('NetSuite payment pagination did not advance.');
                }
                $lastId = (int) $payment['id'];
                yield $payment;
            }
        } while ($page['hasMore']);
    }

    /** @param list<int> $paymentIds
     * @return array<int, array<string, mixed>>
     */
    public function paymentsByIds(int $customerId, array $paymentIds): array
    {
        $ids = $this->paymentIdList($customerId, $paymentIds);
        $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'CustPymt' AND id IN ({$ids}) ORDER BY id");
        $payments = [];
        foreach ($page['items'] as $payment) {
            $this->validatePayment($payment, $customerId);
            $id = (int) $payment['id'];
            if (! in_array($id, $paymentIds, true) || isset($payments[$id])) {
                throw new RuntimeException('NetSuite returned an unexpected or duplicate payment.');
            }
            $payments[$id] = $payment;
        }
        if ($page['hasMore'] || count($payments) !== count($paymentIds)) {
            throw new RuntimeException('A payment disappeared or moved during import. Retry the sync.');
        }

        return $payments;
    }

    private function headerSql(): string
    {
        return 'SELECT id, entity AS customer_id, type, tranid AS number, otherrefnum AS purchase_order_number, '
            ."TO_CHAR(trandate, 'YYYY-MM-DD') AS transaction_date, status, BUILTIN.DF(status) AS status_name, "
            .'currency AS currency_id, total, foreigntotal AS foreign_total, memo, '
            ."TO_CHAR(duedate, 'YYYY-MM-DD') AS due_date, foreignamountpaid AS foreign_amount_paid, foreignamountunpaid AS foreign_amount_unpaid, "
            ."TO_CHAR(SYS_EXTRACT_UTC(lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at "
            .'FROM transaction';
    }

    /** @return array{payments: list<array<string, mixed>>, lines: list<array<string, mixed>>} */
    public function controlTotals(int $customerId): array
    {
        $this->assertPositiveId($customerId);
        $payments = $this->client->query(<<<SQL
            SELECT currency AS currency_id, COUNT(*) AS payment_count,
                COUNT(foreignamountpaid) AS paid_count, COUNT(foreignamountunpaid) AS unpaid_count,
                TO_CHAR(NVL(SUM(foreignamountpaid), 0)) AS foreign_amount_paid,
                TO_CHAR(NVL(SUM(foreignamountunpaid), 0)) AS foreign_amount_unpaid,
                TO_CHAR(SUM(total)) AS total, TO_CHAR(SUM(foreigntotal)) AS foreign_total
            FROM transaction
            WHERE entity = {$customerId} AND type = 'CustPymt'
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
            WHERE transaction.entity = {$customerId} AND transaction.type = 'CustPymt'
            GROUP BY transaction.currency
            ORDER BY transaction.currency
            SQL);

        if ($payments['hasMore'] || $lines['hasMore']) {
            throw new RuntimeException('NetSuite control totals were truncated; reconciliation cannot complete.');
        }

        Validator::make($payments, [
            'items.*.currency_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.payment_count' => ['required', 'integer', 'min:1'],
            'items.*.paid_count' => ['required', 'integer', 'min:0'],
            'items.*.unpaid_count' => ['required', 'integer', 'min:0'],
            'items.*.foreign_amount_paid' => ['required', 'numeric'],
            'items.*.foreign_amount_unpaid' => ['required', 'numeric'],
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

        return ['payments' => $payments['items'], 'lines' => $lines['items']];
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }
    }
}

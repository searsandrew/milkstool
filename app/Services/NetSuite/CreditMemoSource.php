<?php

namespace App\Services\NetSuite;

use Generator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class CreditMemoSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @return array<string, mixed> */
    public function creditMemo(int $customerId, int $creditMemoId): array
    {
        $this->assertPositiveId($customerId);
        $this->assertPositiveId($creditMemoId);
        $page = $this->client->query(
            $this->headerSql()." WHERE entity = {$customerId} AND type = 'CustCred' AND id = {$creditMemoId}"
        );

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new RuntimeException('Credit memo was not found for this customer or is not accessible.');
        }

        $creditMemo = $page['items'][0];
        $this->validateCreditMemo($creditMemo, $customerId);
        Validator::make($creditMemo, ['id' => ['in:'.$creditMemoId]])->validate();

        return $creditMemo;
    }

    /**
     * @param  list<int>  $creditMemoIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesForCreditMemos(int $customerId, array $creditMemoIds): array
    {
        $ids = $this->creditMemoIdList($customerId, $creditMemoIds);
        $lastCreditMemoId = 0;
        $lastLineId = -1;
        $lines = array_fill_keys($creditMemoIds, []);

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
                WHERE transaction.entity = {$customerId} AND transaction.type = 'CustCred'
                    AND transactionline.transaction IN ({$ids})
                    AND (transactionline.transaction > {$lastCreditMemoId}
                        OR (transactionline.transaction = {$lastCreditMemoId} AND transactionline.id > {$lastLineId}))
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
                $creditMemoId = (int) $line['transaction_id'];
                $lineId = (int) $line['line_id'];

                if ($creditMemoId < $lastCreditMemoId || ($creditMemoId === $lastCreditMemoId && $lineId <= $lastLineId)) {
                    throw new RuntimeException('NetSuite line pagination did not advance.');
                }

                $lastCreditMemoId = $creditMemoId;
                $lastLineId = $lineId;
                $lines[$creditMemoId][] = $line;
            }
        } while ($page['hasMore']);

        foreach ($lines as $creditMemoLines) {
            if ($creditMemoLines === []) {
                throw new RuntimeException('NetSuite returned no lines for a credit memo; existing data was retained.');
            }
        }

        return $lines;
    }

    /** @param list<int> $creditMemoIds */
    private function creditMemoIdList(int $customerId, array $creditMemoIds): string
    {
        $this->assertPositiveId($customerId);

        if ($creditMemoIds === [] || count($creditMemoIds) > 50 || count(array_unique($creditMemoIds)) !== count($creditMemoIds)) {
            throw new InvalidArgumentException('Request between 1 and 50 distinct credit memo IDs.');
        }

        foreach ($creditMemoIds as $id) {
            if (! is_int($id)) {
                throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
            }

            $this->assertPositiveId($id);
        }

        return implode(',', $creditMemoIds);
    }

    /** @param array<string, mixed> $creditMemo */
    private function validateCreditMemo(array $creditMemo, int $customerId): void
    {
        Validator::make($creditMemo, [
            'id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'in:'.$customerId],
            'type' => ['required', 'in:CustCred'],
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
    public function creditMemos(int $customerId): Generator
    {
        $this->assertPositiveId($customerId);
        $lastId = 0;
        do {
            $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'CustCred' AND id > {$lastId} ORDER BY id");
            foreach ($page['items'] as $creditMemo) {
                $this->validateCreditMemo($creditMemo, $customerId);
                if ((int) $creditMemo['id'] <= $lastId) {
                    throw new RuntimeException('NetSuite credit memo pagination did not advance.');
                }
                $lastId = (int) $creditMemo['id'];
                yield $creditMemo;
            }
        } while ($page['hasMore']);
    }

    /** @param list<int> $creditMemoIds
     * @return array<int, array<string, mixed>>
     */
    public function creditMemosByIds(int $customerId, array $creditMemoIds): array
    {
        $ids = $this->creditMemoIdList($customerId, $creditMemoIds);
        $page = $this->client->query($this->headerSql()." WHERE entity = {$customerId} AND type = 'CustCred' AND id IN ({$ids}) ORDER BY id");
        $creditMemos = [];
        foreach ($page['items'] as $creditMemo) {
            $this->validateCreditMemo($creditMemo, $customerId);
            $id = (int) $creditMemo['id'];
            if (! in_array($id, $creditMemoIds, true) || isset($creditMemos[$id])) {
                throw new RuntimeException('NetSuite returned an unexpected or duplicate credit memo.');
            }
            $creditMemos[$id] = $creditMemo;
        }
        if ($page['hasMore'] || count($creditMemos) !== count($creditMemoIds)) {
            throw new RuntimeException('A credit memo disappeared or moved during import. Retry the sync.');
        }

        return $creditMemos;
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

    /** @return array{creditMemos: list<array<string, mixed>>, lines: list<array<string, mixed>>} */
    public function controlTotals(int $customerId): array
    {
        $this->assertPositiveId($customerId);
        $creditMemos = $this->client->query(<<<SQL
            SELECT currency AS currency_id, COUNT(*) AS credit_memo_count,
                COUNT(foreignamountpaid) AS paid_count, COUNT(foreignamountunpaid) AS unpaid_count,
                TO_CHAR(NVL(SUM(foreignamountpaid), 0)) AS foreign_amount_paid,
                TO_CHAR(NVL(SUM(foreignamountunpaid), 0)) AS foreign_amount_unpaid,
                TO_CHAR(SUM(total)) AS total, TO_CHAR(SUM(foreigntotal)) AS foreign_total
            FROM transaction
            WHERE entity = {$customerId} AND type = 'CustCred'
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
            WHERE transaction.entity = {$customerId} AND transaction.type = 'CustCred'
            GROUP BY transaction.currency
            ORDER BY transaction.currency
            SQL);

        if ($creditMemos['hasMore'] || $lines['hasMore']) {
            throw new RuntimeException('NetSuite control totals were truncated; reconciliation cannot complete.');
        }

        Validator::make($creditMemos, [
            'items.*.currency_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.credit_memo_count' => ['required', 'integer', 'min:1'],
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

        return ['creditMemos' => $creditMemos['items'], 'lines' => $lines['items']];
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }
    }
}

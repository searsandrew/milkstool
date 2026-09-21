<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class InvoiceSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @return array<string, mixed> */
    public function invoice(int $customerId, int $invoiceId): array
    {
        $this->assertPositiveId($customerId);
        $this->assertPositiveId($invoiceId);
        $page = $this->client->query(
            'SELECT id, entity AS customer_id, type, tranid AS number, otherrefnum AS purchase_order_number, '
            ."TO_CHAR(trandate, 'YYYY-MM-DD') AS transaction_date, status, BUILTIN.DF(status) AS status_name, "
            .'currency AS currency_id, total, foreigntotal AS foreign_total, memo, '
            ."TO_CHAR(duedate, 'YYYY-MM-DD') AS due_date, foreignamountpaid AS foreign_amount_paid, foreignamountunpaid AS foreign_amount_unpaid, "
            ."TO_CHAR(SYS_EXTRACT_UTC(lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at "
            ."FROM transaction WHERE entity = {$customerId} AND type = 'CustInvc' AND id = {$invoiceId}"
        );

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new RuntimeException('Invoice was not found for this customer or is not accessible.');
        }

        $invoice = $page['items'][0];
        $this->validateInvoice($invoice, $customerId);
        Validator::make($invoice, ['id' => ['in:'.$invoiceId]])->validate();

        return $invoice;
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesForInvoices(int $customerId, array $invoiceIds): array
    {
        $ids = $this->invoiceIdList($customerId, $invoiceIds);
        $lastInvoiceId = 0;
        $lastLineId = -1;
        $lines = array_fill_keys($invoiceIds, []);

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
                WHERE transaction.entity = {$customerId} AND transaction.type = 'CustInvc'
                    AND transactionline.transaction IN ({$ids})
                    AND (transactionline.transaction > {$lastInvoiceId}
                        OR (transactionline.transaction = {$lastInvoiceId} AND transactionline.id > {$lastLineId}))
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
                $invoiceId = (int) $line['transaction_id'];
                $lineId = (int) $line['line_id'];

                if ($invoiceId < $lastInvoiceId || ($invoiceId === $lastInvoiceId && $lineId <= $lastLineId)) {
                    throw new RuntimeException('NetSuite line pagination did not advance.');
                }

                $lastInvoiceId = $invoiceId;
                $lastLineId = $lineId;
                $lines[$invoiceId][] = $line;
            }
        } while ($page['hasMore']);

        foreach ($lines as $invoiceLines) {
            if ($invoiceLines === []) {
                throw new RuntimeException('NetSuite returned no lines for a invoice; existing data was retained.');
            }
        }

        return $lines;
    }

    /** @param list<int> $invoiceIds */
    private function invoiceIdList(int $customerId, array $invoiceIds): string
    {
        $this->assertPositiveId($customerId);

        if ($invoiceIds === [] || count($invoiceIds) > 50 || count(array_unique($invoiceIds)) !== count($invoiceIds)) {
            throw new InvalidArgumentException('Request between 1 and 50 distinct invoice IDs.');
        }

        foreach ($invoiceIds as $id) {
            if (! is_int($id)) {
                throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
            }

            $this->assertPositiveId($id);
        }

        return implode(',', $invoiceIds);
    }

    /** @param array<string, mixed> $invoice */
    private function validateInvoice(array $invoice, int $customerId): void
    {
        Validator::make($invoice, [
            'id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'in:'.$customerId],
            'type' => ['required', 'in:CustInvc'],
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

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }
    }
}

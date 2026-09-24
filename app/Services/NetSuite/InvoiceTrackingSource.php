<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class InvoiceTrackingSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @param list<int> $invoiceIds
     * @return array<int, list<string>>
     */
    public function fetch(int $customerId, array $invoiceIds): array
    {
        if ($customerId < 1 || $invoiceIds === [] || count($invoiceIds) > 25 || count(array_unique($invoiceIds)) !== count($invoiceIds)) {
            throw new InvalidArgumentException('Provide a customer and 1 to 25 distinct invoice IDs.');
        }
        foreach ($invoiceIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invoice IDs must be positive integers.');
            }
        }
        $ids = implode(',', $invoiceIds);
        $page = $this->client->query(<<<SQL
            SELECT DISTINCT invoice.id AS invoice_id, invoice.entity AS customer_id,
                itemfulfillmentpackage.packagetrackingnumber AS tracking_number
            FROM transaction invoice
            JOIN transactionline invoiceLine ON invoiceLine.transaction = invoice.id
            JOIN transaction salesOrder ON salesOrder.id = invoiceLine.createdfrom
            JOIN transactionline fulfillmentLine ON fulfillmentLine.createdfrom = salesOrder.id
            JOIN transaction fulfillment ON fulfillment.id = fulfillmentLine.transaction
            JOIN itemfulfillmentpackage ON itemfulfillmentpackage.itemfulfillment = fulfillment.id
            WHERE invoice.type = 'CustInvc' AND invoice.entity = {$customerId} AND invoice.id IN ({$ids})
                AND salesOrder.type = 'SalesOrd' AND salesOrder.entity = {$customerId}
                AND fulfillment.type = 'ItemShip' AND fulfillment.entity = {$customerId}
                AND itemfulfillmentpackage.packagetrackingnumber IS NOT NULL
            ORDER BY invoice.id, itemfulfillmentpackage.packagetrackingnumber
            SQL);
        if ($page['hasMore']) {
            throw new RuntimeException('Tracking results exceed one page. Retry with a single invoice; no partial tracking data was saved.');
        }
        $result = array_fill_keys($invoiceIds, []);
        foreach ($page['items'] as $row) {
            Validator::make($row, [
                'invoice_id' => ['required', 'integer', 'in:'.$ids],
                'customer_id' => ['required', 'integer', 'in:'.$customerId],
                'tracking_number' => ['required', 'string', 'max:255'],
            ])->validate();
            $number = trim($row['tracking_number']);
            if ($number !== '') {
                $result[(int) $row['invoice_id']][] = $number;
            }
        }
        foreach ($result as &$numbers) {
            $numbers = array_values(array_unique($numbers));
            sort($numbers, SORT_STRING);
        }

        return $result;
    }
}

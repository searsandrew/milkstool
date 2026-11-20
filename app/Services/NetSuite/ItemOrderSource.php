<?php

namespace App\Services\NetSuite;

use App\Exceptions\ItemSourceUnavailable;
use Illuminate\Support\Facades\Validator;

class ItemOrderSource
{
    public function __construct(private ItemQueryClient $client) {}

    /** @param array{date: string, id: int}|null $before
     * @return array{orders: list<array<string, mixed>>, has_more: bool} */
    public function forItem(int $itemId, string $type, ?array $before = null): array
    {
        if ($itemId < 1 || ! in_array($type, ['sales', 'purchase'], true)) {
            throw new \InvalidArgumentException('A positive item ID and supported order type are required.');
        }
        $boundary = '';
        if ($before !== null) {
            if (Validator::make($before, ['date' => ['required', 'date_format:Y-m-d'], 'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]])->fails()) {
                throw new \InvalidArgumentException('An order date and positive internal ID are required.');
            }
            $date = $before['date'];
            $id = (int) $before['id'];
            $boundary = " AND (transaction.trandate < TO_DATE('{$date}', 'YYYY-MM-DD') OR (transaction.trandate = TO_DATE('{$date}', 'YYYY-MM-DD') AND transaction.id < {$id}))";
        }
        $recordType = $type === 'sales' ? 'SalesOrd' : 'PurchOrd';
        $rows = $this->client->all(<<<SQL
            SELECT TOP 11 transaction.id, transaction.tranid AS number,
                TO_CHAR(transaction.trandate, 'YYYY-MM-DD') AS order_date,
                transaction.entity AS entity_id, BUILTIN.DF(transaction.entity) AS entity_name,
                transaction.otherrefnum AS customer_po_reference,
                BUILTIN.DF(transaction.status) AS status
            FROM transaction
            WHERE transaction.type = '{$recordType}'{$boundary}
                AND EXISTS (SELECT 1 FROM transactionline
                    WHERE transactionline.transaction = transaction.id
                        AND transactionline.item = {$itemId} AND transactionline.mainline = 'F')
            ORDER BY transaction.trandate DESC, transaction.id DESC
            SQL);
        if (count($rows) > 11) {
            throw new ItemSourceUnavailable('NetSuite returned an oversized order history.');
        }
        $orders = [];
        $seen = [];
        $previous = $before === null ? null : [$before['date'], $before['id']];
        foreach ($rows as $row) {
            $validator = Validator::make($row, [
                'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'number' => ['required', 'string'], 'order_date' => ['required', 'date_format:Y-m-d'],
                'entity_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'entity_name' => ['nullable', 'string'], 'customer_po_reference' => ['nullable', 'string'],
                'status' => ['nullable', 'string'],
            ]);
            if ($validator->fails() || isset($seen[$row['id']])) {
                throw new ItemSourceUnavailable('NetSuite returned invalid order history.');
            }
            $sortKey = [$row['order_date'], (int) $row['id']];
            if ($previous !== null && $sortKey >= $previous) {
                throw new ItemSourceUnavailable('NetSuite returned unordered order history.');
            }
            $previous = $sortKey;
            $seen[$row['id']] = true;
            $orders[] = [
                'id' => (int) $row['id'], 'number' => $row['number'], 'order_date' => $row['order_date'],
                'entity' => ['id' => (int) $row['entity_id'], 'name' => $row['entity_name'] ?? null],
                'customer_po_reference' => $type === 'sales' ? ($row['customer_po_reference'] ?? null) : null,
                'status' => $row['status'] ?? null,
            ];
        }

        return ['orders' => array_slice($orders, 0, 10), 'has_more' => count($orders) > 10];
    }
}

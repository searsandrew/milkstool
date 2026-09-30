<?php

namespace App\Services\NetSuite;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Searsandrew\BriarRose\BriarRoseManager;

class InvoiceSummarySource
{
    public function __construct(private BriarRoseManager $briarRose) {}

    /** @return array<string, mixed> */
    public function fetch(int $customerId, int $invoiceId): array
    {
        $record = $this->briarRose->rest()->record('invoice')->get($invoiceId, ['expandSubResources' => 'true'])->throw()->json();
        $fields = ['subtotal' => 'subtotal', 'discountTotal' => 'discount_total', 'taxTotal' => 'tax_total',
            'shippingCost' => 'shipping_cost', 'handlingCost' => 'handling_cost', 'total' => 'total'];
        $rules = ['item.items' => ['present', 'array', 'list'],
            'item.items.*.line' => ['required', 'integer', 'min:0', 'distinct'],
            'item.items.*.item.id' => ['nullable', 'integer'],
            'item.items.*.quantityRemaining' => ['nullable', 'numeric'],
            'item.items.*.quantityOrdered' => ['nullable', 'numeric'],
            'id' => ['required', 'integer', 'in:'.$invoiceId],
            'entity.id' => ['required', 'integer', 'in:'.$customerId],
            'currency.id' => ['required', 'integer'], 'lastModifiedDate' => ['required', 'date']];
        foreach ($fields as $field => $target) {
            $rules[$field] = [$field === 'total' ? 'required' : 'nullable', 'numeric'];
        }
        Validator::make((array) $record, $rules)->validate();
        $summary = ['currency_id' => (int) $record['currency']['id'], 'source_modified_at' => $record['lastModifiedDate']];
        foreach ($fields as $field => $target) {
            $summary[$target] = isset($record[$field])
                ? (string) BigDecimal::of((string) $record[$field])->toScale(8, RoundingMode::Unnecessary) : null;
        }

        if (($record['item']['hasMore'] ?? false) || (isset($record['item']['totalResults']) && (int) $record['item']['totalResults'] !== count($record['item']['items']))) {
            throw new \RuntimeException('NetSuite returned incomplete invoice items.');
        }
        $summary['line_quantities'] = [];
        foreach ($record['item']['items'] as $line) {
            $summary['line_quantities'][] = [
                'line_id' => (int) $line['line'],
                'item_id' => isset($line['item']['id']) ? (int) $line['item']['id'] : null,
                'quantity_remaining' => isset($line['quantityRemaining']) ? (string) BigDecimal::of((string) $line['quantityRemaining'])->toScale(8, RoundingMode::Unnecessary) : null,
                'quantity_ordered' => isset($line['quantityOrdered']) ? (string) BigDecimal::of((string) $line['quantityOrdered'])->toScale(8, RoundingMode::Unnecessary) : null,
            ];
        }

        return $summary;
    }
}

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
        $record = $this->briarRose->rest()->record('invoice')->get($invoiceId)->throw()->json();
        $fields = ['subtotal' => 'subtotal', 'discountTotal' => 'discount_total', 'taxTotal' => 'tax_total',
            'shippingCost' => 'shipping_cost', 'handlingCost' => 'handling_cost', 'total' => 'total'];
        $rules = ['id' => ['required', 'integer', 'in:'.$invoiceId],
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

        return $summary;
    }
}

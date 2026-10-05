<?php

namespace App\Services\NetSuite;

use App\Exceptions\ReceivableSyncInterrupted;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class InvoiceSummarySource
{
    public function __construct(private NetSuiteRestClient $client) {}

    /**
     * @param  array<string, mixed>  $invoice
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function forInvoice(int $customerId, array $invoice, array $lines): array
    {
        $summary = $this->fetch($customerId, (int) $invoice['id']);
        if ($summary !== $this->fetch($customerId, (int) $invoice['id'])) {
            throw new ReceivableSyncInterrupted("Invoice {$invoice['id']} summary changed during retrieval. Retry.");
        }
        $modified = CarbonImmutable::parse($invoice['updated_at'], 'UTC');
        if (! CarbonImmutable::parse($summary['source_modified_at'])->utc()->startOfMinute()->equalTo($modified->startOfMinute())
            || ! BigDecimal::of($summary['total'])->isEqualTo((string) $invoice['foreign_total'])
            || $summary['currency_id'] !== (int) $invoice['currency_id']) {
            throw new ReceivableSyncInterrupted("Invoice {$invoice['id']} summary and header do not describe the same source version.");
        }
        $sourceLines = collect($lines)->keyBy('line_id');
        $quantities = collect($summary['line_quantities'])->keyBy('line_id');
        foreach ($quantities as $lineId => $quantity) {
            $line = $sourceLines->get($lineId);
            if ($line === null || $quantity['item_id'] !== (isset($line['item_id']) ? (int) $line['item_id'] : null)) {
                throw new ReceivableSyncInterrupted("Invoice {$invoice['id']} item quantities do not match source line {$lineId}.");
            }
        }
        foreach ($lines as $line) {
            $parent = $sourceLines->get($line['kit_parent_line_id'] ?? null);
            $coveredByKit = ($line['kit_component'] ?? null) === 'T' && ($parent['item_type'] ?? null) === 'Kit'
                && $quantities->has($parent['line_id']);
            if ($line['mainline'] !== 'T' && $line['taxline'] !== 'T' && $line['discount_line'] !== 'T'
                && ($line['is_cogs'] ?? null) !== 'T'
                && ($line['item_type'] ?? null) !== 'ShipItem' && isset($line['item_id']) && ! $coveredByKit && ! $quantities->has($line['line_id'])) {
                throw new ReceivableSyncInterrupted("Invoice {$invoice['id']} item quantities are missing source line {$line['line_id']} (item {$line['item_id']}).");
            }
        }

        return [...$summary, 'schema_version' => 1, 'header_updated_at' => $modified->format('Y-m-d H:i:s'),
            'source_modified_at' => $modified->toIso8601String(), 'synced_at' => now()->utc()->toIso8601String(),
            'quantity_basis' => 'invoice_record'];
    }

    /** @return array<string, mixed> */
    public function fetch(int $customerId, int $invoiceId): array
    {
        $record = $this->client->record('invoice')->get($invoiceId, ['expandSubResources' => 'true'])->throw()->json();
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

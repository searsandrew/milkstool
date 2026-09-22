<?php

namespace App\Http\Resources;

use App\Models\PaymentApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceSettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $source = $this->transaction;

        return [
            'source_netsuite_id' => (int) $source->netsuite_id,
            'source_type' => $source->type,
            'source_number' => $source->number,
            'source_date' => $source->transaction_date?->format('Y-m-d'),
            'source_currency_id' => (int) $source->currency_id,
            'source_line_id' => $this->resource instanceof PaymentApplication ? $this->payment_line_id : $this->credit_line_id,
            'target_line_id' => $this->target_line_id,
            'target_currency_id' => $this->target_currency_id,
            'foreign_amount' => $this->foreign_amount,
            'source_synced_at' => $source->synced_at?->utc()->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'netsuite_line_id' => (int) $this->netsuite_line_id,
            'source_transaction_id' => $this->source_transaction_id,
            'item_id' => $this->item_id,
            'item_number' => $this->item_number,
            'memo' => $this->memo,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'is_mainline' => $this->is_mainline,
            'is_tax_line' => $this->is_tax_line,
            'is_discount_line' => $this->is_discount_line,
        ];
    }
}

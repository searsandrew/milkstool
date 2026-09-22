<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment_line_id' => $this->payment_line_id,
            'target_netsuite_id' => $this->target_netsuite_id,
            'target_line_id' => $this->target_line_id,
            'target_type' => $this->target_type,
            'target_currency_id' => $this->target_currency_id,
            'foreign_amount' => $this->foreign_amount,
        ];
    }
}

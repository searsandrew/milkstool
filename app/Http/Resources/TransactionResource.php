<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'netsuite_id' => (int) $this->netsuite_id,
            'type' => $this->type,
            'number' => $this->number,
            'purchase_order_number' => $this->purchase_order_number,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'status_name' => $this->status_name,
            'currency_id' => (int) $this->currency_id,
            'foreign_total' => $this->foreign_total,
            'foreign_amount_paid' => $this->foreign_amount_paid,
            'foreign_amount_unpaid' => $this->foreign_amount_unpaid,
            'synced_at' => $this->synced_at?->utc()->toIso8601String(),
            'credit_memo_applications' => $this->when($this->type === 'CustCred', fn () => CreditMemoApplicationResource::collection($this->whenLoaded('creditMemoApplications'))),
            'credit_memo_applications_scope' => $this->when($this->type === 'CustCred', 'same_customer'),
            'payment_applications' => PaymentApplicationResource::collection($this->whenLoaded('paymentApplications')),
            'payment_applications_scope' => $this->when($this->type === 'CustPymt', 'same_customer'),
            'lines' => TransactionLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}

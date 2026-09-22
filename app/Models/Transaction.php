<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['netsuite_id', 'company_id', 'type', 'number', 'purchase_order_number', 'transaction_date', 'status', 'status_name', 'currency_id', 'total', 'foreign_total', 'memo', 'netsuite_updated_at', 'synced_at', 'raw_payload', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid', 'invoice_details', 'invoice_details_synced_at'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'foreign_amount_paid' => 'decimal:8',
            'foreign_amount_unpaid' => 'decimal:8',
            'netsuite_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'total' => 'decimal:8',
            'foreign_total' => 'decimal:8',
            'raw_payload' => 'array',
            'invoice_details' => 'array',
            'invoice_details_synced_at' => 'immutable_datetime',
        ];
    }

    /** @param array<string, mixed> $invoice */
    public function fillInvoiceDetails(array $invoice): void
    {
        $details = [];
        foreach (['billing_address', 'shipping_address', 'terms_name', 'ship_date', 'shipping_method'] as $field) {
            $details[$field] = $invoice[$field] ?? null;
        }
        $details['terms_id'] = isset($invoice['terms_id']) ? (int) $invoice['terms_id'] : null;
        $this->fill(['invoice_details' => $details, 'invoice_details_synced_at' => now()]);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<PaymentApplication, $this> */
    public function appliedPayments(): HasMany
    {
        return $this->hasMany(PaymentApplication::class, 'target_netsuite_id', 'netsuite_id');
    }

    /** @return HasMany<CreditMemoApplication, $this> */
    public function appliedCredits(): HasMany
    {
        return $this->hasMany(CreditMemoApplication::class, 'target_netsuite_id', 'netsuite_id');
    }

    /** @return HasMany<CreditMemoApplication, $this> */
    public function creditMemoApplications(): HasMany
    {
        return $this->hasMany(CreditMemoApplication::class);
    }

    /** @return HasMany<PaymentApplication, $this> */
    public function paymentApplications(): HasMany
    {
        return $this->hasMany(PaymentApplication::class);
    }

    /** @return HasMany<TransactionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'company_id', 'type', 'number', 'purchase_order_number', 'transaction_date', 'status', 'status_name', 'currency_id', 'total', 'foreign_total', 'memo', 'netsuite_updated_at', 'synced_at', 'raw_payload', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid', 'invoice_details', 'invoice_details_synced_at', 'credit_applied', 'credit_remaining'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'credit_applied' => 'decimal:8',
            'credit_remaining' => 'decimal:8',
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

    /** @param Builder<Transaction> $query */
    public function scopeNeedsInvoiceEnrichment(Builder $query): void
    {
        $query->where('type', 'CustInvc')->where(function (Builder $query): void {
            $query->whereNull('invoice_details->summary->schema_version')
                ->orWhere('invoice_details->summary->schema_version', '!=', 1)
                ->orWhereNull('invoice_details->summary->header_updated_at')
                ->orWhereNull('netsuite_updated_at')
                ->orWhereColumn('invoice_details->summary->header_updated_at', '!=', 'netsuite_updated_at');
        });
    }

    /** @param Builder<Transaction> $query */
    public function scopeNeedsInvoiceTracking(Builder $query): void
    {
        $query->where('type', 'CustInvc')->where(function (Builder $query): void {
            $query->whereNull('invoice_details->tracking_synced_at')
                ->orWhere('invoice_details->tracking_dirty', true)
                ->orWhereNull('netsuite_updated_at')
                ->orWhereNull('invoice_details->tracking_header_updated_at')
                ->orWhereColumn('invoice_details->tracking_header_updated_at', '!=', 'netsuite_updated_at');
        });
    }

    /** @param Builder<Transaction> $query */
    public function scopeNeedsInvoiceWork(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->needsInvoiceEnrichment()
            ->orWhere(fn (Builder $query) => $query->needsInvoiceTracking()));
    }

    public function hasCurrentInvoiceEnrichment(): bool
    {
        return $this->type === 'CustInvc' && ($this->invoice_details['summary']['schema_version'] ?? null) === 1
            && $this->netsuite_updated_at !== null
            && ($this->invoice_details['summary']['header_updated_at'] ?? null) === $this->netsuite_updated_at->utc()->format('Y-m-d H:i:s');
    }

    public function hasCurrentInvoiceTracking(): bool
    {
        $syncedAt = $this->invoice_details['tracking_synced_at'] ?? null;

        return $this->type === 'CustInvc' && is_string($syncedAt)
            && ! ($this->invoice_details['tracking_dirty'] ?? false)
            && $this->netsuite_updated_at !== null
            && ($this->invoice_details['tracking_header_updated_at'] ?? null) === $this->netsuite_updated_at->utc()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $invoice */
    public function fillInvoiceDetails(array $invoice): void
    {
        $details = array_intersect_key($this->invoice_details ?? [], array_flip(['tracking_dirty', 'tracking_numbers', 'tracking_scope', 'tracking_synced_at', 'tracking_header_updated_at', 'enrichment_error', 'summary']));
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
        return $this->hasMany(PaymentApplication::class, 'target_netsuite_id', 'id');
    }

    /** @return HasMany<CreditMemoApplication, $this> */
    public function appliedCredits(): HasMany
    {
        return $this->hasMany(CreditMemoApplication::class, 'target_netsuite_id', 'id');
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

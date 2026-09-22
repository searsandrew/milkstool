<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['netsuite_id', 'account_number', 'name', 'sales_rep_id', 'is_active', 'netsuite_updated_at', 'raw_payload'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'portal_last_active_at' => 'immutable_datetime',
            'netsuite_updated_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'account_balance_snapshot' => 'array',
            'balance_sync_started_at' => 'immutable_datetime',
            'balance_synced_at' => 'immutable_datetime',
            'balance_next_sync_at' => 'immutable_datetime',
            'sales_orders_sync_started_at' => 'immutable_datetime',
            'sales_orders_synced_at' => 'immutable_datetime',
            'invoices_sync_started_at' => 'immutable_datetime',
            'invoices_synced_at' => 'immutable_datetime',
            'payments_synced_at' => 'immutable_datetime',
            'payments_sync_started_at' => 'immutable_datetime',
            'payments_next_sync_at' => 'immutable_datetime',
            'payments_backfilled_at' => 'immutable_datetime',
            'credit_memos_synced_at' => 'immutable_datetime',
            'credit_memos_sync_started_at' => 'immutable_datetime',
            'credit_memos_next_sync_at' => 'immutable_datetime',
            'credit_memos_backfilled_at' => 'immutable_datetime',
            'invoices_next_sync_at' => 'immutable_datetime',
            'invoices_backfilled_at' => 'immutable_datetime',
            'sales_orders_checkpoint_at' => 'immutable_datetime',
            'sales_orders_backfilled_at' => 'immutable_datetime',
            'sales_orders_full_synced_at' => 'immutable_datetime',
            'sales_orders_next_sync_at' => 'immutable_datetime',
        ];
    }

    public function nextRefreshAt(): CarbonImmutable
    {
        return $this->portal_last_active_at?->gte(now()->subDay())
            ? CarbonImmutable::now()->addMinutes(15)
            : CarbonImmutable::now()->addHours(6);
    }

    public function refreshDueAt(string $prefix): ?CarbonImmutable
    {
        $this->assertSyncPrefix($prefix);
        $success = $this->{$prefix.'_synced_at'};
        $due = $this->{$prefix.'_next_sync_at'} ?? $success?->addHours(6);
        if ($this->portal_last_active_at?->gte(now()->subDay()) && $this->{$prefix.'_sync_error'} === null) {
            $activeDue = $success?->addMinutes(15);
            if ($activeDue === null || $due === null) {
                return null;
            }

            return $activeDue->lt($due) ? $activeDue : $due;
        }

        return $due;
    }

    /** @param Builder<Company> $query */
    public function scopeDueForRefresh(Builder $query, string $prefix): void
    {
        $this->assertSyncPrefix($prefix);
        $query->where(function (Builder $query) use ($prefix): void {
            $query->whereNull($prefix.'_next_sync_at')->orWhere($prefix.'_next_sync_at', '<=', now())
                ->orWhere(function (Builder $query) use ($prefix): void {
                    $query->where('portal_last_active_at', '>=', now()->subDay())
                        ->whereNull($prefix.'_sync_error')
                        ->where(fn (Builder $query) => $query->whereNull($prefix.'_synced_at')
                            ->orWhere($prefix.'_synced_at', '<=', now()->subMinutes(15)));
                });
        });
    }

    private function assertSyncPrefix(string $prefix): void
    {
        if (! in_array($prefix, ['sales_orders', 'invoices', 'credit_memos', 'balance', 'payments'], true)) {
            throw new InvalidArgumentException('Unknown sync category.');
        }
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

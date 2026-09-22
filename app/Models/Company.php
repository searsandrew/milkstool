<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'netsuite_updated_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'sales_orders_sync_started_at' => 'immutable_datetime',
            'sales_orders_synced_at' => 'immutable_datetime',
            'invoices_sync_started_at' => 'immutable_datetime',
            'invoices_synced_at' => 'immutable_datetime',
            'sales_orders_checkpoint_at' => 'immutable_datetime',
            'sales_orders_backfilled_at' => 'immutable_datetime',
            'sales_orders_full_synced_at' => 'immutable_datetime',
            'sales_orders_next_sync_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

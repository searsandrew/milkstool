<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['netsuite_id', 'company_id', 'type', 'number', 'purchase_order_number', 'transaction_date', 'status', 'status_name', 'currency_id', 'total', 'foreign_total', 'memo', 'netsuite_updated_at', 'synced_at', 'raw_payload', 'due_date', 'foreign_amount_paid', 'foreign_amount_unpaid'])]
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
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<TransactionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class);
    }
}

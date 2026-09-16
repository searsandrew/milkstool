<?php

namespace App\Models;

use Database\Factories\TransactionLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'netsuite_line_id', 'item_id', 'item_number', 'memo', 'quantity', 'rate', 'amount', 'is_mainline', 'is_tax_line', 'is_discount_line', 'line_type', 'raw_payload'])]
class TransactionLine extends Model
{
    /** @use HasFactory<TransactionLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8', 'rate' => 'decimal:8', 'amount' => 'decimal:8',
            'is_mainline' => 'boolean', 'is_tax_line' => 'boolean', 'is_discount_line' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

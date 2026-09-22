<?php

namespace App\Models;

use Database\Factories\PaymentApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transaction_id', 'payment_line_id', 'target_netsuite_id', 'target_line_id', 'target_customer_id', 'target_currency_id', 'target_type', 'foreign_amount', 'raw_payload'])]
class PaymentApplication extends Model
{
    /** @use HasFactory<PaymentApplicationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['foreign_amount' => 'decimal:8', 'raw_payload' => 'array',
            'payment_line_id' => 'integer', 'target_netsuite_id' => 'integer', 'target_line_id' => 'integer',
            'target_customer_id' => 'integer', 'target_currency_id' => 'integer'];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}

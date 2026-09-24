<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('number');
            $table->string('purchase_order_number')->nullable();
            $table->date('transaction_date');
            $table->string('status');
            $table->string('status_name')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->decimal('total', 24, 8);
            $table->decimal('foreign_total', 24, 8);
            $table->text('memo')->nullable();
            $table->timestamp('netsuite_updated_at');
            $table->timestamp('synced_at');
            $table->json('raw_payload');
            $table->timestamps();
            $table->index(['company_id', 'type', 'transaction_date', 'id'], 'transactions_customer_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

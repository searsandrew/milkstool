<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('payments_sync_started_at')->nullable();
            $table->timestamp('payments_synced_at')->nullable();
            $table->timestamp('payments_next_sync_at')->nullable()->index();
            $table->timestamp('payments_backfilled_at')->nullable();
            $table->text('payments_sync_error')->nullable();
        });
        Schema::create('payment_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payment_line_id');
            $table->unsignedBigInteger('target_netsuite_id');
            $table->unsignedBigInteger('target_line_id');
            $table->unsignedBigInteger('target_customer_id')->nullable();
            $table->unsignedBigInteger('target_currency_id')->nullable();
            $table->string('target_type', 30)->nullable();
            $table->decimal('foreign_amount', 24, 8)->nullable();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['transaction_id', 'payment_line_id', 'target_netsuite_id', 'target_line_id'], 'payment_application_source_unique');
            $table->index(['target_customer_id', 'target_netsuite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_applications');
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['payments_next_sync_at']);
            $table->dropColumn(['payments_sync_started_at', 'payments_synced_at', 'payments_next_sync_at', 'payments_backfilled_at', 'payments_sync_error']);
        });
    }
};

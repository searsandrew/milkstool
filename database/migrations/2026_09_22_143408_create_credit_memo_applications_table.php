<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_memo_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('credit_line_id');
            $table->unsignedBigInteger('target_netsuite_id');
            $table->unsignedBigInteger('target_line_id');
            $table->unsignedBigInteger('target_customer_id')->nullable();
            $table->unsignedBigInteger('target_currency_id')->nullable();
            $table->string('target_type', 30)->nullable();
            $table->decimal('foreign_amount', 24, 8)->nullable();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['transaction_id', 'credit_line_id', 'target_netsuite_id', 'target_line_id'], 'credit_application_source_unique');
            $table->index(['target_customer_id', 'target_netsuite_id'], 'credit_applications_target_index');
        });
        DB::table('companies')->whereNotNull('credit_memos_synced_at')->update(['credit_memos_backfilled_at' => null, 'credit_memos_next_sync_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_memo_applications');

    }
};

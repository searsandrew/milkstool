<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('external_id')->nullable()->index();
        });
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_transaction_line_id')->nullable();
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('return_authorizations_sync_started_at')->nullable();
            $table->timestamp('return_authorizations_synced_at')->nullable();
            $table->timestamp('return_authorizations_backfilled_at')->nullable();
            $table->timestamp('return_authorizations_next_sync_at')->nullable()->index();
            $table->text('return_authorizations_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['return_authorizations_next_sync_at']);
            $table->dropColumn(['return_authorizations_sync_started_at', 'return_authorizations_synced_at',
                'return_authorizations_backfilled_at', 'return_authorizations_next_sync_at', 'return_authorizations_sync_error']);
        });
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropColumn('source_transaction_line_id');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};

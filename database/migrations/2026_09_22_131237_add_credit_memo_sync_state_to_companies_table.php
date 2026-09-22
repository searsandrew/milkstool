<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('credit_memos_sync_started_at')->nullable();
            $table->timestamp('credit_memos_synced_at')->nullable();
            $table->text('credit_memos_sync_error')->nullable();
            $table->timestamp('credit_memos_next_sync_at')->nullable()->index();
            $table->timestamp('credit_memos_backfilled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['credit_memos_next_sync_at']);
            $table->dropColumn(['credit_memos_sync_started_at', 'credit_memos_synced_at', 'credit_memos_sync_error', 'credit_memos_next_sync_at', 'credit_memos_backfilled_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('account_balance_snapshot')->nullable();
            $table->timestamp('balance_sync_started_at')->nullable();
            $table->timestamp('balance_synced_at')->nullable();
            $table->text('balance_sync_error')->nullable();
            $table->timestamp('balance_next_sync_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['balance_next_sync_at']);
            $table->dropColumn(['account_balance_snapshot', 'balance_sync_started_at', 'balance_synced_at', 'balance_sync_error', 'balance_next_sync_at']);
        });
    }
};

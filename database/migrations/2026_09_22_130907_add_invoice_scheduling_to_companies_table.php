<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('invoices_next_sync_at')->nullable()->index();
            $table->timestamp('invoices_backfilled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['invoices_next_sync_at']);
            $table->dropColumn(['invoices_next_sync_at', 'invoices_backfilled_at']);
        });
    }
};

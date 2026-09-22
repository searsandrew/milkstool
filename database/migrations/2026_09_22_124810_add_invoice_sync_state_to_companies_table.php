<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('invoices_sync_started_at')->nullable();
            $table->timestamp('invoices_synced_at')->nullable();
            $table->text('invoices_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['invoices_sync_started_at', 'invoices_synced_at', 'invoices_sync_error']);
        });
    }
};

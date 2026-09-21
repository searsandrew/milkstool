<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->timestamp('sales_orders_checkpoint_at')->nullable();
            $table->timestamp('sales_orders_full_synced_at')->nullable();
            $table->timestamp('sales_orders_next_sync_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['sales_orders_next_sync_at']);
            $table->dropColumn(['sales_orders_checkpoint_at', 'sales_orders_full_synced_at', 'sales_orders_next_sync_at']);
        });
    }
};

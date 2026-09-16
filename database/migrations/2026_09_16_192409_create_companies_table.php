<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('netsuite_id')->unique();
            $table->string('account_number')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('sales_rep_id')->nullable()->index();
            $table->boolean('is_active');
            $table->timestamp('netsuite_updated_at');
            $table->json('raw_payload');
            $table->timestamp('sales_orders_sync_started_at')->nullable();
            $table->timestamp('sales_orders_synced_at')->nullable();
            $table->text('sales_orders_sync_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

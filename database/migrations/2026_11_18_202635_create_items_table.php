<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('item_number');
            $table->text('description')->nullable();
            $table->string('upc')->nullable()->index();
            $table->timestamp('netsuite_updated_at');
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

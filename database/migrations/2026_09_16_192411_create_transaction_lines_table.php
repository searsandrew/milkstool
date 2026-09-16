<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('netsuite_line_id');
            $table->bigInteger('item_id')->nullable()->index();
            $table->string('item_number')->nullable();
            $table->text('memo')->nullable();
            $table->decimal('quantity', 24, 8)->nullable();
            $table->decimal('rate', 24, 8)->nullable();
            $table->decimal('amount', 24, 8)->nullable();
            $table->boolean('is_mainline');
            $table->boolean('is_tax_line');
            $table->boolean('is_discount_line');
            $table->string('line_type')->nullable();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['transaction_id', 'netsuite_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_lines');
    }
};

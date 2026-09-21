<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->date('due_date')->nullable();
            $table->decimal('foreign_amount_paid', 24, 8)->nullable();
            $table->decimal('foreign_amount_unpaid', 24, 8)->nullable();
        });
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_transaction_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropIndex(['source_transaction_id']);
            $table->dropColumn('source_transaction_id');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['due_date', 'foreign_amount_paid', 'foreign_amount_unpaid']);
        });
    }
};

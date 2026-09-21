<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->bigInteger('sales_rep_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('companies')->where('sales_rep_id', '<', 0)->exists()) {
            throw new RuntimeException('Cannot restore unsigned sales-rep IDs while negative NetSuite references are stored.');
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('sales_rep_id')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->timestamp('source_missing_at')->nullable();
            $table->timestamp('source_review_required_at')->nullable()->index();
            $table->string('source_review_reason', 80)->nullable();
            $table->json('source_review_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['source_review_required_at']);
            $table->dropColumn(['source_missing_at', 'source_review_required_at', 'source_review_reason', 'source_review_context']);
        });
    }
};

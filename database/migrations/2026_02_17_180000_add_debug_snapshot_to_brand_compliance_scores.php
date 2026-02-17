<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add debug_snapshot JSON column to brand_compliance_scores.
     * Stores structured snapshot when scoreAsset() runs for debugging.
     */
    public function up(): void
    {
        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->json('debug_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->dropColumn('debug_snapshot');
        });
    }
};

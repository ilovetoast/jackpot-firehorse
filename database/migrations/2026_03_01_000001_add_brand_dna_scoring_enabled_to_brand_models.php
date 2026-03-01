<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brand Guidelines Builder v1: Additive only.
     * Scoring toggle — independent of is_enabled (publish/unpublish).
     * Default true: existing behavior when Brand DNA is enabled is to score; we preserve that.
     */
    public function up(): void
    {
        Schema::table('brand_models', function (Blueprint $table) {
            $table->boolean('brand_dna_scoring_enabled')->default(true)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('brand_models', function (Blueprint $table) {
            $table->dropColumn('brand_dna_scoring_enabled');
        });
    }
};

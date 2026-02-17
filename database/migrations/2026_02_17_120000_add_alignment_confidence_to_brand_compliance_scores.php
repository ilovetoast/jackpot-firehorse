<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive: alignment_confidence for Brand Compliance scoring.
     * Indicates confidence level (high/medium/low) based on reference count, embedding, and metadata.
     */
    public function up(): void
    {
        if (Schema::hasColumn('brand_compliance_scores', 'alignment_confidence')) {
            return;
        }

        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->string('alignment_confidence', 20)->default('low')->after('evaluation_status');
            $table->index('alignment_confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('brand_compliance_scores', 'alignment_confidence')) {
            return;
        }
        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->dropIndex(['alignment_confidence']);
            $table->dropColumn('alignment_confidence');
        });
    }
};

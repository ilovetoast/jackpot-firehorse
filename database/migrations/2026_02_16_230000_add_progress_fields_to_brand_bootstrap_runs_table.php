<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7: Add progress tracking fields for multi-stage Brand Bootstrap pipeline.
     */
    public function up(): void
    {
        Schema::table('brand_bootstrap_runs', function (Blueprint $table) {
            $table->string('stage')->nullable()->after('status');
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('stage');
            $table->json('stage_log')->nullable()->after('progress_percent');
            $table->unsignedTinyInteger('current_stage_index')->default(0)->after('stage_log');
        });
    }

    public function down(): void
    {
        Schema::table('brand_bootstrap_runs', function (Blueprint $table) {
            $table->dropColumn(['stage', 'progress_percent', 'stage_log', 'current_stage_index']);
        });
    }
};

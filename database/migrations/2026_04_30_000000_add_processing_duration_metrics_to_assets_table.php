<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops-only: time from pipeline / thumbnail start to completion. Nullable, batch-clearable to save space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('processing_duration_ms')->nullable();
            $table->unsignedInteger('thumbnail_ready_duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['processing_duration_ms', 'thumbnail_ready_duration_ms']);
        });
    }
};

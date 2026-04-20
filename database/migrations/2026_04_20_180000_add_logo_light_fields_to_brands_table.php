<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces an explicit "for light backgrounds" logo slot.
 *
 * Historically the primary logo (logo_id / logo_path) was assumed to work on light
 * backgrounds, and logo_dark_id was the only opt-in variant. That assumption breaks
 * when the primary logo is itself colored or low-contrast on white — callers had
 * no way to opt into a light-bg-optimized variant while keeping the primary intact
 * as the Studio/generative source of truth.
 *
 * This migration adds logo_light_path + logo_light_id. Display call sites use the
 * light variant on light surfaces, falling back to the primary when null. The
 * primary logo remains the required source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_light_path')->nullable()->after('logo_dark_id');
            $table->uuid('logo_light_id')->nullable()->after('logo_light_path');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['logo_light_path', 'logo_light_id']);
        });
    }
};

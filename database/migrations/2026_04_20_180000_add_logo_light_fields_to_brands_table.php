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
        // Idempotent: staging ran an earlier attempt that added logo_light_path
        // before failing/redeploying, so the re-run tripped "Duplicate column".
        // Guard each add so the migration can be replayed safely on any env.
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'logo_light_path')) {
                $table->string('logo_light_path')->nullable()->after('logo_dark_id');
            }
            if (! Schema::hasColumn('brands', 'logo_light_id')) {
                $table->uuid('logo_light_id')->nullable()->after('logo_light_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $existing = array_values(array_filter(
                ['logo_light_id', 'logo_light_path'],
                fn (string $col) => Schema::hasColumn('brands', $col)
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};

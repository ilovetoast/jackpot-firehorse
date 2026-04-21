<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache extracted visual signals on each brand_ad_references row.
 *
 * Shape (see BrandAdReferenceSignalExtractor for the authoritative writer):
 * {
 *   version: 1,
 *   avg_brightness: float 0..1,
 *   avg_saturation: float 0..1,
 *   palette_kind: 'monochrome' | 'duochrome' | 'polychrome',
 *   dominant_hue_bucket: 'warm' | 'cool' | 'neutral',
 *   top_colors: [ { hex, weight }, ... ],   // up to 5
 *   extracted_at: ISO8601 timestamp
 * }
 *
 * Stored on the reference row (not computed on-the-fly) because:
 *   - Image sampling costs real wall-time (Imagick loads the full image).
 *   - Brand Guidelines pages + Studio both need the aggregated hints, and we
 *     don't want to recompute per-page-load.
 *   - Re-extraction is idempotent and safe to run in a backfill command;
 *     having the schema in place from day one keeps callers simple.
 *
 * `extraction_attempted_at` is separate from "successful" so we don't retry
 * bad assets (corrupt uploads, unsupported formats) in a tight loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_ad_references')) {
            return;
        }
        if (Schema::hasColumn('brand_ad_references', 'signals')) {
            return;
        }

        Schema::table('brand_ad_references', function (Blueprint $table) {
            $table->json('signals')->nullable()->after('notes');
            $table->timestamp('signals_extracted_at')->nullable()->after('signals');
            $table->timestamp('signals_extraction_attempted_at')->nullable()->after('signals_extracted_at');
            $table->string('signals_extraction_error', 500)->nullable()->after('signals_extraction_attempted_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_ad_references')) {
            return;
        }
        Schema::table('brand_ad_references', function (Blueprint $table) {
            if (Schema::hasColumn('brand_ad_references', 'signals_extraction_error')) {
                $table->dropColumn('signals_extraction_error');
            }
            if (Schema::hasColumn('brand_ad_references', 'signals_extraction_attempted_at')) {
                $table->dropColumn('signals_extraction_attempted_at');
            }
            if (Schema::hasColumn('brand_ad_references', 'signals_extracted_at')) {
                $table->dropColumn('signals_extracted_at');
            }
            if (Schema::hasColumn('brand_ad_references', 'signals')) {
                $table->dropColumn('signals');
            }
        });
    }
};

<?php

use App\Models\BrandVisualReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Semantic reference role (identity vs style) and tiered weights for EBI similarity.
 * Table name in codebase: brand_visual_references (projection for embeddings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_visual_references')) {
            return;
        }

        Schema::table('brand_visual_references', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_visual_references', 'reference_type')) {
                $table->string('reference_type', 32)->nullable()->after('type')->index();
            }
            if (! Schema::hasColumn('brand_visual_references', 'reference_tier')) {
                $table->string('reference_tier', 32)->nullable()->after('reference_type')->index();
            }
            if (! Schema::hasColumn('brand_visual_references', 'weight')) {
                $table->float('weight', 8, 4)->nullable()->after('reference_tier');
            }
        });

        // Backfill: logo → identity (excluded from style similarity); imagery → style; tier guideline @ 1.0
        if (Schema::hasColumn('brand_visual_references', 'reference_type')) {
            DB::table('brand_visual_references')->where('type', BrandVisualReference::TYPE_LOGO)->update([
                'reference_type' => 'identity',
                'reference_tier' => 'guideline',
                'weight' => 1.0,
            ]);
            DB::table('brand_visual_references')->where('type', '!=', BrandVisualReference::TYPE_LOGO)->update([
                'reference_type' => 'style',
                'reference_tier' => 'guideline',
                'weight' => 1.0,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_visual_references')) {
            return;
        }

        Schema::table('brand_visual_references', function (Blueprint $table) {
            if (Schema::hasColumn('brand_visual_references', 'weight')) {
                $table->dropColumn('weight');
            }
            if (Schema::hasColumn('brand_visual_references', 'reference_tier')) {
                $table->dropColumn('reference_tier');
            }
            if (Schema::hasColumn('brand_visual_references', 'reference_type')) {
                $table->dropColumn('reference_type');
            }
        });
    }
};

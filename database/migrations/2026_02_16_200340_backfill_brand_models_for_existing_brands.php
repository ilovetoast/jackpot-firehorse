<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill brand_models for existing brands (one per brand).
     */
    public function up(): void
    {
        $brandIdsWithoutModel = DB::table('brands')
            ->leftJoin('brand_models', 'brands.id', '=', 'brand_models.brand_id')
            ->whereNull('brand_models.id')
            ->pluck('brands.id');

        foreach ($brandIdsWithoutModel as $brandId) {
            DB::table('brand_models')->insert([
                'brand_id' => $brandId,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: we cannot safely remove backfilled rows without knowing which were pre-existing
    }
};

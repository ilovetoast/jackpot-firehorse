<?php

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandVisualReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrates brand_visual_references into brand_model_version_assets.
     * For each brand with BVRs: get or create active version, add pivot rows, then
     * BrandVisualReferenceSyncService will repopulate BVR from pivot (run after).
     */
    public function up(): void
    {
        $bvrRows = BrandVisualReference::with('brand.brandModel')->get();
        if ($bvrRows->isEmpty()) {
            return;
        }

        foreach ($bvrRows->groupBy('brand_id') as $brandId => $refs) {
            $brand = Brand::find($brandId);
            if (! $brand) {
                continue;
            }

            $brandModel = $brand->brandModel;
            if (! $brandModel) {
                $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
            }

            $version = $brandModel->activeVersion;
            if (! $version) {
                $versionNumber = $brandModel->versions()->max('version_number') + 1;
                $version = $brandModel->versions()->create([
                    'version_number' => $versionNumber,
                    'source_type' => 'manual',
                    'model_payload' => [],
                    'metrics_payload' => null,
                    'status' => 'active',
                    'created_by' => null,
                ]);
                $brandModel->versions()->where('status', 'active')->where('id', '!=', $version->id)->update(['status' => 'archived']);
                $brandModel->update(['active_version_id' => $version->id]);
            }

            foreach ($refs as $ref) {
                if (! $ref->asset_id) {
                    continue;
                }
                BrandModelVersionAsset::updateOrCreate(
                    [
                        'brand_model_version_id' => $version->id,
                        'asset_id' => $ref->asset_id,
                        'builder_context' => 'visual_reference',
                    ],
                    ['reference_type' => $ref->type]
                );
            }
        }

        // Repopulate brand_visual_references from pivot (sync)
        $syncService = app(\App\Services\BrandDNA\BrandVisualReferenceSyncService::class);
        foreach ($bvrRows->pluck('brand_id')->unique() as $brandId) {
            $brand = Brand::find($brandId);
            if ($brand) {
                $syncService->syncFromActiveVersion($brand);
            }
        }
    }

    /**
     * Reverse: cannot fully restore BVR from pivot (we'd lose data if BVR had more).
     * No-op for safety.
     */
    public function down(): void
    {
        // No-op: migration adds data; down would require backup
    }
};

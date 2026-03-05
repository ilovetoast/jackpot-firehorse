<?php

use App\Enums\AssetType;
use App\Models\SystemCategory;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Add reference_material system category for Brand Builder reference materials.
     */
    public function up(): void
    {
        SystemCategory::firstOrCreate(
            [
                'slug' => 'reference_material',
                'asset_type' => AssetType::REFERENCE,
                'version' => 1,
            ],
            [
                'name' => 'Reference Material',
                'asset_type' => AssetType::REFERENCE,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 0,
                'version' => 1,
            ]
        );
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        SystemCategory::where('slug', 'reference_material')
            ->where('asset_type', AssetType::REFERENCE)
            ->delete();
    }
};

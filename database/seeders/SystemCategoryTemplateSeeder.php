<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\SystemCategory;
use Illuminate\Database\Seeder;

/**
 * System Category Template Seeder
 *
 * Seeds only system category templates (no brand categories).
 * New brand creation clones from these templates via SystemCategoryService::syncToBrand.
 *
 * Each template has explicit version = 1.
 * Default enabled fields (collection, tags) are configured in metadata_category_defaults.
 *
 * ASSET: Logos, Photography, Graphics, Video
 * DELIVERABLE: Print, Digital, OOH, Events, Videos, Sales Collateral, PR, Packaging, Product Renders, Radio
 */
class SystemCategoryTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            // ASSET asset type system categories
            [
                'name' => 'Logos',
                'slug' => 'logos',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 0,
                'version' => 1,
            ],
            [
                'name' => 'Photography',
                'slug' => 'photography',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
                'version' => 1,
            ],
            [
                'name' => 'Graphics',
                'slug' => 'graphics',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
                'version' => 1,
            ],
            [
                'name' => 'Video',
                'slug' => 'video',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 3,
                'version' => 1,
            ],
            // Execution/Deliverable asset type system categories (exactly 10)
            [
                'name' => 'Print',
                'slug' => 'print',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 0,
                'version' => 1,
            ],
            [
                'name' => 'Digital',
                'slug' => 'digital-ads',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
                'version' => 1,
            ],
            [
                'name' => 'OOH',
                'slug' => 'ooh',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
                'version' => 1,
            ],
            [
                'name' => 'Events',
                'slug' => 'events',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 3,
                'version' => 1,
            ],
            [
                'name' => 'Videos',
                'slug' => 'videos',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 4,
                'version' => 1,
            ],
            [
                'name' => 'Sales Collateral',
                'slug' => 'sales-collateral',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 5,
                'version' => 1,
            ],
            [
                'name' => 'PR',
                'slug' => 'pr',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 6,
                'version' => 1,
            ],
            [
                'name' => 'Packaging',
                'slug' => 'packaging',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 7,
                'version' => 1,
            ],
            [
                'name' => 'Product Renders',
                'slug' => 'product-renders',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 8,
                'version' => 1,
            ],
            [
                'name' => 'Radio',
                'slug' => 'radio',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 9,
                'version' => 1,
            ],
        ];

        foreach ($templates as $template) {
            SystemCategory::firstOrCreate(
                [
                    'slug' => $template['slug'],
                    'asset_type' => $template['asset_type'],
                    'version' => 1,
                ],
                $template
            );
        }

        // Rename "Digital Ads" to "Digital" (slug stays digital-ads for config compatibility)
        SystemCategory::where('asset_type', AssetType::DELIVERABLE)
            ->where('slug', 'digital-ads')
            ->update(['name' => 'Digital']);
    }
}

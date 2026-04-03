<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\SystemCategory;
use Illuminate\Database\Seeder;

/**
 * System Category Template Seeder
 *
 * Seeds only system category templates (no brand categories).
 * New brands receive copies of templates where auto_provision=true via SystemCategoryService::syncToBrand.
 * Admin-created templates default to auto_provision=false (catalog-only) unless toggled in admin.
 *
 * Each seeded template sets auto_provision=true so core folders (Logos, Photography, …) stay on new brands.
 * Each template has explicit version = 1 (template rows are updated in place; no tenant version upgrade flow).
 * Default enabled fields (collection, tags) are configured in metadata_category_defaults.
 *
 * ASSET: Logos, Photography, Graphics, Video, Fonts (hidden)
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
            [
                'name' => 'Fonts',
                'slug' => 'fonts',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 4,
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
            // REFERENCE asset type: Brand Builder reference materials (hidden from normal libraries)
            [
                'name' => 'Reference Material',
                'slug' => 'reference_material',
                'asset_type' => AssetType::REFERENCE,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 0,
                'version' => 1,
            ],
        ];

        foreach ($templates as $template) {
            $row = array_merge($template, ['auto_provision' => true]);

            SystemCategory::firstOrCreate(
                [
                    'slug' => $row['slug'],
                    'asset_type' => $row['asset_type'],
                    'version' => 1,
                ],
                $row
            );
        }

        // Normalize v1 seeded rows if they already existed before auto_provision was added to firstOrCreate attributes
        foreach ($templates as $template) {
            SystemCategory::where('slug', $template['slug'])
                ->where('asset_type', $template['asset_type'])
                ->where('version', 1)
                ->update(['auto_provision' => true]);
        }

        // Rename "Digital Ads" to "Digital" (slug stays digital-ads for config compatibility)
        SystemCategory::where('asset_type', AssetType::DELIVERABLE)
            ->where('slug', 'digital-ads')
            ->update(['name' => 'Digital']);
    }
}

<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\SystemCategory;
use Illuminate\Database\Seeder;

/**
 * System Category Template Seeder
 *
 * Seeds initial system category templates that will be copied to new brands.
 *
 * ASSET asset type categories: Logos, Photography, Graphics, Video
 * Execution/Deliverable asset type categories (exactly these 10):
 *   Print, Digital, OOH, Events, Videos, Sales Collateral, PR, Packaging, Product Renders, Radio
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
            ],
            [
                'name' => 'Photography',
                'slug' => 'photography',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Graphics',
                'slug' => 'graphics',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Video',
                'slug' => 'video',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 3,
            ],
            // Execution/Deliverable asset type system categories (exactly 10; slugs match metadata_category_defaults)
            [
                'name' => 'Print',
                'slug' => 'print',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 0,
            ],
            [
                'name' => 'Digital',
                'slug' => 'digital-ads',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'OOH',
                'slug' => 'ooh',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Events',
                'slug' => 'events',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Videos',
                'slug' => 'videos',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Sales Collateral',
                'slug' => 'sales-collateral',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 5,
            ],
            [
                'name' => 'PR',
                'slug' => 'pr',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 6,
            ],
            [
                'name' => 'Packaging',
                'slug' => 'packaging',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 7,
            ],
            [
                'name' => 'Product Renders',
                'slug' => 'product-renders',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 8,
            ],
            [
                'name' => 'Radio',
                'slug' => 'radio',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 9,
            ],
        ];

        foreach ($templates as $template) {
            SystemCategory::firstOrCreate(
                [
                    'slug' => $template['slug'],
                    'asset_type' => $template['asset_type'],
                ],
                $template
            );
        }

        // Rename "Digital Ads" to "Digital" (slug stays digital-ads for config compatibility)
        SystemCategory::where('asset_type', AssetType::DELIVERABLE)
            ->where('slug', 'digital-ads')
            ->update(['name' => 'Digital']);

        // Hide removed deliverable templates so they no longer appear in Executions sidebar
        SystemCategory::where('asset_type', AssetType::DELIVERABLE)
            ->whereIn('slug', ['catalogs', 'press-releases', 'social-creative'])
            ->update(['is_hidden' => true]);

        // Hide existing brand categories that used the removed templates (so sidebar shows new list only)
        Category::where('asset_type', AssetType::DELIVERABLE)
            ->whereIn('slug', ['catalogs', 'press-releases', 'social-creative'])
            ->update(['is_hidden' => true]);
    }
}

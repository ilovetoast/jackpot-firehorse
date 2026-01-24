<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\SystemCategory;
use Illuminate\Database\Seeder;

/**
 * System Category Template Seeder
 *
 * Seeds initial system category templates that will be copied to new brands.
 * 
 * ASSET asset type categories: Logos, Photography, Graphics
 * Deliverable asset type categories: Catalogs, Press Releases, Digital Ads, Social Creative, Videos
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
            // Deliverable asset type system categories
            [
                'name' => 'Catalogs',
                'slug' => 'catalogs',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 0,
            ],
            [
                'name' => 'Press Releases',
                'slug' => 'press-releases',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Digital Ads',
                'slug' => 'digital-ads',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Social Creative',
                'slug' => 'social-creative',
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
    }
}

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
 *
 * Broad noun categories; subtype detail lives in metadata fields (see metadata_category_defaults.php).
 *
 * ASSET: Photography, Graphics, Logos, Video, Audio, Documents, Templates, Fonts, 3D Models, Illustrations, Brand Elements
 * DELIVERABLE: Social, Digital, Print, Video Campaigns, Packaging, OOH / Environmental, Sales Collateral, PR / Communications,
 *               Events / Experiential, Web / Landing Pages, Email
 * LEGACY (no auto-provision): Product Renders, Radio
 */
class SystemCategoryTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            // --- Assets ---
            [
                'name' => 'Photography',
                'slug' => 'photography',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 0,
                'version' => 1,
            ],
            [
                'name' => 'Graphics',
                'slug' => 'graphics',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 1,
                'version' => 1,
            ],
            [
                'name' => 'Logos',
                'slug' => 'logos',
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
                'name' => 'Audio',
                'slug' => 'audio',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 4,
                'version' => 1,
            ],
            [
                'name' => 'Documents',
                'slug' => 'documents',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 5,
                'version' => 1,
            ],
            [
                'name' => 'Templates',
                'slug' => 'templates',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 6,
                'version' => 1,
            ],
            [
                'name' => 'Fonts',
                'slug' => 'fonts',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 7,
                'version' => 1,
            ],
            [
                'name' => '3D Models',
                'slug' => 'model-3d',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 8,
                'version' => 1,
            ],
            [
                'name' => 'Illustrations',
                'slug' => 'illustrations',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 9,
                'version' => 1,
            ],
            [
                'name' => 'Brand Elements',
                'slug' => 'brand-elements',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 10,
                'version' => 1,
            ],
            // --- Deliverables / executions ---
            [
                'name' => 'Social',
                'slug' => 'social',
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
                'name' => 'Print',
                'slug' => 'print',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 2,
                'version' => 1,
            ],
            [
                'name' => 'Video Campaigns',
                'slug' => 'videos',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 3,
                'version' => 1,
            ],
            [
                'name' => 'Packaging',
                'slug' => 'packaging',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 4,
                'version' => 1,
            ],
            [
                'name' => 'OOH / Environmental',
                'slug' => 'ooh',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 5,
                'version' => 1,
            ],
            [
                'name' => 'Sales Collateral',
                'slug' => 'sales-collateral',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 6,
                'version' => 1,
            ],
            [
                'name' => 'PR / Communications',
                'slug' => 'pr',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 7,
                'version' => 1,
            ],
            [
                'name' => 'Events / Experiential',
                'slug' => 'events',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 8,
                'version' => 1,
            ],
            [
                'name' => 'Web / Landing Pages',
                'slug' => 'web',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 9,
                'version' => 1,
            ],
            [
                'name' => 'Email',
                'slug' => 'email',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => false,
                'sort_order' => 10,
                'version' => 1,
            ],
            // Legacy deliverable folders (existing tenants only; not auto-provisioned for new brands)
            [
                'name' => 'Product Renders',
                'slug' => 'product-renders',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 11,
                'version' => 1,
            ],
            [
                'name' => 'Radio',
                'slug' => 'radio',
                'asset_type' => AssetType::DELIVERABLE,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 12,
                'version' => 1,
            ],
            // Reference (Brand Builder)
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
            $autoProvision = ! ($template['asset_type'] === AssetType::DELIVERABLE
                && in_array($template['slug'], ['product-renders', 'radio'], true));

            $row = array_merge($template, ['auto_provision' => $autoProvision]);

            SystemCategory::firstOrCreate(
                [
                    'slug' => $row['slug'],
                    'asset_type' => $row['asset_type'],
                    'version' => 1,
                ],
                $row
            );
        }

        foreach ($templates as $template) {
            $autoProvision = ! ($template['asset_type'] === AssetType::DELIVERABLE
                && in_array($template['slug'], ['product-renders', 'radio'], true));

            SystemCategory::where('slug', $template['slug'])
                ->where('asset_type', $template['asset_type'])
                ->where('version', 1)
                ->update([
                    'name' => $template['name'],
                    'is_hidden' => $template['is_hidden'],
                    'sort_order' => $template['sort_order'],
                    'auto_provision' => $autoProvision,
                ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $defaultBrand = $tenant->defaultBrand;

            if (! $defaultBrand) {
                continue;
            }

            // Basic asset type categories
            $basicCategories = [
                ['name' => 'General', 'slug' => 'general'],
                ['name' => 'Documents', 'slug' => 'documents'],
                ['name' => 'Images', 'slug' => 'images'],
            ];

            foreach ($basicCategories as $category) {
                Category::create([
                    'tenant_id' => $tenant->id,
                    'brand_id' => $defaultBrand->id,
                    'asset_type' => AssetType::BASIC,
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'is_system' => true,
                    'is_locked' => true,
                    'is_private' => false,
                ]);
            }

            // Marketing asset type categories
            $marketingCategories = [
                ['name' => 'Social Media', 'slug' => 'social-media'],
                ['name' => 'Email', 'slug' => 'email'],
                ['name' => 'Print', 'slug' => 'print'],
                ['name' => 'Web', 'slug' => 'web'],
            ];

            foreach ($marketingCategories as $category) {
                Category::create([
                    'tenant_id' => $tenant->id,
                    'brand_id' => $defaultBrand->id,
                    'asset_type' => AssetType::MARKETING,
                    'name' => $category['name'],
                    'slug' => $category['slug'],
                    'is_system' => true,
                    'is_locked' => true,
                    'is_private' => false,
                ]);
            }
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Tenant;
use App\Services\SystemCategorySeeder;
use Illuminate\Database\Seeder;

/**
 * Category Seeder
 *
 * Seeds system categories for all existing brands.
 * Uses SystemCategorySeeder service to ensure consistency and idempotency.
 */
class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seeder = app(SystemCategorySeeder::class);

        // Seed system categories for ALL brands (not just default)
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $brands = $tenant->brands;

            foreach ($brands as $brand) {
                $seeder->seedForBrand($brand);
            }
        }
    }
}

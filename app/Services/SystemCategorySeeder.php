<?php

namespace App\Services;

use App\Models\Brand;

/**
 * System Category Seeder Service
 *
 * Handles creation of default system categories for brands.
 * Now uses SystemCategory templates instead of hardcoded categories.
 *
 * This service ensures idempotency - running it multiple times won't create duplicates.
 *
 * @deprecated This service is kept for backward compatibility.
 * New brands should use SystemCategoryService::syncToBrand() directly.
 */
class SystemCategorySeeder
{
    /**
     * Seed system categories for a brand.
     *
     * Creates system categories from templates for the specified brand.
     * This method is idempotent - it won't create duplicates if categories already exist.
     *
     * @param Brand $brand The brand to seed categories for
     * @return void
     */
    public function seedForBrand(Brand $brand): void
    {
        // Use SystemCategoryService to sync templates to brand
        app(SystemCategoryService::class)->syncToBrand($brand);
    }
}

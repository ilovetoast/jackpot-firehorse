<?php

namespace App\Services;

use App\Models\Brand;

/**
 * System Category Seeder Service
 *
 * Handles creation of default system categories for brands.
 * Delegates to SystemCategoryService::syncToBrand(), which only adds templates with auto_provision=true.
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
     * @param  Brand  $brand  The brand to seed categories for
     */
    public function seedForBrand(Brand $brand): void
    {
        // Use SystemCategoryService to sync templates to brand
        app(SystemCategoryService::class)->syncToBrand($brand);
    }
}

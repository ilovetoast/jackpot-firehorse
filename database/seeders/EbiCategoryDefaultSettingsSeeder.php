<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Idempotent: only sets settings.ebi_enabled when the key is absent (matches migration backfill rules).
 */
class EbiCategoryDefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Category::query()->orderBy('id')->each(function (Category $category) {
            $settings = $category->settings ?? [];
            if (array_key_exists('ebi_enabled', $settings)) {
                return;
            }
            $settings['ebi_enabled'] = Category::defaultEbiEnabledForSystemSlug((string) $category->slug);
            $category->update(['settings' => $settings]);
        });
    }
}

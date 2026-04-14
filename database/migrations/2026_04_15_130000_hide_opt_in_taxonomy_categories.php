<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in taxonomy folders: hide new system templates and matching brand categories by default.
 * Tenants enable them via Manage → Categories (show folder / add from catalog).
 *
 * @see database/seeders/SystemCategoryTemplateSeeder.php
 */
return new class extends Migration
{
    /** @var list<string> */
    protected array $optInSlugs = [
        'audio',
        'documents',
        'templates',
        'model-3d',
        'illustrations',
        'brand-elements',
        'social',
        'web',
        'email',
    ];

    public function up(): void
    {
        if (Schema::hasTable('system_categories')) {
            DB::table('system_categories')
                ->whereIn('slug', $this->optInSlugs)
                ->update(['is_hidden' => true, 'updated_at' => now()]);
        }

        if (Schema::hasTable('categories')) {
            DB::table('categories')
                ->where('is_system', true)
                ->whereIn('slug', $this->optInSlugs)
                ->whereNull('deleted_at')
                ->update(['is_hidden' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_categories')) {
            DB::table('system_categories')
                ->whereIn('slug', $this->optInSlugs)
                ->update(['is_hidden' => false, 'updated_at' => now()]);
        }

        if (Schema::hasTable('categories')) {
            DB::table('categories')
                ->where('is_system', true)
                ->whereIn('slug', $this->optInSlugs)
                ->whereNull('deleted_at')
                ->update(['is_hidden' => false, 'updated_at' => now()]);
        }
    }
};

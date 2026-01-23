<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SystemCategoryTemplateSeeder::class, // Seed system category templates first
            NotificationTemplateSeeder::class, // Seed notification templates
            CompanyBrandSeeder::class, // This creates the site owner and companies/brands
            EnsureDefaultBrandsSeeder::class, // Ensure all tenants have default brands
            CategorySeeder::class,
            MetadataFieldPermissionSeeder::class, // Seed metadata field permissions (owner/admin can edit all fields)
            MetadataFieldPopulationSeeder::class, // Configure automatic population fields (orientation, color_space, resolution_class, etc.)
            MetadataFieldAiEligibleSeeder::class, // Enable AI suggestions for fields like photo_type
        ]);
    }
}

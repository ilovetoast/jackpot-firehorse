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
            CategorySeeder::class,
        ]);
    }
}

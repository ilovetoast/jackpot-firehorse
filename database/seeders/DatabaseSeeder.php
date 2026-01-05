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
        // Create a default user
        $user = User::create([
            'name' => 'Michael Steele',
            'email' => 'msteele@velvethammerbranding.com',
            'password' => Hash::make('password'),
        ]);

        // Create a default tenant/company
        // Note: Brand is automatically created via Tenant model event
        $tenant = Tenant::create([
            'name' => 'Velvet Hammer Branding',
            'slug' => 'velvet-hammer-branding',
        ]);

        // Associate user with tenant
        $user->tenants()->attach($tenant->id);
        
        // Ensure default brand exists (should be created automatically, but verify)
        if (! $tenant->defaultBrand) {
            $tenant->brands()->create([
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_default' => true,
            ]);
        }

        // Run the company/brand seeder
        $this->call(CompanyBrandSeeder::class);
    }
}

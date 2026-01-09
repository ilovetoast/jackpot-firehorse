<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CompanyBrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create initial user account (ID 1) with placeholder info
        $initialUser = User::firstOrCreate(
            ['email' => 'msteele@velvethammerbranding.com'],
            [
                'first_name' => 'Michael',
                'last_name' => 'Steele',
                'password' => Hash::make('gotrice'),
            ]
        );

        // Create initial company for user 1
        $initialCompany = Tenant::firstOrCreate(
            ['slug' => 'velvethammerbranding'],
            ['name' => 'Velve Hammer Branding']
        );

        // Attach user 1 to the initial company as owner
        $initialUser->tenants()->syncWithoutDetaching([$initialCompany->id => ['role' => 'owner']]);
        // make Site Owner role
        $initialUser->assignRole('site_owner');

        // Get the default brand for the initial company
        $initialDefaultBrand = $initialCompany->defaultBrand;
        if ($initialDefaultBrand) {
            $initialDefaultBrand->update([
                'name' => 'Example Company',
                'show_in_selector' => true,
                'primary_color' => '#6366f1',
                'secondary_color' => '#8b5cf6',
                'accent_color' => '#ec4899',
            ]);
        }

        // Create a secondary user for testing/development (will be user ID 2+ if user 1 exists)
        // NOTE: This user should NEVER have site_owner role - only user ID 1 can be site_owner
        $secondaryUser = User::firstOrCreate(
            ['email' => 'johndoe@example.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'password' => Hash::make('password'),
            ]
        );

        // Remove site_owner role if it was previously assigned (safety check)
        if ($secondaryUser->hasRole('site_owner')) {
            $secondaryUser->removeRole('site_owner');
        }

        // Define secondary companies and their brands
        $companiesData = [
            'St. Croix' => ['St Croix', 'St Croix Fly', 'Seviin'],
            'Augusta' => ['Augusta'],
            'ACG' => ['Nebo', 'True', 'Thaw'],
            'Victory' => ['Victory'],
        ];

        foreach ($companiesData as $companyName => $brandNames) {
            $companySlug = Str::slug($companyName);
            
            // Create or get tenant - the boot() method will auto-create a default brand
            $tenant = Tenant::firstOrCreate(
                ['slug' => $companySlug],
                ['name' => $companyName]
            );

            // Update name if it changed
            if ($tenant->name !== $companyName) {
                $tenant->update(['name' => $companyName]);
            }

            // Attach secondary user to every company
            $secondaryUser->tenants()->syncWithoutDetaching([$tenant->id]);

            // Get the first brand name we want
            $firstBrandName = $brandNames[0];
            $firstBrandSlug = Str::slug($firstBrandName);
            
            // Get the auto-created default brand (created by Tenant boot method)
            $defaultBrand = $tenant->defaultBrand;
            
            if ($defaultBrand) {
                // If the default brand's slug matches what we want, update it
                if ($defaultBrand->slug === $firstBrandSlug) {
                    $defaultBrand->update([
                        'name' => $firstBrandName,
                        'show_in_selector' => true,
                        'primary_color' => '#000000',
                        'secondary_color' => '#ffffff',
                        'accent_color' => '#6366f1',
                    ]);
                } else {
                    // If it doesn't match, update it to be the first brand we want
                    $defaultBrand->update([
                        'name' => $firstBrandName,
                        'slug' => $firstBrandSlug,
                        'show_in_selector' => true,
                        'primary_color' => '#000000',
                        'secondary_color' => '#ffffff',
                        'accent_color' => '#6366f1',
                    ]);
                }
            } else {
                // If no default brand exists (shouldn't happen, but handle it), create it
                Brand::create([
                    'tenant_id' => $tenant->id,
                    'name' => $firstBrandName,
                    'slug' => $firstBrandSlug,
                    'is_default' => true,
                    'show_in_selector' => true,
                    'primary_color' => '#000000',
                    'secondary_color' => '#ffffff',
                    'accent_color' => '#6366f1',
                    'settings' => [],
                ]);
            }

            // Create any additional brands (skip the first one since we already handled it)
            foreach (array_slice($brandNames, 1) as $brandName) {
                $brandSlug = Str::slug($brandName);
                
                Brand::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $brandSlug],
                    [
                        'name' => $brandName,
                        'is_default' => false,
                        'show_in_selector' => true,
                        'primary_color' => '#000000',
                        'secondary_color' => '#ffffff',
                        'accent_color' => '#6366f1',
                        'settings' => [],
                    ]
                );
            }
        }
    }
}

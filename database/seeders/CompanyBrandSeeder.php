<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CompanyBrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create user 1 (Site Owner)
        $siteOwner = User::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Site Owner',
                'email' => 'siteowner@example.com',
                'password' => Hash::make('password'),
            ]
        );

        // Create companies and brands
        $companies = [
            [
                'name' => 'St. Croix',
                'slug' => 'st-croix',
                'brands' => [
                    'St Croix',
                    'St Croix Fly',
                    'Seviin',
                ],
            ],
            [
                'name' => 'Augusta',
                'slug' => 'augusta',
                'brands' => [],
            ],
            [
                'name' => 'ACG',
                'slug' => 'acg',
                'brands' => [
                    'Nebo',
                    'True',
                    'Thaw',
                ],
            ],
            [
                'name' => 'Victory',
                'slug' => 'victory',
                'brands' => [],
            ],
        ];

        foreach ($companies as $companyData) {
            // Create company
            $company = Tenant::firstOrCreate(
                ['slug' => $companyData['slug']],
                ['name' => $companyData['name']]
            );

            // Attach site owner to company
            if (!$company->users()->where('users.id', $siteOwner->id)->exists()) {
                $company->users()->attach($siteOwner->id);
            }

            // Create brands for this company
            if (empty($companyData['brands'])) {
                // If no brands specified, ensure at least default brand exists
                if (!$company->defaultBrand) {
                    $company->brands()->create([
                        'name' => $company->name,
                        'slug' => $company->slug,
                        'is_default' => true,
                    ]);
                }
            } else {
                // Create specified brands
                $isFirst = true;
                foreach ($companyData['brands'] as $brandName) {
                    $brandSlug = \Illuminate\Support\Str::slug($brandName);
                    $brand = Brand::firstOrCreate(
                        [
                            'tenant_id' => $company->id,
                            'slug' => $brandSlug,
                        ],
                        [
                            'name' => $brandName,
                            'is_default' => $isFirst,
                        ]
                    );

                    // If this is the first brand and it's not default, make it default
                    if ($isFirst && !$brand->is_default) {
                        // Unset other defaults
                        Brand::where('tenant_id', $company->id)
                            ->where('id', '!=', $brand->id)
                            ->update(['is_default' => false]);
                        $brand->update(['is_default' => true]);
                    }

                    $isFirst = false;
                }
            }
        }
    }
}

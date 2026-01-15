<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Ensures company 1 (tenant ID 1) has a default brand.
 * 
 * This seeder ensures the initial company always has a brand.
 */
class EnsureDefaultBrandsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure company 1 (Velve Hammer Branding) has a default brand
        $company1 = Tenant::find(1);
        
        if (! $company1) {
            $this->command->warn('Company 1 not found. Skipping brand creation.');
            return;
        }
        
        $defaultBrand = $company1->defaultBrand;
        
        // If no default brand exists, create or fix one
        if (! $defaultBrand) {
            // Check if tenant has any brands at all
            $anyBrand = $company1->brands()->first();
            
            if ($anyBrand) {
                // Mark the first brand as default
                $anyBrand->update(['is_default' => true]);
                $this->command->info("Set first brand as default for company 1: {$company1->name}");
            } else {
                // Create a default brand for the tenant
                $defaultBrand = $company1->brands()->create([
                    'name' => $company1->name,
                    'slug' => $company1->slug,
                    'is_default' => true,
                    'show_in_selector' => true,
                ]);
                $this->command->info("Created default brand for company 1: {$company1->name}");
            }
        } else {
            $this->command->info("Company 1 already has a default brand: {$defaultBrand->name}");
        }
    }
}

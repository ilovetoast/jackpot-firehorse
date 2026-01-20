<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
        // CRITICAL: Ensure msteele@velvethammerbranding.com is ALWAYS user ID 1
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Check if user with email already exists
        $existingUser = DB::table('users')->where('email', 'msteele@velvethammerbranding.com')->first();
        
        if ($existingUser) {
            // User exists - check if it's already ID 1
            if ($existingUser->id !== 1) {
                // User exists but not as ID 1 - need to swap IDs
                $oldId = $existingUser->id;
                
                // Check if ID 1 is already taken by another user
                $userAtId1 = DB::table('users')->where('id', 1)->first();
                if ($userAtId1 && $userAtId1->id === 1 && $userAtId1->email !== 'msteele@velvethammerbranding.com') {
                    // ID 1 is taken by wrong user - move them to oldId temporarily, then to a high number
                    $tempId = 999999;
                    $this->updateUserReferences(1, $tempId);
                    DB::table('users')->where('id', 1)->update(['id' => $tempId]);
                }
                
                // Now update msteele's ID from oldId to 1
                $this->updateUserReferences($oldId, 1);
                DB::table('users')->where('id', $oldId)->update(['id' => 1]);
            }
            
            // Update user attributes to ensure they're correct
            DB::table('users')->where('id', 1)->update([
                'first_name' => 'Michael',
                'last_name' => 'Steele',
            ]);
        } else {
            // User doesn't exist - create with ID 1
            // First check if ID 1 is available
            $userAtId1 = DB::table('users')->where('id', 1)->first();
            if ($userAtId1) {
                // ID 1 is taken - move that user first
                $maxId = DB::table('users')->max('id') ?? 0;
                $newId = $maxId + 1;
                $this->updateUserReferences(1, $newId);
                DB::table('users')->where('id', 1)->update(['id' => $newId]);
            }
            
            // Now insert the new user with ID 1
            DB::table('users')->insert([
                'id' => 1,
                'email' => 'msteele@velvethammerbranding.com',
                'first_name' => 'Michael',
                'last_name' => 'Steele',
                'password' => Hash::make('gotrice'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        
        // Get the user as Eloquent model
        $initialUser = User::find(1);

        // Create initial company for user 1
        $initialCompany = Tenant::firstOrCreate(
            ['slug' => 'velvethammerbranding'],
            ['name' => 'Velve Hammer Branding']
        );

        // Set tenant 1 to enterprise plan from the beginning
        $initialCompany->update([
            'manual_plan_override' => 'enterprise',
        ]);

        // Attach user 1 to the initial company as owner
        $initialUser->tenants()->syncWithoutDetaching([$initialCompany->id => ['role' => 'owner']]);
        // make Site Owner role
        $initialUser->assignRole('site_owner');

        // Ensure the initial company has a default brand
        $initialDefaultBrand = $initialCompany->defaultBrand;
        if (! $initialDefaultBrand) {
            // Check if tenant has any brands at all
            $anyBrand = $initialCompany->brands()->first();
            
            if ($anyBrand) {
                // Mark the first brand as default
                $anyBrand->update(['is_default' => true]);
                $initialDefaultBrand = $anyBrand->fresh();
            } else {
                // Create a default brand for the tenant
                $initialDefaultBrand = $initialCompany->brands()->create([
                    'name' => $initialCompany->name, // Brand name same as company name
                    'slug' => $initialCompany->slug,
                    'is_default' => true,
                    'show_in_selector' => true,
                ]);
            }
        }
        
        // Update the brand with styling - brand name matches company name
        if ($initialDefaultBrand) {
            $initialDefaultBrand->update([
                'name' => $initialCompany->name, // Brand name same as company name
                'show_in_selector' => true,
                'primary_color' => '#6366f1',
                'secondary_color' => '#8b5cf6',
                'accent_color' => '#ec4899',
            ]);
        }

        // Ensure user 1 is admin of the brand
        if ($initialDefaultBrand) {
            $initialUser->brands()->syncWithoutDetaching([$initialDefaultBrand->id => ['role' => 'admin']]);
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
    
    /**
     * Helper method to update all foreign key references when changing user ID
     */
    private function updateUserReferences(int $oldId, int $newId): void
    {
        $tablesWithUserId = [
            'tenant_user' => ['user_id'],
            'brand_user' => ['user_id'],
            'model_has_roles' => ['model_id'],
            'assets' => ['user_id'],
            'asset_events' => ['user_id'],
            'asset_metrics' => ['user_id'],
            'tickets' => ['created_by_user_id', 'assigned_to_user_id', 'converted_by_user_id'],
            'ticket_messages' => ['user_id'],
            'ticket_attachments' => ['user_id'],
            'ai_ticket_suggestions' => ['accepted_by_user_id', 'rejected_by_user_id'],
            'ai_agent_runs' => ['user_id'],
            'ownership_transfers' => ['initiated_by_user_id', 'from_user_id', 'to_user_id'],
            'category_access' => ['user_id'],
            'ai_model_overrides' => ['created_by_user_id', 'updated_by_user_id'],
            'ai_agent_overrides' => ['created_by_user_id', 'updated_by_user_id'],
            'ai_automation_overrides' => ['created_by_user_id', 'updated_by_user_id'],
            'ai_budget_overrides' => ['created_by_user_id', 'updated_by_user_id'],
            'frontend_errors' => ['user_id'],
        ];
        
        foreach ($tablesWithUserId as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    DB::table($table)->where($column, $oldId)->update([$column => $newId]);
                } catch (\Exception $e) {
                    // Table or column might not exist, continue
                }
            }
        }
        
        // Handle activity_events separately (uses actor_id with actor_type)
        try {
            DB::table('activity_events')
                ->where('actor_type', 'App\Models\User')
                ->where('actor_id', $oldId)
                ->update(['actor_id' => $newId]);
        } catch (\Exception $e) {
            // Table might not exist, continue
        }
    }
}

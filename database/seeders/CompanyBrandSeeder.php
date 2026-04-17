<?php

namespace Database\Seeders;

use App\Models\AgencyTier;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\User;
use App\Services\TenantAgencyService;
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

        // Canonical: Company ID 1 = Velvet Hammer (primary agency)
        $initialCompany = Tenant::firstOrCreate(
            ['slug' => 'velvethammerbranding'],
            ['name' => 'Velvet Hammer', 'uuid' => Str::uuid()->toString()]
        );
        $initialCompany->update(['name' => 'Velvet Hammer']);

        // Agency: business plan (feature limits), Platinum tier (highest seeded tier), approved
        $platinumTier = AgencyTier::where('name', 'Platinum')->first();
        $initialCompany->update([
            'manual_plan_override' => 'business',
            'is_agency' => true,
            'agency_approved_at' => now(),
            'agency_approved_by' => $initialUser->id,
            'agency_tier_id' => $platinumTier?->id,
        ]);

        // Attach user 1 to the initial company as owner
        // Use bypassOwnerCheck=true since this is seeder (initial setup)
        $initialUser->setRoleForTenant($initialCompany, 'owner', true);
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
                'primary_color' => '#502c6d',
                'secondary_color' => '#ffffff',
                'accent_color' => null,
                'accent_color_user_defined' => false,
                'icon_style' => 'solid',
            ]);
        }

        // Ensure user 1 is admin of the brand
        if ($initialDefaultBrand) {
            $initialUser->brands()->syncWithoutDetaching([$initialDefaultBrand->id => ['role' => 'admin']]);
        }

        // Bill Rempe: admin for tenant 1 (Velvet Hammer), member of all other tenants and all brands
        $bill = User::firstOrCreate(
            ['email' => 'brempe@velvethammerbranding.com'],
            [
                'first_name' => 'Bill',
                'last_name' => 'Rempe',
                'password' => Hash::make('hammerna!l'),
            ]
        );
        $bill->setRoleForTenant($initialCompany, 'admin', true);
        if ($initialDefaultBrand) {
            $bill->brands()->syncWithoutDetaching([$initialDefaultBrand->id => ['role' => 'admin']]);
        }
        if (! $bill->hasRole('site_admin')) {
            $bill->assignRole('site_admin');
        }

        $tenantAgencyService = app(TenantAgencyService::class);

        // Drop prior agency links (VH → clients) so pivots can be recreated idempotently
        foreach (TenantAgency::where('agency_tenant_id', $initialCompany->id)->get() as $link) {
            $tenantAgencyService->detach($link);
        }

        // msteele + brempe: only direct membership on Velvet Hammer (tenant 1 / primary agency).
        // Remove direct tenant_user and brand_user on all other companies; access comes from agency link below.
        DB::table('tenant_user')
            ->whereIn('user_id', [$initialUser->id, $bill->id])
            ->where('tenant_id', '!=', $initialCompany->id)
            ->delete();

        $nonAgencyBrandIds = Brand::where('tenant_id', '!=', $initialCompany->id)->pluck('id');
        if ($nonAgencyBrandIds->isNotEmpty()) {
            DB::table('brand_user')
                ->whereIn('user_id', [$initialUser->id, $bill->id])
                ->whereIn('brand_id', $nonAgencyBrandIds)
                ->whereNull('removed_at')
                ->update(['removed_at' => now(), 'updated_at' => now()]);
        }

        // Optional demo user (not attached to agency-client tenants — incubation is agency-stewarded).
        $secondaryUser = User::firstOrCreate(
            ['email' => 'johndoe@example.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'password' => Hash::make('password'),
            ]
        );

        if ($secondaryUser->hasRole('site_owner')) {
            $secondaryUser->removeRole('site_owner');
        }

        // Define secondary companies, their brands, and plan assignments
        $companiesData = [
            'St. Croix' => ['brands' => ['St Croix', 'St Croix Fly', 'Seviin'], 'plan' => 'pro'],
            'Augusta' => ['brands' => ['Augusta'], 'plan' => 'starter'],
            'ACG' => ['brands' => ['Nebo', 'True', 'Thaw'], 'plan' => 'business'],
            'Victory' => ['brands' => ['Victory'], 'plan' => 'free'],
        ];

        foreach ($companiesData as $companyName => $companyConfig) {
            $brandNames = $companyConfig['brands'];
            $plan = $companyConfig['plan'];
            $companySlug = Str::slug($companyName);

            // Create or get tenant - the boot() method will auto-create a default brand
            $tenant = Tenant::firstOrCreate(
                ['slug' => $companySlug],
                ['name' => $companyName, 'uuid' => Str::uuid()->toString()]
            );

            // Update name if it changed
            if ($tenant->name !== $companyName) {
                $tenant->update(['name' => $companyName]);
            }

            // Incubated clients: agency is steward; no separate "client owner" user in default seed.
            // Michael (user 1) becomes tenant `owner` after tenant_agencies attach (temporary steward until transfer).
            $tenant->update([
                'incubated_by_agency_id' => $initialCompany->id,
                'manual_plan_override' => $plan,
                'incubated_at' => $tenant->incubated_at ?? now(),
                'incubation_expires_at' => null,
            ]);

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

            $tenant->load('brands');

            // Remove legacy demo "client owner" membership so the agency is the steward (re-seed safe)
            DB::table('tenant_user')
                ->where('user_id', $secondaryUser->id)
                ->where('tenant_id', $tenant->id)
                ->delete();
            $clientBrandIds = $tenant->brands->pluck('id');
            if ($clientBrandIds->isNotEmpty()) {
                DB::table('brand_user')
                    ->where('user_id', $secondaryUser->id)
                    ->whereIn('brand_id', $clientBrandIds)
                    ->whereNull('removed_at')
                    ->update(['removed_at' => now(), 'updated_at' => now()]);
            }

            // Velvet Hammer (agency) → client tenant: explicit RBAC via tenant_agencies + agency-managed pivots
            $brandAssignments = $tenant->brands->map(fn (Brand $b) => [
                'brand_id' => $b->id,
                'role' => 'admin',
            ])->all();

            $tenantAgencyService->attach(
                $tenant,
                $initialCompany,
                'agency_admin',
                $brandAssignments,
                $initialUser
            );

            // Temporary owner on the client tenant is the agency primary user (until ownership transfer to a client).
            $initialUser->setRoleForTenant($tenant, 'owner', true);
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

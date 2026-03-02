<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix brands where tenant owners/admins lack brand_user access.
 * Run this to repair "No brand access" for company leadership.
 */
class FixTenantLeadershipBrandAccess extends Command
{
    protected $signature = 'brand:fix-tenant-leadership-access
                            {--tenant-id= : Fix only for this tenant ID}
                            {--brand-id= : Fix only for this brand ID}
                            {--user-email= : Add this user to brands in tenant where they lack access}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Add tenant owners and admins to brands they lack access to';

    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');
        $brandId = $this->option('brand-id');
        $userEmail = $this->option('user-email');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        if ($userEmail) {
            return $this->fixUserAccess($userEmail, $tenantId, $brandId, $dryRun);
        }

        return $this->fixLeadershipAccess($tenantId, $brandId, $dryRun);
    }

    protected function fixLeadershipAccess(?string $tenantId, ?string $brandId, bool $dryRun): int
    {
        $brands = Brand::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($brandId, fn ($q) => $q->where('id', $brandId))
            ->get();

        $fixed = 0;
        foreach ($brands as $brand) {
            $tenant = $brand->tenant;
            $owner = $tenant->owner();
            $admins = $tenant->users()->wherePivot('role', 'admin')->get();

            $leadership = collect([$owner])->merge($admins)->filter()->unique('id');

            foreach ($leadership as $user) {
                $hasAccess = DB::table('brand_user')
                    ->where('user_id', $user->id)
                    ->where('brand_id', $brand->id)
                    ->whereNull('removed_at')
                    ->exists();

                if (! $hasAccess) {
                    $this->line("  {$tenant->name} / {$brand->name}: Adding {$user->email} (ID: {$user->id}) as admin");

                    if (! $dryRun) {
                        try {
                            $user->setRoleForBrand($brand, 'admin');
                            $fixed++;
                        } catch (\Throwable $e) {
                            $this->error("    Failed: {$e->getMessage()}");
                        }
                    } else {
                        $fixed++;
                    }
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? "Would fix {$fixed} membership(s)." : "Fixed {$fixed} membership(s).");

        return 0;
    }

    protected function fixUserAccess(string $userEmail, ?string $tenantId, ?string $brandId, bool $dryRun): int
    {
        $user = User::where('email', $userEmail)->first();
        if (! $user) {
            $this->error("User not found: {$userEmail}");
            return 1;
        }

        $tenants = $user->tenants()
            ->when($tenantId, fn ($q) => $q->where('tenants.id', $tenantId))
            ->get();

        if ($tenants->isEmpty()) {
            $this->error("User {$userEmail} is not a member of any tenant" . ($tenantId ? " with ID {$tenantId}" : ''));
            return 1;
        }

        $fixed = 0;
        foreach ($tenants as $tenant) {
            $brands = $tenant->brands()
                ->when($brandId, fn ($q) => $q->where('brands.id', $brandId))
                ->get();

            foreach ($brands as $brand) {
                $hasAccess = DB::table('brand_user')
                    ->where('user_id', $user->id)
                    ->where('brand_id', $brand->id)
                    ->whereNull('removed_at')
                    ->exists();

                if (! $hasAccess) {
                    $this->line("  {$tenant->name} / {$brand->name}: Adding {$user->email} as viewer");

                    if (! $dryRun) {
                        try {
                            $user->setRoleForBrand($brand, 'viewer');
                            $fixed++;
                        } catch (\Throwable $e) {
                            $this->error("    Failed: {$e->getMessage()}");
                        }
                    } else {
                        $fixed++;
                    }
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? "Would fix {$fixed} membership(s)." : "Fixed {$fixed} membership(s).");

        return 0;
    }
}

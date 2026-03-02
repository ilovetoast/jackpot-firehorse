<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnose why a user sees "No brand access".
 * Shows tenant role, brand_user records, and recommended fix.
 */
class DiagnoseUserBrandAccess extends Command
{
    protected $signature = 'brand:diagnose-user-access
                            {email : User email to diagnose}
                            {--tenant-id= : Optional tenant ID to scope}';

    protected $description = 'Diagnose why a user sees "No brand access"';

    public function handle(): int
    {
        $email = $this->argument('email');
        $tenantId = $this->option('tenant-id');

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("User not found: {$email}");
            return 1;
        }

        $this->info("User: {$user->name} ({$user->email}) ID: {$user->id}");
        $this->newLine();

        $tenantUsers = DB::table('tenant_user')
            ->where('user_id', $user->id)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get();

        if ($tenantUsers->isEmpty()) {
            $this->warn('User is not in any company (tenant_user). Add them to a company first.');
            return 0;
        }

        foreach ($tenantUsers as $tu) {
            $tenant = \App\Models\Tenant::find($tu->tenant_id);
            $tenantName = $tenant?->name ?? "Tenant #{$tu->tenant_id}";
            $this->info("Company: {$tenantName} (ID: {$tu->tenant_id})");
            $this->line("  tenant_user.role: {$tu->role}");

            if (! in_array($tu->role, ['owner', 'admin'])) {
                $this->warn("  → User is NOT company owner/admin. They need brand_user records to see brands.");
            } else {
                $this->line("  → Company owner/admin: should see all brands automatically.");
            }

            $brands = \App\Models\Brand::where('tenant_id', $tu->tenant_id)->get();
            $this->line("  Brands in company: {$brands->count()}");

            $brandUserRecords = DB::table('brand_user')
                ->where('user_id', $user->id)
                ->whereNull('removed_at')
                ->whereIn('brand_id', $brands->pluck('id'))
                ->get();

            $this->line("  brand_user records (active): {$brandUserRecords->count()}");

            if ($brandUserRecords->isEmpty() && ! in_array($tu->role, ['owner', 'admin'])) {
                $this->error("  → No brand access! Run: sail artisan brand:fix-tenant-leadership-access --user-email={$email} --tenant-id={$tu->tenant_id}");
            } elseif ($brandUserRecords->isEmpty() && in_array($tu->role, ['owner', 'admin'])) {
                $this->warn("  → Company admin but brands array may be empty for another reason (e.g. tenant has no brands).");
            }

            foreach ($brands as $brand) {
                $hasAccess = $brandUserRecords->contains('brand_id', $brand->id)
                    || in_array($tu->role, ['owner', 'admin']);
                $status = $hasAccess ? '✓' : '✗';
                $this->line("    {$status} {$brand->name} (ID: {$brand->id})");
            }

            $this->newLine();
        }

        return 0;
    }
}

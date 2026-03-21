<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard widget visibility is no longer configurable per tenant; defaults are defined in code.
 * Removes stored overrides and the Spatie permission for the removed Company Settings section.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'company_settings.manage_dashboard_widgets';
        $perm = DB::table('permissions')->where('name', $name)->first();
        if ($perm) {
            DB::table('role_has_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }

        Tenant::query()->chunkById(100, function ($tenants): void {
            foreach ($tenants as $tenant) {
                $settings = $tenant->settings ?? [];
                if (! isset($settings['dashboard_widgets'])) {
                    continue;
                }
                unset($settings['dashboard_widgets']);
                $tenant->settings = $settings;
                $tenant->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Irreversible: permission and per-tenant widget JSON are not restored.
    }
};

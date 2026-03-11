<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Add company.view permission and assign to owner/admin roles.
     * Company page shows aggregated metrics across all brands (tenant admin/owner only).
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'company.view', 'guard_name' => 'web']
        );

        foreach (['owner', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permission = Permission::where('name', 'company.view')->where('guard_name', 'web')->first();
        if ($permission) {
            foreach (['owner', 'admin'] as $roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role) {
                    $role->revokePermissionTo($permission);
                }
            }
            $permission->delete();
        }
    }
};

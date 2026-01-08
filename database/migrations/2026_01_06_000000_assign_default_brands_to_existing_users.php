<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Brand;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Assigns existing users to default brands if they belong to a tenant
     * but don't have any brand assignments.
     */
    public function up(): void
    {
        // Get all users who belong to tenants
        $users = User::with('tenants')->get();
        
        foreach ($users as $user) {
            foreach ($user->tenants as $tenant) {
                // Check if user has any brand assignments in this tenant
                $hasBrandAssignment = $user->brands()
                    ->where('tenant_id', $tenant->id)
                    ->exists();
                
                if (!$hasBrandAssignment) {
                    // Get default brand for this tenant
                    $defaultBrand = $tenant->defaultBrand;
                    
                    if ($defaultBrand) {
                        // Get user's role in the tenant
                        $tenantRole = $user->getRoleForTenant($tenant) ?? 'member';
                        
                        // Assign user to default brand with tenant role (or 'member' if no role)
                        $user->setRoleForBrand($defaultBrand, $tenantRole);
                    } else {
                        // No default brand, assign to first brand or all brands
                        $firstBrand = $tenant->brands()->first();
                        if ($firstBrand) {
                            $tenantRole = $user->getRoleForTenant($tenant) ?? 'member';
                            $user->setRoleForBrand($firstBrand, $tenantRole);
                        }
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not reversible as it's a data correction
        // We don't want to remove brand assignments
    }
};

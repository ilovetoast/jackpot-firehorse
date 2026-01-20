<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Migrates existing 'member' roles to 'contributor' role.
     * This is an additive migration - preserves existing data while updating role names.
     */
    public function up(): void
    {
        // Update tenant_user pivot table
        $tenantUserUpdated = DB::table('tenant_user')
            ->where('role', 'member')
            ->update(['role' => 'contributor']);
        
        Log::info('[Migration] Migrated member role to contributor in tenant_user', [
            'updated_count' => $tenantUserUpdated,
        ]);

        // Update brand_user pivot table
        $brandUserUpdated = DB::table('brand_user')
            ->where('role', 'member')
            ->update(['role' => 'contributor']);
        
        Log::info('[Migration] Migrated member role to contributor in brand_user', [
            'updated_count' => $brandUserUpdated,
        ]);
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This reverts contributor back to member, but this is not recommended
     * as member is deprecated. Only use if absolutely necessary.
     */
    public function down(): void
    {
        // Revert tenant_user pivot table
        DB::table('tenant_user')
            ->where('role', 'contributor')
            ->update(['role' => 'member']);

        // Revert brand_user pivot table
        DB::table('brand_user')
            ->where('role', 'contributor')
            ->update(['role' => 'member']);
    }
};

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Metadata Field Permission Seeder
 *
 * Creates default permissions for metadata field editing.
 * By default, owner, admin, and member roles can edit all metadata fields.
 *
 * Phase 4: Default permissions are set at tenant level (no brand/category scope).
 * Administrators can override these permissions via the admin UI if needed.
 */
class MetadataFieldPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all tenants
        $tenants = DB::table('tenants')->get();
        
        // Get all metadata fields
        $fields = DB::table('metadata_fields')
            ->whereNull('deprecated_at')
            ->get();
        
        // Roles that should have edit permission by default
        $rolesWithEdit = ['owner', 'admin', 'member'];
        
        $permissions = [];
        
        foreach ($tenants as $tenant) {
            foreach ($fields as $field) {
                foreach ($rolesWithEdit as $role) {
                    // Check if permission already exists
                    $exists = DB::table('metadata_field_permissions')
                        ->where('metadata_field_id', $field->id)
                        ->where('role', $role)
                        ->where('tenant_id', $tenant->id)
                        ->whereNull('brand_id')
                        ->whereNull('category_id')
                        ->exists();
                    
                    if (!$exists) {
                        $permissions[] = [
                            'metadata_field_id' => $field->id,
                            'role' => $role,
                            'tenant_id' => $tenant->id,
                            'brand_id' => null, // Tenant-level permission
                            'category_id' => null, // Tenant-level permission
                            'can_edit' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
        }
        
        // Insert permissions in batches
        if (!empty($permissions)) {
            DB::table('metadata_field_permissions')->insert($permissions);
            $this->command->info('Created ' . count($permissions) . ' metadata field permissions.');
        } else {
            $this->command->info('No new permissions to create (all already exist).');
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            'view brand',
            'upload asset',
            'view private category',
            'approve asset',
            'manage categories',
            'manage brands',
            'manage users',
            'manage billing',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Assign permissions to roles
        $owner = Role::findByName('owner', 'web');
        $admin = Role::findByName('admin', 'web');
        $contributor = Role::findByName('contributor', 'web');
        $viewer = Role::findByName('viewer', 'web');

        // Owner has all permissions
        $owner->givePermissionTo(Permission::all());

        // Admin has all except billing
        $admin->givePermissionTo([
            'view brand',
            'upload asset',
            'view private category',
            'approve asset',
            'manage categories',
            'manage brands',
            'manage users',
        ]);

        // Contributor can upload and view
        $contributor->givePermissionTo([
            'view brand',
            'upload asset',
            'view private category',
        ]);

        // Viewer can only view
        $viewer->givePermissionTo([
            'view brand',
        ]);
    }
}

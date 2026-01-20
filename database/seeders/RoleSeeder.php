<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Note: Roles are tenant-scoped, so they should be created per tenant
        // This seeder creates the role definitions that can be used per tenant
        // In a production system, you might want to create these roles per tenant in a separate seeder

        $roles = [
            [
                'name' => 'owner',
                'guard_name' => 'web',
            ],
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
            [
                'name' => 'manager',
                'guard_name' => 'web',
            ],
            [
                'name' => 'contributor',
                'guard_name' => 'web',
            ],
            [
                'name' => 'uploader',
                'guard_name' => 'web',
            ],
            [
                'name' => 'viewer',
                'guard_name' => 'web',
            ],
            [
                'name' => 'brand_manager',
                'guard_name' => 'web',
            ],
            [
                'name' => 'member',
                'guard_name' => 'web',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role['name'],
                'guard_name' => $role['guard_name'],
            ]);
        }
    }
}

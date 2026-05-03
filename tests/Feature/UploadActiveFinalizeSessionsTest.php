<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UploadActiveFinalizeSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_finalize_sessions_returns_stable_json_for_uploader(): void
    {
        Permission::firstOrCreate(['name' => 'asset.upload', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-active-finalize',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-active-finalize',
        ]);
        $user = User::create([
            'email' => 'uploader-active@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'U',
            'last_name' => 'P',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.upload');
        $user->assignRole($role);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/uploads/sessions/active');

        $response->assertOk();
        $response->assertJsonStructure(['feature_enabled', 'sessions', 'message']);
        $response->assertJsonPath('sessions', []);
    }

    public function test_finalize_session_status_returns_placeholder_payload(): void
    {
        Permission::firstOrCreate(['name' => 'asset.upload', 'guard_name' => 'web']);

        $tenant = Tenant::create([
            'name' => 'T2',
            'slug' => 't2-active-finalize',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B2',
            'slug' => 'b2-active-finalize',
        ]);
        $user = User::create([
            'email' => 'uploader-status@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'U',
            'last_name' => 'S',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.upload');
        $user->assignRole($role);

        $id = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson("/app/uploads/sessions/{$id}/status");

        $response->assertOk();
        $response->assertJsonPath('upload_session_id', $id);
        $response->assertJsonStructure(['feature_enabled', 'status', 'items', 'client_file_id_to_asset_id']);
    }
}

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

/**
 * Validates Gate 2 of the upload allowlist ladder: /uploads/initiate-batch.
 *
 * The historical "Retry bypasses preflight" exploit worked by re-POSTing to
 * initiate-batch without a preflight_id after a preflight rejection. The
 * fix added an unconditional FileTypeService::isUploadAllowed gate inside
 * the controller. These tests prove that gate runs and rejects with 422,
 * regardless of whether a preflight_id is present.
 */
class UploadInitiateBatchGateTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Brand $brand;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'asset.upload', 'guard_name' => 'web']);

        $this->tenant = Tenant::create([
            'name' => 'IB Tenant',
            'slug' => 'ib-tenant',
            'manual_plan_override' => 'enterprise',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'IB Brand',
            'slug' => 'ib-brand',
        ]);

        $this->user = User::create([
            'email' => 'ib@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'In',
            'last_name' => 'Batch',
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'owner']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.upload');
        $this->user->assignRole($role);
    }

    public function test_initiate_batch_blocks_exe_without_preflight_id(): void
    {
        // The "Retry" path: client never re-runs preflight, just hammers
        // initiate-batch with the same dangerous file. Gate must reject.
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/uploads/initiate-batch', [
                'brand_id' => $this->brand->id,
                'files' => [
                    [
                        'client_file_id' => (string) Str::uuid(),
                        'file_name' => 'malware.exe',
                        'file_size' => 2048,
                        'mime_type' => 'application/octet-stream',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'blocked_executable');
    }

    public function test_initiate_batch_blocks_double_extension_attack(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/uploads/initiate-batch', [
                'brand_id' => $this->brand->id,
                'files' => [
                    [
                        'client_file_id' => (string) Str::uuid(),
                        'file_name' => 'evil.php.jpg',
                        'file_size' => 1024,
                        'mime_type' => 'image/jpeg',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'blocked_double_extension');
    }

    public function test_initiate_batch_blocks_zip_archive(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/uploads/initiate-batch', [
                'brand_id' => $this->brand->id,
                'files' => [
                    [
                        'client_file_id' => (string) Str::uuid(),
                        'file_name' => 'collection.zip',
                        'file_size' => 5_000_000,
                        'mime_type' => 'application/zip',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'blocked_archive');
    }
}

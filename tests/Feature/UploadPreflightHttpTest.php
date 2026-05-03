<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UploadPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UploadPreflightHttpTest extends TestCase
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
            'name' => 'Preflight Tenant',
            'slug' => 'preflight-tenant',
            'manual_plan_override' => 'enterprise',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Preflight Brand',
            'slug' => 'preflight-brand',
        ]);

        $this->user = User::create([
            'email' => 'uploader@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Up',
            'last_name' => 'Loader',
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'owner']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('asset.upload');
        $this->user->assignRole($role);
    }

    public function test_preflight_accepts_jpeg_and_rejects_exe(): void
    {
        $idOk = (string) Str::uuid();
        $idBad = (string) Str::uuid();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/uploads/preflight', [
                'brand_id' => $this->brand->id,
                'files' => [
                    [
                        'client_file_id' => $idOk,
                        'name' => 'photo.jpg',
                        'size' => 1024,
                        'mime_type' => 'image/jpeg',
                        'extension' => 'jpg',
                    ],
                    [
                        'client_file_id' => $idBad,
                        'name' => 'malware.exe',
                        'size' => 2048,
                        'mime_type' => 'application/octet-stream',
                        'extension' => 'exe',
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('batch_summary.accepted_count', 1);
        $response->assertJsonPath('batch_summary.rejected_count', 1);
        $response->assertJsonPath('batch_summary.rejected_counts_by_code.dangerous_file_type', 1);
        $this->assertNotEmpty($response->json('preflight_id'));

        $payload = app(UploadPreflightService::class)->getCachedPayload($response->json('preflight_id'));
        $this->assertIsArray($payload);
        $this->assertArrayHasKey($idOk, $payload['accepted']);
        $this->assertSame('photo.jpg', $payload['accepted'][$idOk]['file_name']);
    }

    public function test_preflight_requires_upload_permission(): void
    {
        // Tenant owner/admin get asset.upload from PermissionMap; viewers do not.
        $this->user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'viewer']);
        $this->user->refresh();

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson('/app/uploads/preflight', [
                'brand_id' => $this->brand->id,
                'files' => [
                    [
                        'client_file_id' => (string) Str::uuid(),
                        'name' => 'x.jpg',
                        'size' => 10,
                        'mime_type' => 'image/jpeg',
                        'extension' => 'jpg',
                    ],
                ],
            ]);

        $response->assertStatus(403);
    }
}

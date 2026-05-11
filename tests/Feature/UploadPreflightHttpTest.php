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

        // Tenant::created() auto-creates a default brand. Reuse it instead of
        // creating a second one — otherwise a free-plan max_brands=1 test would
        // mark this brand as plan-disabled (it would sort after the default).
        $this->brand = $this->tenant->brands()->where('is_default', true)->firstOrFail();

        $this->user = User::create([
            'email' => 'uploader@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Up',
            'last_name' => 'Loader',
        ]);
        $this->user->forceFill(['email_verified_at' => now()])->save();
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
        // FileTypeService::isUploadAllowed now emits structured codes like
        // `blocked_executable` (was `dangerous_file_type`) — see config/file_types.php → blocked.executable.code_suffix
        $response->assertJsonPath('batch_summary.rejected_counts_by_code.blocked_executable', 1);
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


    /**
     * Phase 6: oversized files surface a plan-limit upgrade payload so the UI
     * can render an "upgrade for bigger uploads" nudge instead of just a generic error.
     *
     * Driven through {@see UploadPreflightService::evaluate()} directly (not HTTP) — the
     * intent is to verify the rejection payload shape, not the route stack. The route stack
     * is exercised by other tests in this file ({@see self::test_preflight_accepts_jpeg_and_rejects_exe()}).
     */
    public function test_preflight_evaluate_attaches_plan_limit_payload_for_oversized_file(): void
    {
        $this->tenant->update(['manual_plan_override' => 'free']);
        $this->tenant->refresh();

        $id = (string) Str::uuid();
        $tooLarge = 11 * 1024 * 1024;

        $payload = app(UploadPreflightService::class)->evaluate(
            $this->tenant,
            $this->user,
            $this->brand,
            null,
            null,
            [[
                'client_file_id' => $id,
                'name' => 'big.bin',
                'size' => $tooLarge,
                'mime_type' => 'application/octet-stream',
                'extension' => 'bin',
            ]],
            false,
        );

        $this->assertSame(1, $payload['batch_summary']['rejected_count']);
        $rejected = $payload['rejected'];
        $this->assertIsArray($rejected);
        $this->assertSame($id, $rejected[0]['client_file_id'] ?? null);

        $reasons = $rejected[0]['reasons'] ?? [];
        // The first reason should be the size limit (it is enforced before the file-type gate
        // so the user sees the upgrade nudge first; the unsupported-type reason follows).
        $sizeReason = collect($reasons)->firstWhere('code', 'file_size_limit');
        $this->assertNotNull($sizeReason, 'Expected a file_size_limit reason. Got: '.json_encode($reasons));

        $pl = $sizeReason['plan_limit'] ?? null;
        $this->assertIsArray($pl);
        $this->assertSame('plan_limit_exceeded', $pl['error_code'] ?? null);
        $this->assertSame('max_upload_size', $pl['limit_key'] ?? null);
        $this->assertSame('free', $pl['current_plan_key'] ?? null);
        $this->assertArrayHasKey('upgrade_url', $pl);
    }
}

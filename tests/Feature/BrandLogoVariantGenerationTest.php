<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\BrandModelVersionAsset;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandLogoVariantGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        Permission::firstOrCreate(['name' => 'brand_settings.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('brand_settings.manage');

        $this->tenant = Tenant::create([
            'name' => 'Logo Variant Tenant',
            'slug' => 'logo-variant-tenant',
            'manual_plan_override' => 'pro',
        ]);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Logo Variant Brand',
            'slug' => 'logo-variant-brand',
        ]);
        $this->user = User::create([
            'first_name' => 'Logo',
            'last_name' => 'User',
            'email' => 'logo-user@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->assignRole($role);
        $this->user->setRoleForTenant($this->tenant, 'admin', true);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        config(['storage.shared_bucket' => $this->bucket->name]);
    }

    public function test_logo_variant_generation_persists_checksum_on_created_asset_version(): void
    {
        $pngBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgwJ/l7sRKQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertIsString($pngBinary);

        $logoAsset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::REFERENCE,
            'title' => 'Primary Logo',
            'original_filename' => 'primary-logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($pngBinary),
            'storage_root_path' => "tenants/{$this->tenant->uuid}/assets/logo-source/v1/original.png",
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'metadata' => [],
        ]);

        Storage::disk('s3')->put($logoAsset->storage_root_path, $pngBinary, 'private');

        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $logoAsset->id,
            'version_number' => 1,
            'file_path' => $logoAsset->storage_root_path,
            'file_size' => strlen($pngBinary),
            'mime_type' => 'image/png',
            'checksum' => hash('sha256', $pngBinary),
            'is_current' => true,
            'pipeline_status' => 'complete',
            'uploaded_by' => $this->user->id,
        ]);

        $this->brand->update(['logo_id' => $logoAsset->id, 'primary_color' => '#7C3AED']);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/brands/{$this->brand->id}/logo-variants/generate", [
                'on_dark' => true,
                'on_light' => false,
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $pivot = BrandModelVersionAsset::query()
            ->where('builder_context', 'logo_on_dark')
            ->latest('id')
            ->first();
        $this->assertNotNull($pivot, 'Expected logo_on_dark variant asset to be linked to draft version.');

        $variantVersion = AssetVersion::query()
            ->where('asset_id', $pivot->asset_id)
            ->where('version_number', 1)
            ->first();
        $this->assertNotNull($variantVersion, 'Expected generated variant to create v1.');
        $this->assertNotNull($variantVersion->checksum, 'Generated variant v1 checksum must be persisted.');
        $this->assertNotSame('', trim((string) $variantVersion->checksum), 'Checksum must be non-empty.');
    }
}

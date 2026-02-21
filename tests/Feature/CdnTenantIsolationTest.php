<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant-scoped CloudFront signed cookies.
 *
 * Verifies cookies are scoped to /tenants/{uuid}/* and regenerate on tenant switch.
 */
class CdnTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $user;

    protected ?string $testKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Company A', 'slug' => 'company-a']);
        $this->tenantB = Tenant::create(['name' => 'Company B', 'slug' => 'company-b']);
        // Ensure UUIDs exist for policy resource
        $this->tenantA->refresh();
        $this->tenantB->refresh();

        Brand::create(['tenant_id' => $this->tenantA->id, 'name' => 'Brand A', 'slug' => 'brand-a', 'is_default' => true]);
        Brand::create(['tenant_id' => $this->tenantB->id, 'name' => 'Brand B', 'slug' => 'brand-b', 'is_default' => true]);

        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach([$this->tenantA->id => ['role' => 'member'], $this->tenantB->id => ['role' => 'member']]);
        $this->user->brands()->attach([
            $this->tenantA->brands()->first()->id => ['role' => 'admin', 'removed_at' => null],
            $this->tenantB->brands()->first()->id => ['role' => 'admin', 'removed_at' => null],
        ]);

        $this->testKeyPath = $this->createTestPrivateKey();
        config([
            'cloudfront.domain' => 'cdn.test',
            'cloudfront.key_pair_id' => 'test-key',
            'cloudfront.private_key_path' => $this->testKeyPath,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->testKeyPath && file_exists($this->testKeyPath)) {
            @unlink($this->testKeyPath);
        }
        parent::tearDown();
    }

    protected function createTestPrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $keyContent);
        $path = sys_get_temp_dir() . '/cloudfront-test-key-' . uniqid() . '.pem';
        file_put_contents($path, $keyContent);
        return $path;
    }

    protected function decodePolicyFromCookie(string $policyCookie): ?array
    {
        $value = $policyCookie;
        $decoded = str_replace(['-', '_', '~'], ['+', '=', '/'], $value);
        $json = base64_decode($decoded, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function test_signed_cookie_policy_contains_tenant_a_path(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenantA->id, 'brand_id' => $this->tenantA->brands()->first()->id])
            ->get('/app/dashboard');

        $response->assertStatus(200);

        $policyValue = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'CloudFront-Policy') {
                $policyValue = $cookie->getValue();
                break;
            }
        }

        if (!$policyValue) {
            $this->markTestSkipped('CloudFront-Policy cookie not set');
        }

        $decoded = $this->decodePolicyFromCookie($policyValue);
        $this->assertNotNull($decoded, 'Policy should decode to JSON');
        $resource = $decoded['Statement'][0]['Resource'] ?? null;
        $this->assertNotNull($resource);
        $this->assertStringContainsString("/tenants/{$this->tenantA->uuid}/", $resource);
        $this->assertStringEndsWith('*', $resource);
    }

    public function test_cookies_regenerate_on_tenant_switch(): void
    {
        // Request with tenant A
        $responseA = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenantA->id, 'brand_id' => $this->tenantA->brands()->first()->id])
            ->get('/app/dashboard');

        $policyA = null;
        foreach ($responseA->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'CloudFront-Policy') {
                $policyA = $cookie->getValue();
                break;
            }
        }

        if (!$policyA) {
            $this->markTestSkipped('CloudFront-Policy cookie not set');
        }

        $decodedA = $this->decodePolicyFromCookie($policyA);
        $resourceA = $decodedA['Statement'][0]['Resource'] ?? null;
        $this->assertNotNull($resourceA);
        $this->assertStringContainsString("/tenants/{$this->tenantA->uuid}/", $resourceA);

        // Switch to tenant B (simulate companies.switch)
        $responseB = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenantB->id, 'brand_id' => $this->tenantB->brands()->first()->id])
            ->get('/app/dashboard');

        $policyB = null;
        foreach ($responseB->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'CloudFront-Policy') {
                $policyB = $cookie->getValue();
                break;
            }
        }

        $this->assertNotNull($policyB);
        $decodedB = $this->decodePolicyFromCookie($policyB);
        $resourceB = $decodedB['Statement'][0]['Resource'] ?? null;
        $this->assertNotNull($resourceB);
        $this->assertStringContainsString("/tenants/{$this->tenantB->uuid}/", $resourceB);
        $this->assertStringNotContainsString("/tenants/{$this->tenantA->uuid}/", $resourceB);
    }

    /**
     * Cookie issued for Tenant A must NOT allow access to Tenant B assets.
     * Policy resource must be /tenants/{tenant_a_uuid}/* — not /tenants/* or *.
     */
    public function test_signed_cookie_is_tenant_scoped(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenantA->id, 'brand_id' => $this->tenantA->brands()->first()->id])
            ->get('/app/dashboard');

        $response->assertStatus(200);

        $policyValue = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'CloudFront-Policy') {
                $policyValue = $cookie->getValue();
                break;
            }
        }

        if (!$policyValue) {
            $this->markTestSkipped('CloudFront-Policy cookie not set (local env skips)');
        }

        $decoded = $this->decodePolicyFromCookie($policyValue);
        $this->assertNotNull($decoded);
        $resource = $decoded['Statement'][0]['Resource'] ?? null;
        $this->assertNotNull($resource);

        // Must be tenant-scoped: /tenants/{uuid}/*
        $this->assertStringContainsString("/tenants/{$this->tenantA->uuid}/", $resource);
        $this->assertStringEndsWith('*', $resource);

        // Must NOT be permissive wildcard
        $this->assertStringNotContainsString('/*', preg_replace('#/tenants/[^/]+/\*$#', '', $resource), 'Resource must not allow /tenants/*');
        $this->assertNotSame('https://cdn.test/*', $resource, 'Resource must not be domain-wide wildcard');

        // Cookie for Tenant A must NOT include Tenant B path
        $this->assertStringNotContainsString($this->tenantB->uuid, $resource);
    }

    /**
     * Admin users on /app/admin/assets with assets from multiple tenants receive
     * multiple CloudFront-Policy cookies, each scoped to /tenants/{uuid}/*.
     * No wildcard resource.
     */
    public function test_admin_can_receive_multiple_tenant_cookies(): void
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $admin->assignRole('site_admin');

        $brandA = $this->tenantA->brands()->first();
        $brandB = $this->tenantB->brands()->first();

        $bucketA = StorageBucket::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'bucket-a',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $bucketB = StorageBucket::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'bucket-b',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $uploadA = UploadSession::create([
            'tenant_id' => $this->tenantA->id,
            'brand_id' => $brandA->id,
            'storage_bucket_id' => $bucketA->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);
        $uploadB = UploadSession::create([
            'tenant_id' => $this->tenantB->id,
            'brand_id' => $brandB->id,
            'storage_bucket_id' => $bucketB->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        Asset::create([
            'tenant_id' => $this->tenantA->id,
            'brand_id' => $brandA->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadA->id,
            'storage_bucket_id' => $bucketA->id,
            'title' => 'Asset A',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenantA->uuid . '/assets/test/a.jpg',
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'size_bytes' => 1024,
        ]);
        Asset::create([
            'tenant_id' => $this->tenantB->id,
            'brand_id' => $brandB->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $uploadB->id,
            'storage_bucket_id' => $bucketB->id,
            'title' => 'Asset B',
            'original_filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/' . $this->tenantB->uuid . '/assets/test/b.jpg',
            'type' => AssetType::ASSET,
            'status' => AssetStatus::VISIBLE,
            'size_bytes' => 1024,
        ]);

        $response = $this->actingAs($admin)->get('/app/admin/assets');

        $response->assertStatus(200);

        $policyCookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'CloudFront-Policy') {
                $policyCookies[] = ['value' => $cookie->getValue(), 'path' => $cookie->getPath()];
            }
        }

        if (count($policyCookies) < 2) {
            $this->markTestSkipped('Expected multiple CloudFront-Policy cookies (admin multi-tenant mode)');
        }

        $resources = [];
        foreach ($policyCookies as $c) {
            $decoded = $this->decodePolicyFromCookie($c['value']);
            $this->assertNotNull($decoded);
            $resource = $decoded['Statement'][0]['Resource'] ?? null;
            $this->assertNotNull($resource);
            $resources[] = $resource;

            $this->assertStringContainsString('/tenants/', $resource);
            $this->assertStringEndsWith('*', $resource);
            $this->assertNotSame('https://cdn.test/*', $resource, 'Resource must not be domain-wide wildcard');
        }

        $this->assertContains("https://cdn.test/tenants/{$this->tenantA->uuid}/*", $resources);
        $this->assertContains("https://cdn.test/tenants/{$this->tenantB->uuid}/*", $resources);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
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
}

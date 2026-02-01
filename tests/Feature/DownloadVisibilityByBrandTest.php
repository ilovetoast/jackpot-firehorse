<?php

namespace Tests\Feature;

use App\Enums\DownloadAccessMode;
use App\Enums\DownloadStatus;
use App\Enums\ZipStatus;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\CollectionUser;
use App\Models\Download;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Phase D12.2 — Downloads visibility and brand scoping.
 *
 * - Brand manager cannot see other brands' downloads
 * - Tenant admin sees all brands' downloads
 * - Contributor sees only own downloads
 * - Collection-only user cannot access downloads index
 */
class DownloadVisibilityByBrandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brandA;
    protected Brand $brandB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->brandA = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand A',
            'slug' => 'brand-a',
            'is_default' => true,
        ]);
        $this->brandB = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Brand B',
            'slug' => 'brand-b',
            'is_default' => false,
        ]);
    }

    protected function createDownload(int $tenantId, int $brandId, int $createdByUserId): Download
    {
        $d = Download::create([
            'tenant_id' => $tenantId,
            'brand_id' => $brandId,
            'created_by_user_id' => $createdByUserId,
            'download_type' => 'snapshot',
            'source' => 'grid',
            'slug' => 'slug-' . uniqid(),
            'version' => 1,
            'status' => DownloadStatus::READY,
            'zip_status' => ZipStatus::READY,
            'zip_path' => 'downloads/test.zip',
            'expires_at' => now()->addDays(30),
            'hard_delete_at' => now()->addDays(37),
            'access_mode' => DownloadAccessMode::PUBLIC,
            'allow_reshare' => true,
        ]);
        return $d;
    }

    public function test_brand_manager_cannot_see_other_brands_downloads(): void
    {
        $brandManager = User::create([
            'email' => 'bm@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Brand',
            'last_name' => 'Manager',
        ]);
        $brandManager->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $brandManager->brands()->attach($this->brandA->id, ['role' => 'brand_manager', 'removed_at' => null]);

        $otherUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $otherUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $otherUser->brands()->attach($this->brandA->id, ['role' => 'contributor', 'removed_at' => null]);
        $otherUser->brands()->attach($this->brandB->id, ['role' => 'contributor', 'removed_at' => null]);

        $downloadBrandA = $this->createDownload($this->tenant->id, $this->brandA->id, $otherUser->id);
        $downloadBrandB = $this->createDownload($this->tenant->id, $this->brandB->id, $otherUser->id);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brandA->id);
        $this->actingAs($brandManager);

        $response = $this->get(route('downloads.index', ['scope' => 'all']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Downloads/Index')->has('downloads'));
        $downloads = $response->inertiaPage()['props']['downloads'] ?? [];
        $ids = array_column($downloads, 'id');
        $this->assertContains($downloadBrandA->id, $ids, 'Brand manager should see Brand A download');
        $this->assertNotContains($downloadBrandB->id, $ids, 'Brand manager should not see Brand B download');
    }

    public function test_tenant_admin_sees_all_brands_downloads(): void
    {
        $admin = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $admin->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $admin->brands()->attach($this->brandA->id, ['role' => 'admin', 'removed_at' => null]);

        $otherUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $otherUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $otherUser->brands()->attach($this->brandA->id, ['role' => 'contributor', 'removed_at' => null]);
        $otherUser->brands()->attach($this->brandB->id, ['role' => 'contributor', 'removed_at' => null]);

        $downloadBrandA = $this->createDownload($this->tenant->id, $this->brandA->id, $otherUser->id);
        $downloadBrandB = $this->createDownload($this->tenant->id, $this->brandB->id, $otherUser->id);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brandA->id);
        $this->actingAs($admin);

        $response = $this->get(route('downloads.index', ['scope' => 'all']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Downloads/Index')->has('downloads'));
        $downloads = $response->inertiaPage()['props']['downloads'] ?? [];
        $ids = array_column($downloads, 'id');
        $this->assertContains($downloadBrandA->id, $ids);
        $this->assertContains($downloadBrandB->id, $ids);
    }

    public function test_contributor_sees_only_own_downloads(): void
    {
        $contributor = User::create([
            'email' => 'contrib@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Contrib',
            'last_name' => 'User',
        ]);
        $contributor->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $contributor->brands()->attach($this->brandA->id, ['role' => 'contributor', 'removed_at' => null]);

        $otherUser = User::create([
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'User',
        ]);
        $otherUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        $otherUser->brands()->attach($this->brandA->id, ['role' => 'brand_manager', 'removed_at' => null]);

        $downloadByContributor = $this->createDownload($this->tenant->id, $this->brandA->id, $contributor->id);
        $downloadByOther = $this->createDownload($this->tenant->id, $this->brandA->id, $otherUser->id);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('brand_id', $this->brandA->id);
        $this->actingAs($contributor);

        $response = $this->get(route('downloads.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Downloads/Index')->has('downloads'));
        $downloads = $response->inertiaPage()['props']['downloads'] ?? [];
        $ids = array_column($downloads, 'id');
        $this->assertContains($downloadByContributor->id, $ids, 'Contributor should see own download');
        $this->assertNotContains($downloadByOther->id, $ids, 'Contributor should not see other user download');
    }

    public function test_collection_only_user_cannot_access_downloads_index(): void
    {
        $collectionOnlyUser = User::create([
            'email' => 'collection@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Collection',
            'last_name' => 'User',
        ]);
        $collectionOnlyUser->tenants()->attach($this->tenant->id, ['role' => 'member']);
        // No brand_user — collection-only

        $collection = Collection::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brandA->id,
            'name' => 'Private Col',
            'slug' => 'private-col',
            'visibility' => 'private',
            'is_public' => false,
            'created_by' => null,
        ]);

        CollectionUser::create([
            'user_id' => $collectionOnlyUser->id,
            'collection_id' => $collection->id,
            'invited_by_user_id' => null,
            'accepted_at' => now(),
        ]);

        Session::put('tenant_id', $this->tenant->id);
        Session::put('collection_id', $collection->id);
        // No brand_id — triggers collection-only in ResolveTenant
        $this->actingAs($collectionOnlyUser);

        $response = $this->get(route('downloads.index'));

        $this->assertTrue(
            $response->isRedirect() || $response->getStatusCode() === 403,
            'Collection-only user must get redirect or 403'
        );
        if ($response->isRedirect()) {
            $this->assertStringContainsString(
                (string) $collection->id,
                $response->headers->get('Location'),
                'Redirect should target collection landing'
            );
        }
    }
}

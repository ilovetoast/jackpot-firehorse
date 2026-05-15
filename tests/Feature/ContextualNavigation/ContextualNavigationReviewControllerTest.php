<?php

namespace Tests\Feature\ContextualNavigation;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class ContextualNavigationReviewControllerTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: \App\Models\Brand, 2: User} */
    private function bootstrap(string $slug, bool $withPermission = true): array
    {
        Permission::findOrCreate('metadata.tenant.visibility.manage', 'web');
        Permission::findOrCreate('metadata.suggestions.view', 'web');
        Permission::findOrCreate('metadata.suggestions.apply', 'web');

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin([
            'name' => 'CN '.$slug,
            'slug' => 'cn-'.$slug.'-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], [
            'email' => 'cn-'.$slug.'@example.com',
            'first_name' => 'C', 'last_name' => 'N',
        ]);
        if ($withPermission) {
            $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            $role->givePermissionTo([
                'metadata.tenant.visibility.manage',
                'metadata.suggestions.view',
                'metadata.suggestions.apply',
            ]);
            $user->assignRole($role);
            $user->setRoleForTenant($tenant, 'admin');
        }

        return [$tenant, $brand, $user];
    }

    private function setupFolderField(Tenant $tenant, $brand): array
    {
        $folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'F',
            'slug' => 'f-'.Str::random(4),
            'is_system' => false, 'is_locked' => false, 'is_private' => false,
            'is_hidden' => false, 'sort_order' => 1, 'requires_approval' => false,
        ]);
        $fid = DB::table('metadata_fields')->insertGetId([
            'key' => 'env_'.Str::random(4), 'system_label' => 'Env',
            'type' => 'select', 'applies_to' => 'all', 'scope' => 'system',
            'is_filterable' => true, 'is_user_editable' => true,
            'is_ai_trainable' => false, 'is_upload_visible' => true,
            'is_internal_only' => false, 'group_key' => 'general',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$folder, MetadataField::query()->findOrFail($fid)];
    }

    private function pendingRec(Tenant $tenant, $brand, Category $folder, MetadataField $field, string $type): ContextualNavigationRecommendation
    {
        return ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => $type,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.75,
            'last_seen_at' => now(),
        ]);
    }

    public function test_list_returns_pending_rows_for_tenant(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('list');
        [$folder, $field] = $this->setupFolderField($tenant, $brand);
        $rec = $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER);

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/app/api/ai/review?type=contextual');

        $resp->assertOk();
        $items = $resp->json('items');
        $this->assertIsArray($items);
        $this->assertGreaterThanOrEqual(1, count($items));
        $this->assertSame($rec->id, $items[0]['id']);
        $this->assertSame('suggest_quick_filter', $items[0]['recommendation_type']);
        $this->assertTrue($items[0]['is_actionable']);
    }

    public function test_approve_endpoint_routes_to_assignment_service(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('approve');
        [$folder, $field] = $this->setupFolderField($tenant, $brand);
        $rec = $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER);

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/app/api/ai/review/contextual/{$rec->id}/approve", ['notes' => 'ship it']);

        $resp->assertOk();
        $rec->refresh();
        $this->assertSame('accepted', $rec->status);
        $this->assertSame('ship it', $rec->reviewer_notes);

        $vis = MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->first();
        $this->assertNotNull($vis);
        $this->assertTrue((bool) $vis->show_in_folder_quick_filters);
    }

    public function test_reject_endpoint_marks_status_only(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('reject');
        [$folder, $field] = $this->setupFolderField($tenant, $brand);
        $rec = $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/app/api/ai/review/contextual/{$rec->id}/reject")
            ->assertOk();

        $this->assertSame('rejected', $rec->fresh()->status);
        $this->assertSame(0, MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->count());
    }

    public function test_run_endpoint_dispatches_job_for_admins(): void
    {
        Queue::fake();
        [$tenant, $brand, $user] = $this->bootstrap('run');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson('/app/api/ai/review/contextual/run', ['force' => true])
            ->assertOk()
            ->assertJsonFragment(['ok' => true]);

        Queue::assertPushed(\App\Jobs\RunContextualNavigationInsightsJob::class);
    }

    public function test_endpoints_require_manage_permission(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('no-perms', withPermission: false);
        // Strip elevated tenant + brand roles. createActivatedTenantBrandAdmin
        // attaches admin on both pivots, which trivially passes
        // metadata.suggestions.apply via the hasForBrand path. We need the
        // user to have neither metadata.tenant.visibility.manage nor
        // metadata.suggestions.apply.
        $user->setRoleForTenant($tenant, 'member');
        $user->setRoleForBrand($brand, 'viewer');
        [$folder, $field] = $this->setupFolderField($tenant, $brand);
        $rec = $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/app/api/ai/review/contextual/{$rec->id}/approve")
            ->assertStatus(403);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson('/app/api/ai/review/contextual/run')
            ->assertStatus(403);
    }

    public function test_list_includes_contextual_count_in_review_counts(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('counts');
        [$folder, $field] = $this->setupFolderField($tenant, $brand);
        $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER);
        $this->pendingRec($tenant, $brand, $folder, $field, ContextualNavigationRecommendation::TYPE_SUGGEST_PIN);

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/app/api/ai/review/counts');

        $resp->assertOk();
        $this->assertSame(2, $resp->json('contextual'));
    }
}

<?php

namespace Tests\Feature\ContextualNavigation;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Services\ContextualNavigation\ContextualNavigationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 6 — approval routes through FolderQuickFilterAssignmentService;
 * we verify the visibility row reaches the expected end state.
 */
class ContextualNavigationApprovalServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private function context(): array
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Approve Co',
            'slug' => 'approve-co-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], ['email' => 'approve@example.com', 'first_name' => 'A', 'last_name' => 'P']);

        $folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Folder',
            'slug' => 'folder-'.Str::random(4),
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'requires_approval' => false,
        ]);
        $fid = DB::table('metadata_fields')->insertGetId([
            'key' => 'env_'.Str::random(4),
            'system_label' => 'Env',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $field = MetadataField::query()->findOrFail($fid);

        return [$tenant, $brand, $user, $folder, $field];
    }

    public function test_approve_suggest_quick_filter_enables_visibility_row(): void
    {
        [$tenant, $brand, $user, $folder, $field] = $this->context();
        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.75,
        ]);

        $service = app(ContextualNavigationApprovalService::class);
        $service->approve($rec, $tenant, $user, 'Looks right');

        $vis = MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->first();
        $this->assertNotNull($vis);
        $this->assertTrue((bool) $vis->show_in_folder_quick_filters);
        $this->assertSame('ai_suggested', $vis->folder_quick_filter_source);

        $rec->refresh();
        $this->assertSame('accepted', $rec->status);
        $this->assertSame($user->id, (int) $rec->reviewed_by_user_id);
    }

    public function test_approve_suggest_pin_marks_pinned_state(): void
    {
        [$tenant, $brand, $user, $folder, $field] = $this->context();
        // Already enabled, not pinned
        MetadataFieldVisibility::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'metadata_field_id' => $field->id,
            'category_id' => $folder->id,
            'show_in_folder_quick_filters' => true,
            'is_pinned_folder_quick_filter' => false,
            'folder_quick_filter_order' => 1,
            'folder_quick_filter_weight' => 0,
            'folder_quick_filter_source' => 'manual',
        ]);
        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_PIN,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.85,
        ]);

        app(ContextualNavigationApprovalService::class)->approve($rec, $tenant, $user);

        $vis = MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->first();
        $this->assertTrue((bool) $vis->is_pinned_folder_quick_filter);
    }

    public function test_reject_marks_status_without_mutating_visibility(): void
    {
        [$tenant, $brand, $user, $folder, $field] = $this->context();
        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.75,
        ]);

        app(ContextualNavigationApprovalService::class)->reject($rec, $tenant, $user, 'Not relevant');

        $rec->refresh();
        $this->assertSame('rejected', $rec->status);
        $this->assertSame('Not relevant', $rec->reviewer_notes);
        // Visibility row must NOT exist — reject is non-mutative.
        $this->assertSame(0, MetadataFieldVisibility::query()
            ->where('category_id', $folder->id)
            ->where('metadata_field_id', $field->id)
            ->count());
    }

    public function test_warning_recommendation_cannot_be_approved(): void
    {
        [$tenant, $brand, $user, $folder, $field] = $this->context();
        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_WARN_HIGH_CARDINALITY,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.75,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(ContextualNavigationApprovalService::class)->approve($rec, $tenant, $user);
    }

    public function test_already_finalised_recommendation_cannot_be_re_acted(): void
    {
        [$tenant, $brand, $user, $folder, $field] = $this->context();
        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
            'source' => 'statistical',
            'status' => 'rejected',
            'score' => 0.75,
        ]);
        $this->expectException(InvalidArgumentException::class);
        app(ContextualNavigationApprovalService::class)->approve($rec, $tenant, $user);
    }
}

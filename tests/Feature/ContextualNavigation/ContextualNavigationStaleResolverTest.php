<?php

namespace Tests\Feature\ContextualNavigation;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Services\ContextualNavigation\ContextualNavigationStaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

class ContextualNavigationStaleResolverTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private function context(): array
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Stale Co',
            'slug' => 'stale-co-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], ['email' => 'stale@example.com', 'first_name' => 'S', 'last_name' => 'C']);
        $folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'F',
            'slug' => 'f-'.Str::random(4),
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
            'applies_to' => 'all', 'scope' => 'system',
            'is_filterable' => true, 'is_user_editable' => true,
            'is_ai_trainable' => false, 'is_upload_visible' => true,
            'is_internal_only' => false, 'group_key' => 'general',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$tenant, $brand, $folder, MetadataField::query()->findOrFail($fid)];
    }

    public function test_marks_already_applied_suggestions_stale(): void
    {
        [$tenant, $brand, $folder, $field] = $this->context();
        // State already matches the recommendation: filter is enabled.
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
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.75,
            'last_seen_at' => now(),
        ]);

        app(ContextualNavigationStaleResolver::class)->resolveForTenant($tenant);

        $rec->refresh();
        $this->assertSame('stale', $rec->status);
    }

    public function test_marks_ttl_expired_pending_rows_stale(): void
    {
        [$tenant, $brand, $folder, $field] = $this->context();
        config(['contextual_navigation_insights.recommendation_ttl_days' => 1]);

        $rec = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'category_id' => $folder->id,
            'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_WARN_HIGH_CARDINALITY,
            'source' => 'statistical',
            'status' => 'pending',
            'score' => 0.6,
            'last_seen_at' => now()->subDays(10),
        ]);

        app(ContextualNavigationStaleResolver::class)->resolveForTenant($tenant);

        $rec->refresh();
        $this->assertSame('stale', $rec->status);
    }

    public function test_does_not_touch_accepted_or_rejected_rows(): void
    {
        [$tenant, $brand, $folder, $field] = $this->context();
        config(['contextual_navigation_insights.recommendation_ttl_days' => 1]);

        $accepted = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id, 'brand_id' => $brand->id,
            'category_id' => $folder->id, 'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER,
            'source' => 'statistical', 'status' => 'accepted',
            'score' => 0.75, 'last_seen_at' => now()->subDays(99),
        ]);
        $rejected = ContextualNavigationRecommendation::create([
            'tenant_id' => $tenant->id, 'brand_id' => $brand->id,
            'category_id' => $folder->id, 'metadata_field_id' => $field->id,
            'recommendation_type' => ContextualNavigationRecommendation::TYPE_SUGGEST_PIN,
            'source' => 'statistical', 'status' => 'rejected',
            'score' => 0.75, 'last_seen_at' => now()->subDays(99),
        ]);

        app(ContextualNavigationStaleResolver::class)->resolveForTenant($tenant);

        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
    }
}

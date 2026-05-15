<?php

namespace Tests\Feature\ContextualNavigation;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\MetadataFieldVisibility;
use App\Models\Tenant;
use App\Services\ContextualNavigation\ContextualNavigationRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 6 — full recommender pipeline tests with seeded assets so we
 * exercise the SQL counters too.
 */
class ContextualNavigationRecommenderTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private Tenant $tenant;
    private $brand;
    private Category $folder;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'contextual_navigation_insights.min_assets_per_folder' => 4,
            'contextual_navigation_insights.min_distinct_values_per_field' => 2,
            'contextual_navigation_insights.score_thresholds.suggest_quick_filter' => 0.4,
            'contextual_navigation_insights.score_thresholds.suggest_disable_quick_filter' => 0.5,
        ]);

        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Reco Co',
            'slug' => 'reco-co-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], ['email' => 'reco@example.com', 'first_name' => 'R', 'last_name' => 'C']);
        $this->tenant = $tenant;
        $this->brand = $brand;
        $this->app->instance('tenant', $tenant);
        $this->folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography-'.Str::random(4),
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'requires_approval' => false,
        ]);
    }

    private function makeField(string $key, string $type = 'select', array $opts = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
            'key' => $key,
            'system_label' => $key,
            'type' => $type,
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
        ], $opts));

        return MetadataField::query()->findOrFail($id);
    }

    private function makeAsset(array $fieldValueMap): string
    {
        $assetId = (string) Str::uuid();
        DB::table('assets')->insert([
            'id' => $assetId,
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => null,
            'upload_session_id' => null,
            'storage_bucket_id' => null,
            'status' => AssetStatus::VISIBLE->value,
            'thumbnail_status' => ThumbnailStatus::PENDING->value,
            'analysis_status' => 'pending',
            'type' => AssetType::ASSET->value,
            'original_filename' => 'a.jpg',
            'title' => 'A',
            'size_bytes' => 3,
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/'.$this->tenant->uuid.'/assets/'.$assetId.'/v1',
            'metadata' => json_encode(['category_id' => (string) $this->folder->id]),
            'created_at' => now(),
            'updated_at' => now(),
            'intake_state' => 'normal',
        ]);
        foreach ($fieldValueMap as $fieldId => $value) {
            DB::table('asset_metadata')->insert([
                'asset_id' => $assetId,
                'metadata_field_id' => $fieldId,
                'value_json' => json_encode($value),
                'source' => 'user',
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $assetId;
    }

    public function test_strong_filter_emits_suggest_quick_filter_recommendation(): void
    {
        $field = $this->makeField('environment_select');
        // 6 assets, 4 with values across 3 distinct values → high coverage,
        // healthy reuse, low cardinality.
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'outdoor']);
        $this->makeAsset([$field->id => 'lifestyle']);
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'outdoor']);

        $written = app(ContextualNavigationRecommender::class)->run($this->tenant);

        $this->assertGreaterThan(0, $written['written']);
        $rec = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('recommendation_type', ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->first();
        $this->assertNotNull($rec, 'suggest_quick_filter row should be present');
        $this->assertSame(ContextualNavigationRecommendation::SOURCE_STATISTICAL, $rec->source);
        $this->assertGreaterThan(0.4, (float) $rec->score);
        $this->assertNotNull($rec->reason_summary);
    }

    public function test_pinned_underperforming_filter_emits_suggest_unpin(): void
    {
        $field = $this->makeField('rights_select');
        // Same number of assets but field is on every asset with same value.
        // → coverage 100%, distinct=1 → narrowing power tanks.
        $this->makeAsset([$field->id => 'public']);
        $this->makeAsset([$field->id => 'public']);
        $this->makeAsset([$field->id => 'public']);
        $this->makeAsset([$field->id => 'public']);
        $this->makeAsset([$field->id => 'public']);

        // Pre-mark as enabled + pinned in the visibility table.
        MetadataFieldVisibility::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'metadata_field_id' => $field->id,
            'category_id' => $this->folder->id,
            'show_in_folder_quick_filters' => true,
            'is_pinned_folder_quick_filter' => true,
            'folder_quick_filter_order' => 1,
            'folder_quick_filter_weight' => 0,
            'folder_quick_filter_source' => 'manual',
        ]);

        // Force min_distinct=1 so the recommender doesn't skip this field.
        config(['contextual_navigation_insights.min_distinct_values_per_field' => 1]);

        app(ContextualNavigationRecommender::class)->run($this->tenant);

        $rec = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('recommendation_type', ContextualNavigationRecommendation::TYPE_SUGGEST_UNPIN)
            ->first();
        $this->assertNotNull($rec, 'A pinned-but-weak filter should produce SUGGEST_UNPIN');
    }

    public function test_already_enabled_filter_does_not_emit_suggest_enable(): void
    {
        $field = $this->makeField('subject_select');
        $this->makeAsset([$field->id => 'people']);
        $this->makeAsset([$field->id => 'product']);
        $this->makeAsset([$field->id => 'people']);
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'people']);
        $this->makeAsset([$field->id => 'product']);

        // Already enabled.
        MetadataFieldVisibility::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'metadata_field_id' => $field->id,
            'category_id' => $this->folder->id,
            'show_in_folder_quick_filters' => true,
            'is_pinned_folder_quick_filter' => false,
            'folder_quick_filter_order' => 1,
            'folder_quick_filter_weight' => 0,
            'folder_quick_filter_source' => 'manual',
        ]);

        app(ContextualNavigationRecommender::class)->run($this->tenant);

        $this->assertSame(0, ContextualNavigationRecommendation::query()
            ->where('metadata_field_id', $field->id)
            ->where('recommendation_type', ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER)
            ->count());
    }

    public function test_rerunning_does_not_create_duplicate_pending_rows(): void
    {
        $field = $this->makeField('environment_select');
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'outdoor']);
        $this->makeAsset([$field->id => 'lifestyle']);
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'outdoor']);

        $rec = app(ContextualNavigationRecommender::class);
        $rec->run($this->tenant);
        $rec->run($this->tenant);

        $this->assertSame(1, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('recommendation_type', ContextualNavigationRecommendation::TYPE_SUGGEST_QUICK_FILTER)
            ->where('status', ContextualNavigationRecommendation::STATUS_PENDING)
            ->count());
    }

    public function test_skips_folder_below_min_assets(): void
    {
        config(['contextual_navigation_insights.min_assets_per_folder' => 100]);
        $field = $this->makeField('environment_select');
        $this->makeAsset([$field->id => 'studio']);
        $this->makeAsset([$field->id => 'outdoor']);

        $result = app(ContextualNavigationRecommender::class)->run($this->tenant);
        $this->assertSame(0, $result['written']);
        $this->assertGreaterThan(0, $result['skipped_folders']);
    }
}

<?php

namespace Tests\Feature\ContextualNavigation;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\RunContextualNavigationInsightsJob;
use App\Models\AIAgentRun;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 6 — job-level integration tests.
 *
 * Covers gate plumbing + that statistical-only runs do NOT debit credits or
 * record AIAgentRun rows. AI-on path is exercised separately to keep the
 * fixture bare in this file.
 */
class RunContextualNavigationInsightsJobTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'contextual_navigation_insights.enabled' => true,
            'contextual_navigation_insights.use_ai_reasoning' => false,
            'contextual_navigation_insights.min_assets_per_tenant' => 0,
            'contextual_navigation_insights.min_assets_per_folder' => 4,
            'contextual_navigation_insights.min_distinct_values_per_field' => 2,
            'contextual_navigation_insights.run_cooldown_hours' => 0,
            'contextual_navigation_insights.score_thresholds.suggest_quick_filter' => 0.4,
        ]);
    }

    private function setupTenantWithAssets(): array
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Job Co',
            'slug' => 'job-co-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], ['email' => 'job@example.com', 'first_name' => 'J', 'last_name' => 'C']);
        $tenant->ai_insights_enabled = true;
        $tenant->save();

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
        $field = MetadataField::query()->findOrFail($fid);

        // 6 assets, 3 distinct values
        foreach (['studio', 'studio', 'outdoor', 'lifestyle', 'studio', 'outdoor'] as $val) {
            $assetId = (string) Str::uuid();
            DB::table('assets')->insert([
                'id' => $assetId, 'tenant_id' => $tenant->id, 'brand_id' => $brand->id,
                'user_id' => null, 'upload_session_id' => null, 'storage_bucket_id' => null,
                'status' => AssetStatus::VISIBLE->value,
                'thumbnail_status' => ThumbnailStatus::PENDING->value,
                'analysis_status' => 'pending', 'type' => AssetType::ASSET->value,
                'original_filename' => 'a.jpg', 'title' => 'A', 'size_bytes' => 3,
                'mime_type' => 'image/jpeg',
                'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.$assetId.'/v1',
                'metadata' => json_encode(['category_id' => (string) $folder->id]),
                'created_at' => now(), 'updated_at' => now(), 'intake_state' => 'normal',
            ]);
            DB::table('asset_metadata')->insert([
                'asset_id' => $assetId, 'metadata_field_id' => $field->id,
                'value_json' => json_encode($val),
                'source' => 'user', 'approved_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$tenant, $brand, $folder, $field];
    }

    public function test_skipped_when_master_ai_disabled(): void
    {
        [$tenant] = $this->setupTenantWithAssets();
        $tenant->settings = ['ai_enabled' => false];
        $tenant->save();

        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );

        $this->assertSame(0, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->count());
    }

    public function test_skipped_when_ai_insights_disabled(): void
    {
        [$tenant] = $this->setupTenantWithAssets();
        $tenant->ai_insights_enabled = false;
        $tenant->save();

        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );

        $this->assertSame(0, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count());
    }

    public function test_statistical_only_run_writes_recommendations_without_charging_credits(): void
    {
        [$tenant] = $this->setupTenantWithAssets();
        $beforeAgentRuns = AIAgentRun::query()->where('tenant_id', $tenant->id)->count();
        $beforeUsage = (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', 'contextual_navigation')
            ->sum('call_count');

        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );

        $this->assertGreaterThan(0, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count());

        // NO AI debit, NO AIAgentRun row when use_ai_reasoning=false.
        $afterAgentRuns = AIAgentRun::query()->where('tenant_id', $tenant->id)->count();
        $afterUsage = (int) DB::table('ai_usage')
            ->where('tenant_id', $tenant->id)
            ->where('feature', 'contextual_navigation')
            ->sum('call_count');
        $this->assertSame($beforeAgentRuns, $afterAgentRuns);
        $this->assertSame($beforeUsage, $afterUsage);
    }

    public function test_cooldown_short_circuits_subsequent_runs(): void
    {
        [$tenant] = $this->setupTenantWithAssets();
        config(['contextual_navigation_insights.run_cooldown_hours' => 24]);

        // First run lands recommendations.
        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );
        $afterFirst = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count();
        $this->assertGreaterThan(0, $afterFirst);

        // Second run within cooldown does NOT change row counts because the
        // recommender is short-circuited before it has a chance to refresh.
        // (We can't easily distinguish "skipped" from "refreshed in place"
        // by row count alone, so we delete one recommendation and confirm
        // it isn't recreated under cooldown.)
        ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->limit(1)
            ->delete();
        $expectedAfterDelete = $afterFirst - 1;

        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );

        $this->assertSame($expectedAfterDelete, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count(),
            'Cooldown should prevent re-running and recreating the deleted row.');
    }

    public function test_force_bypass_cooldown_re_runs_recommender(): void
    {
        [$tenant] = $this->setupTenantWithAssets();
        config(['contextual_navigation_insights.run_cooldown_hours' => 24]);

        (new RunContextualNavigationInsightsJob($tenant->id))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );
        $afterFirst = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count();
        ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->limit(1)
            ->delete();

        // forceBypassCooldown=true should re-run the recommender and
        // recreate the deleted recommendation row.
        (new RunContextualNavigationInsightsJob($tenant->id, forceBypassCooldown: true))->handle(
            app(\App\Services\ContextualNavigation\ContextualNavigationRecommender::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationStaleResolver::class),
            app(\App\Services\ContextualNavigation\ContextualNavigationAiReasoner::class),
        );

        $this->assertSame($afterFirst, ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)->count());
    }
}

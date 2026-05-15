<?php

namespace Tests\Feature\ContextualNavigation;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\ContextualNavigation\ContextualNavigationScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 6 — pure-statistical scoring tests.
 *
 * These tests exercise computeFromCounters directly so we never have to
 * stand up assets/metadata for scoring math. The DB-touching helpers
 * (countFolderAssets / countAssetsWithFieldPopulated /
 * countDistinctValuesInFolder) get coverage in
 * ContextualNavigationRecommenderTest.
 */
class ContextualNavigationScoringServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private ContextualNavigationScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = app(ContextualNavigationScoringService::class);
    }

    private function field(array $overrides = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
            'key' => 'env_'.Str::random(6),
            'system_label' => 'Environment',
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
        ], $overrides));

        return MetadataField::query()->findOrFail($id);
    }

    public function test_high_coverage_low_cardinality_filter_scores_high(): void
    {
        $field = $this->field();
        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 95,
            distinctValues: 4,
            field: $field,
            aliasCount: 0,
            duplicateCandidateCount: 0,
        );

        $this->assertGreaterThan(0.7, $scores['overall'], 'Overall should be strong for clean filter');
        $this->assertGreaterThan(0.9, $scores['coverage']);
        $this->assertGreaterThan(0.5, $scores['narrowing_power']);
        $this->assertGreaterThan(0.7, $scores['reuse_consistency']);
        $this->assertSame(95, $scores['counters']['coverage_count']);
    }

    public function test_high_cardinality_field_collapses_cardinality_penalty(): void
    {
        $field = $this->field();
        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 80,
            distinctValues: 5_000, // tag-soup
            field: $field,
        );

        // Past the cap by orders of magnitude: cardinality must be at the floor (0.1) or below.
        $this->assertLessThanOrEqual(0.1, $scores['cardinality_penalty']);
        $this->assertLessThan(0.7, $scores['overall'], 'High cardinality should suppress overall');
    }

    public function test_persisted_high_cardinality_flag_floors_cardinality_score(): void
    {
        $field = $this->field();
        $field->is_high_cardinality = true;
        $field->save();

        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 80,
            distinctValues: 6, // would normally be fine
            field: $field,
        );

        $this->assertLessThanOrEqual(0.1, $scores['cardinality_penalty']);
    }

    public function test_fragmented_metadata_penalises_fragmentation_score(): void
    {
        $field = $this->field();
        // 4 distinct values + 6 alias rows = density 1.5 → penalty clamps to 0.
        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 80,
            distinctValues: 4,
            field: $field,
            aliasCount: 6,
            duplicateCandidateCount: 0,
        );
        $this->assertLessThanOrEqual(0.0, $scores['fragmentation_penalty'] - 0.0001);
    }

    public function test_unused_filter_lowers_usage_score_only(): void
    {
        $field = $this->field();
        $field->facet_usage_count = 0;
        $field->save();
        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 90,
            distinctValues: 4,
            field: $field,
        );
        $this->assertSame(0.0, (float) $scores['usage']);

        $field2 = $this->field();
        $field2->facet_usage_count = 200; // capped at log target
        $field2->save();
        $scores2 = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 90,
            distinctValues: 4,
            field: $field2,
        );
        $this->assertGreaterThan(0.9, $scores2['usage']);
    }

    public function test_zero_coverage_yields_zero_overall(): void
    {
        $field = $this->field();
        $scores = $this->scoring->computeFromCounters(
            folderAssetCount: 100,
            coverageCount: 0,
            distinctValues: 0,
            field: $field,
        );
        $this->assertSame(0.0, (float) $scores['coverage']);
        $this->assertSame(0.0, (float) $scores['overall']);
    }

    public function test_empty_folder_short_circuits_to_zero_scores(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Empty Co',
            'slug' => 'empty-co-'.Str::random(4),
            'manual_plan_override' => 'starter',
        ], ['email' => 'empty@example.com', 'first_name' => 'E', 'last_name' => 'C']);
        $folder = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => \App\Enums\AssetType::ASSET,
            'name' => 'Empty Folder',
            'slug' => 'empty-folder-cnis',
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
            'requires_approval' => false,
        ]);
        $field = $this->field();
        $scores = $this->scoring->score($tenant, $folder, $field);
        $this->assertSame(0.0, (float) $scores['overall']);
        $this->assertSame(0, $scores['counters']['folder_asset_count']);
    }
}

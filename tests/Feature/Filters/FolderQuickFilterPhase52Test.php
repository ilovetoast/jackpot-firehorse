<?php

namespace Tests\Feature\Filters;

use App\Enums\AssetType;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\Contracts\QuickFilterInstrumentation;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterQualityService;
use App\Services\Filters\Instrumentation\NullQuickFilterInstrumentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5.2 — covers the additive surface added in this phase:
 *   - Pinning column + sort order (pinned > order > weight > label).
 *   - Pin/unpin API on the assignment service.
 *   - `partitionVisibleAndOverflow` helper.
 *   - FolderQuickFilterQualityService evaluate / warningsFor / suppression.
 *   - Instrumentation seam binding + Null implementation contract.
 *
 * Existing Phase 1-5 tests assert the rest of the behaviour. New columns
 * are nullable / defaulted so older tests continue to pass.
 */
class FolderQuickFilterPhase52Test extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private FolderQuickFilterAssignmentService $service;
    private FolderQuickFilterQualityService $quality;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FolderQuickFilterAssignmentService::class);
        $this->quality = app(FolderQuickFilterQualityService::class);
    }

    /** @return array{0: \App\Models\Tenant, 1: \App\Models\Brand, 2: \App\Models\User, 3: Category} */
    private function tenantBrandUserCategory(string $slug = 'qf-52'): array
    {
        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin(
            [
                'name' => 'QF52 '.$slug,
                'slug' => 'qf52-'.$slug,
                'manual_plan_override' => 'starter',
            ],
            ['email' => 'qf52-'.$slug.'@example.com', 'first_name' => 'Q', 'last_name' => 'F']
        );
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => AssetType::ASSET,
            'name' => 'Folder '.$slug,
            'slug' => $slug,
            'is_system' => false,
            'is_locked' => false,
            'is_private' => false,
            'is_hidden' => false,
            'sort_order' => 1,
        ]);

        return [$tenant, $brand, $user, $category];
    }

    private function makeField(string $key, string $type, array $overrides = []): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId(array_merge([
            'key' => $key,
            'system_label' => ucfirst(str_replace('_', ' ', $key)),
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
        ], $overrides));

        return MetadataField::query()->findOrFail($id);
    }

    // -----------------------------------------------------------------
    // Pinning
    // -----------------------------------------------------------------

    public function test_pinned_filters_sort_before_unpinned_regardless_of_order(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-sort');

        $alpha = $this->makeField('qf52_alpha', 'select', ['system_label' => 'Alpha']);
        $bravo = $this->makeField('qf52_bravo', 'select', ['system_label' => 'Bravo']);
        $charlie = $this->makeField('qf52_charlie', 'select', ['system_label' => 'Charlie']);

        // Bravo gets pinned but no explicit order. Alpha + Charlie have
        // explicit orders that would otherwise place them first.
        $this->service->enableQuickFilter($category, $alpha, ['order' => 0]);
        $this->service->enableQuickFilter($category, $charlie, ['order' => 1]);
        $this->service->enableQuickFilter($category, $bravo);
        $this->service->pinQuickFilter($category, $bravo);

        $rows = $this->service->getQuickFiltersForFolder($category);
        $this->assertSame(
            [$bravo->id, $alpha->id, $charlie->id],
            $rows->pluck('metadata_field_id')->all(),
            'Pinned filters must sort first; remaining rows fall back to order then label.'
        );
    }

    public function test_among_pinned_filters_secondary_sort_keys_still_apply(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-among');

        $a = $this->makeField('qf52_pa', 'select', ['system_label' => 'Aaa']);
        $b = $this->makeField('qf52_pb', 'select', ['system_label' => 'Bbb']);
        $c = $this->makeField('qf52_pc', 'select', ['system_label' => 'Ccc']);

        // All three pinned; only `c` has an explicit order. The remaining two
        // should sort alphabetically AFTER the explicitly-ordered one.
        $this->service->enableQuickFilter($category, $b);
        $this->service->enableQuickFilter($category, $c, ['order' => 0]);
        $this->service->enableQuickFilter($category, $a);
        $this->service->pinQuickFilter($category, $a);
        $this->service->pinQuickFilter($category, $b);
        $this->service->pinQuickFilter($category, $c);

        $rows = $this->service->getQuickFiltersForFolder($category);
        $this->assertSame(
            [$c->id, $a->id, $b->id],
            $rows->pluck('metadata_field_id')->all(),
        );
    }

    public function test_unpin_removes_priority_and_set_pinned_round_trips(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-toggle');
        $field = $this->makeField('qf52_pin_toggle', 'select');
        $other = $this->makeField('qf52_pin_other', 'select', ['system_label' => 'Aother']);

        $this->service->enableQuickFilter($category, $field, ['order' => 5]);
        $this->service->enableQuickFilter($category, $other, ['order' => 0]);

        $this->service->setQuickFilterPinned($category, $field, true);
        $this->assertTrue($this->service->isQuickFilterPinned($category, $field));
        $rows = $this->service->getQuickFiltersForFolder($category);
        $this->assertSame($field->id, $rows->first()->metadata_field_id);

        $this->service->unpinQuickFilter($category, $field);
        $this->assertFalse($this->service->isQuickFilterPinned($category, $field));
        $rows = $this->service->getQuickFiltersForFolder($category);
        $this->assertSame($other->id, $rows->first()->metadata_field_id);
    }

    public function test_disabling_a_pinned_filter_clears_its_pinned_flag(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-disable');
        $field = $this->makeField('qf52_disable_pinned', 'select');

        $this->service->enableQuickFilter($category, $field);
        $this->service->pinQuickFilter($category, $field);
        $this->assertTrue($this->service->isQuickFilterPinned($category, $field));

        $this->service->disableQuickFilter($category, $field);
        $this->assertFalse($this->service->isQuickFilterPinned($category, $field));

        $row = DB::table('metadata_field_visibility')
            ->where('metadata_field_id', $field->id)
            ->where('category_id', $category->id)
            ->first();
        $this->assertFalse((bool) $row->is_pinned_folder_quick_filter);
        // Source preserved (Phase 2 invariant) — disable mustn't wipe audit info.
        $this->assertNotNull($row);
    }

    public function test_pinning_an_ineligible_filter_throws(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-bad');
        $field = $this->makeField('qf52_pin_text', 'text');

        $this->expectException(InvalidArgumentException::class);
        $this->service->pinQuickFilter($category, $field);
    }

    public function test_enable_quick_filter_accepts_pinned_in_opts(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('pin-enable');
        $field = $this->makeField('qf52_enable_pinned', 'multiselect');

        $row = $this->service->enableQuickFilter($category, $field, ['pinned' => true]);

        $this->assertTrue((bool) $row->is_pinned_folder_quick_filter);
        $this->assertTrue($this->service->isQuickFilterPinned($category, $field));
    }

    public function test_get_quick_filters_for_folders_batch_applies_pinned_sort(): void
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'Pin Batch Co',
            'slug' => 'pin-batch-co',
            'manual_plan_override' => 'starter',
        ]);
        $folder = Category::create([
            'tenant_id' => $tenant->id, 'brand_id' => $brand->id, 'asset_type' => AssetType::ASSET,
            'name' => 'Pin Batch Folder', 'slug' => 'pin-batch-folder',
            'is_system' => false, 'is_locked' => false, 'is_private' => false,
            'is_hidden' => false, 'sort_order' => 1,
        ]);
        $a = $this->makeField('qf52_batch_a', 'select', ['system_label' => 'Aaa']);
        $b = $this->makeField('qf52_batch_b', 'select', ['system_label' => 'Bbb']);

        $this->service->enableQuickFilter($folder, $a, ['order' => 0]);
        $this->service->enableQuickFilter($folder, $b);
        $this->service->pinQuickFilter($folder, $b);

        $bucketed = $this->service->getQuickFiltersForFolders([$folder]);
        $rows = $bucketed[$folder->id] ?? [];
        $this->assertCount(2, $rows);
        $this->assertSame($b->id, (int) $rows[0]->metadata_field_id);
        $this->assertSame($a->id, (int) $rows[1]->metadata_field_id);
    }

    // -----------------------------------------------------------------
    // Visibility / overflow split
    // -----------------------------------------------------------------

    public function test_partition_visible_and_overflow_respects_max(): void
    {
        config(['categories.folder_quick_filters.max_visible_per_folder' => 2]);
        $rows = ['a', 'b', 'c', 'd'];
        $split = $this->service->partitionVisibleAndOverflow($rows);
        $this->assertSame(['a', 'b'], $split['visible']);
        $this->assertSame(['c', 'd'], $split['overflow']);
    }

    public function test_partition_zero_cap_pushes_all_to_overflow(): void
    {
        config(['categories.folder_quick_filters.max_visible_per_folder' => 0]);
        $rows = ['a', 'b'];
        $split = $this->service->partitionVisibleAndOverflow($rows);
        $this->assertSame([], $split['visible']);
        $this->assertSame(['a', 'b'], $split['overflow']);
    }

    public function test_max_visible_per_folder_reads_config(): void
    {
        config(['categories.folder_quick_filters.max_visible_per_folder' => 7]);
        $this->assertSame(7, $this->service->maxVisiblePerFolder());

        // Negative values clamp to 0 — defensive against admin error.
        config(['categories.folder_quick_filters.max_visible_per_folder' => -3]);
        $this->assertSame(0, $this->service->maxVisiblePerFolder());
    }

    // -----------------------------------------------------------------
    // FolderQuickFilterQualityService
    // -----------------------------------------------------------------

    public function test_quality_service_returns_no_warnings_for_clean_field(): void
    {
        $field = $this->makeField('qf52_clean', 'select');
        $summary = $this->quality->evaluate($field);

        $this->assertFalse($summary['is_high_cardinality']);
        $this->assertFalse($summary['is_low_quality_candidate']);
        $this->assertSame([], $summary['warnings']);
        $this->assertNull($summary['estimated_distinct_value_count']);
        $this->assertSame(0, $summary['facet_usage_count']);
    }

    public function test_quality_service_flags_high_cardinality_and_emits_warning(): void
    {
        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 50]);

        $field = $this->makeField('qf52_explosion', 'select');
        // Persist a fake estimate above the cap.
        DB::table('metadata_fields')->where('id', $field->id)->update([
            'estimated_distinct_value_count' => 25000,
        ]);
        $field->refresh();

        $summary = $this->quality->evaluate($field);

        $this->assertTrue($summary['is_high_cardinality']);
        $this->assertNotEmpty($summary['warnings']);
        $this->assertStringContainsString('25,000', $summary['warnings'][0]);
        $this->assertSame(25000, $summary['estimated_distinct_value_count']);
        $this->assertTrue($this->quality->recommendsSuppression($field));
    }

    public function test_quality_service_persists_cardinality_estimate_and_flag(): void
    {
        config(['categories.folder_quick_filters.max_distinct_values_for_quick_filter' => 100]);
        $field = $this->makeField('qf52_card_persist', 'select');

        $this->quality->recordCardinalityEstimate($field, 250);

        $row = DB::table('metadata_fields')->where('id', $field->id)->first();
        $this->assertSame(250, (int) $row->estimated_distinct_value_count);
        $this->assertTrue((bool) $row->is_high_cardinality);

        // Going back below the cap clears the flag (admin-relevant signal).
        $this->quality->recordCardinalityEstimate($field, 10);
        $row = DB::table('metadata_fields')->where('id', $field->id)->first();
        $this->assertSame(10, (int) $row->estimated_distinct_value_count);
        $this->assertFalse((bool) $row->is_high_cardinality);
    }

    public function test_quality_service_record_facet_usage_increments_counter(): void
    {
        $field = $this->makeField('qf52_usage', 'select');
        $this->quality->recordFacetUsage($field);
        $this->quality->recordFacetUsage($field);

        $row = DB::table('metadata_fields')->where('id', $field->id)->first();
        $this->assertSame(2, (int) $row->facet_usage_count);
        $this->assertNotNull($row->last_facet_usage_at);
    }

    public function test_quality_service_warnings_for_low_quality_candidate_flag(): void
    {
        $field = $this->makeField('qf52_lowq', 'select');
        DB::table('metadata_fields')->where('id', $field->id)->update([
            'is_low_quality_candidate' => true,
        ]);
        $field->refresh();

        $warnings = $this->quality->warningsFor($field);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('high metadata variation', strtolower($warnings[0]));
    }

    // -----------------------------------------------------------------
    // Instrumentation seam
    // -----------------------------------------------------------------

    public function test_default_instrumentation_binding_is_null_implementation(): void
    {
        $instrumentation = app(QuickFilterInstrumentation::class);
        $this->assertInstanceOf(NullQuickFilterInstrumentation::class, $instrumentation);
    }

    public function test_null_instrumentation_swallows_every_event_safely(): void
    {
        [, , , $category] = $this->tenantBrandUserCategory('instr-null');
        $field = $this->makeField('qf52_instr_null', 'select');
        $instrumentation = app(QuickFilterInstrumentation::class);

        // Each call must return cleanly without throwing.
        $instrumentation->recordOpen($field, $category);
        $instrumentation->recordOverflowOpen($category);
        $instrumentation->recordSelection($field, $category, 'foo');
        $instrumentation->recordSelection($field, $category, ['a', 'b']);
        $instrumentation->recordSelection($field, $category, true);

        $this->assertTrue(true); // reaching here = no exception thrown
    }

    public function test_log_instrumentation_can_be_swapped_in_without_changes_to_call_sites(): void
    {
        // Simulate an environment that wants log-channel instrumentation.
        $this->app->bind(
            QuickFilterInstrumentation::class,
            \App\Services\Filters\Instrumentation\LogQuickFilterInstrumentation::class
        );

        [, , , $category] = $this->tenantBrandUserCategory('instr-log');
        $field = $this->makeField('qf52_instr_log', 'select');
        $instrumentation = app(QuickFilterInstrumentation::class);
        $this->assertInstanceOf(
            \App\Services\Filters\Instrumentation\LogQuickFilterInstrumentation::class,
            $instrumentation,
        );

        // Just verify the bound impl does not throw.
        $instrumentation->recordOverflowOpen($category);
        $instrumentation->recordOpen($field, $category);
        $instrumentation->recordSelection($field, $category, ['x']);
        $this->assertTrue(true);
    }
}

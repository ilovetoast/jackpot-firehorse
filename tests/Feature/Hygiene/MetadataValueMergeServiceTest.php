<?php

namespace Tests\Feature\Hygiene;

use App\Models\MetadataField;
use App\Models\MetadataValueAlias;
use App\Models\MetadataValueMerge;
use App\Models\Tenant;
use App\Services\Hygiene\MetadataValueMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5.3 — covers the non-destructive value merge path. Exercises both
 * select (string value_json) and multiselect (array value_json) shapes.
 */
class MetadataValueMergeServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private MetadataValueMergeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MetadataValueMergeService::class);
    }

    /** @return array{0: Tenant, 1: \App\Models\Brand} */
    private function tenant(string $slug): array
    {
        [$tenant, $brand] = $this->createActivatedTenantBrandAdmin([
            'name' => 'P53M '.$slug,
            'slug' => 'p53m-'.$slug,
            'manual_plan_override' => 'starter',
        ], ['email' => 'p53m-'.$slug.'@example.com', 'first_name' => 'M', 'last_name' => 'X']);

        return [$tenant, $brand];
    }

    private function field(string $key, string $type = 'select'): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId([
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
        ]);

        return MetadataField::query()->findOrFail($id);
    }

    private function makeAsset(Tenant $tenant, $brand, MetadataField $field, mixed $value): string
    {
        $assetId = (string) Str::uuid();
        DB::table('assets')->insert([
            'id' => $assetId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => null,
            'storage_bucket_id' => null,
            'status' => 'visible',
            'thumbnail_status' => 'pending',
            'analysis_status' => 'pending',
            'type' => 'asset',
            'original_filename' => 'a.jpg',
            'title' => 'A',
            'size_bytes' => 3,
            'mime_type' => 'image/jpeg',
            'storage_root_path' => 'tenants/'.$tenant->uuid.'/assets/'.$assetId.'/v1',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
            'intake_state' => 'normal',
        ]);
        DB::table('asset_metadata')->insert([
            'asset_id' => $assetId,
            'metadata_field_id' => $field->id,
            'value_json' => json_encode($value),
            'source' => 'user',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assetId;
    }

    public function test_merge_select_rewrites_value_json_and_records_alias_and_audit(): void
    {
        [$tenant, $brand] = $this->tenant('select-basic');
        $field = $this->field('environment_select');
        $a = $this->makeAsset($tenant, $brand, $field, 'Outdoors');
        $b = $this->makeAsset($tenant, $brand, $field, 'Outdoors');
        $c = $this->makeAsset($tenant, $brand, $field, 'Studio');

        $result = $this->service->merge($field, $tenant, 'Outdoors', 'Outdoor');

        $this->assertSame(2, $result['rows_updated']);
        $this->assertTrue($result['alias_recorded']);
        $this->assertFalse($result['option_removed']);
        $this->assertFalse($result['bounded_by_cap']);

        $values = DB::table('asset_metadata')
            ->where('metadata_field_id', $field->id)
            ->orderBy('asset_id')
            ->pluck('value_json')
            ->all();
        $decoded = array_map('json_decode', $values);
        sort($decoded);
        $this->assertSame(['Outdoor', 'Outdoor', 'Studio'], $decoded);

        $this->assertSame(1, MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->count());
        $this->assertSame(1, MetadataValueMerge::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->count());
    }

    public function test_merge_multiselect_replaces_alias_and_dedupes(): void
    {
        [$tenant, $brand] = $this->tenant('multi-basic');
        $field = $this->field('subjects_multi', 'multiselect');
        // Asset A has only the alias.
        $a = $this->makeAsset($tenant, $brand, $field, ['Outdoors']);
        // Asset B has both alias AND canonical → must dedupe.
        $b = $this->makeAsset($tenant, $brand, $field, ['Outdoor', 'Outdoors', 'Lifestyle']);
        // Asset C is unrelated.
        $c = $this->makeAsset($tenant, $brand, $field, ['Studio']);

        $result = $this->service->merge($field, $tenant, 'Outdoors', 'Outdoor');

        $this->assertSame(2, $result['rows_updated']);

        $aValues = json_decode(DB::table('asset_metadata')->where('asset_id', $a)->value('value_json'), true);
        $bValues = json_decode(DB::table('asset_metadata')->where('asset_id', $b)->value('value_json'), true);
        $cValues = json_decode(DB::table('asset_metadata')->where('asset_id', $c)->value('value_json'), true);

        $this->assertEqualsCanonicalizing(['Outdoor'], $aValues);
        $this->assertEqualsCanonicalizing(['Outdoor', 'Lifestyle'], $bValues);
        $this->assertEqualsCanonicalizing(['Studio'], $cValues);
    }

    public function test_merge_does_not_touch_other_tenants_data(): void
    {
        [$tenantA, $brandA] = $this->tenant('iso-a');
        [$tenantB, $brandB] = $this->tenant('iso-b');
        $field = $this->field('environment_iso');
        $this->makeAsset($tenantA, $brandA, $field, 'Outdoors');
        $this->makeAsset($tenantB, $brandB, $field, 'Outdoors');

        $result = $this->service->merge($field, $tenantA, 'Outdoors', 'Outdoor');
        $this->assertSame(1, $result['rows_updated']);

        $values = DB::table('asset_metadata as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->where('am.metadata_field_id', $field->id)
            ->orderBy('a.tenant_id')
            ->pluck('am.value_json', 'a.tenant_id')
            ->all();
        $this->assertSame('"Outdoor"', $values[$tenantA->id]);
        $this->assertSame('"Outdoors"', $values[$tenantB->id]);
    }

    public function test_merge_with_no_matching_rows_returns_zero_and_still_records_alias(): void
    {
        [$tenant, $brand] = $this->tenant('zero');
        $field = $this->field('environment_zero');
        $this->makeAsset($tenant, $brand, $field, 'Studio');

        $result = $this->service->merge($field, $tenant, 'Outdoors', 'Outdoor');
        $this->assertSame(0, $result['rows_updated']);
        $this->assertTrue($result['alias_recorded']);
        $this->assertSame(1, MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->count());
    }

    public function test_merge_remove_from_option_deletes_the_metadata_option_row(): void
    {
        [$tenant, $brand] = $this->tenant('remove-opt');
        $field = $this->field('environment_remove');
        DB::table('metadata_options')->insert([
            'metadata_field_id' => $field->id,
            'value' => 'Outdoors',
            'system_label' => 'Outdoors',
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->makeAsset($tenant, $brand, $field, 'Outdoors');

        $result = $this->service->merge(
            $field,
            $tenant,
            'Outdoors',
            'Outdoor',
            null,
            removeFromOption: true,
        );
        $this->assertTrue($result['option_removed']);
        $this->assertSame(0, DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->whereRaw('LOWER(value) = ?', ['outdoors'])
            ->count());
    }

    public function test_merge_rejects_same_normalized_value(): void
    {
        [$tenant] = $this->tenant('same');
        $field = $this->field('environment_same');
        $this->expectException(InvalidArgumentException::class);
        $this->service->merge($field, $tenant, 'Outdoor', 'OUTDOOR');
    }

    public function test_merge_rejects_unsupported_field_types(): void
    {
        [$tenant] = $this->tenant('type');
        $field = $this->field('environment_text', 'text');
        $this->expectException(InvalidArgumentException::class);
        $this->service->merge($field, $tenant, 'Outdoors', 'Outdoor');
    }

    public function test_recent_merges_returns_latest_first(): void
    {
        [$tenant, $brand] = $this->tenant('history');
        $field = $this->field('environment_hist');
        $this->makeAsset($tenant, $brand, $field, 'Outdoors');
        $this->service->merge($field, $tenant, 'Outdoors', 'Outdoor');
        $this->service->merge($field, $tenant, 'Outside', 'Outdoor');

        $history = $this->service->recentMerges($field, $tenant);
        $this->assertCount(2, $history);
        $this->assertSame('outside', $history[0]['from_value']);
        $this->assertSame('outdoors', $history[1]['from_value']);
    }
}

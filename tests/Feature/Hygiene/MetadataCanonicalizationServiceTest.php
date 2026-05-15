<?php

namespace Tests\Feature\Hygiene;

use App\Models\MetadataField;
use App\Models\MetadataValueAlias;
use App\Models\Tenant;
use App\Services\Hygiene\MetadataCanonicalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5.3 — covers the alias CRUD + chain guards in
 * MetadataCanonicalizationService. Touches the DB so it lives in the
 * Feature suite.
 */
class MetadataCanonicalizationServiceTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    private MetadataCanonicalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MetadataCanonicalizationService::class);
    }

    /** @return array{0: Tenant, 1: MetadataField} */
    private function tenantField(string $slug): array
    {
        [$tenant] = $this->createActivatedTenantBrandAdmin([
            'name' => 'P53 '.$slug,
            'slug' => 'p53-'.$slug,
            'manual_plan_override' => 'starter',
        ], ['email' => 'p53-'.$slug.'@example.com', 'first_name' => 'P', 'last_name' => 'C']);
        $id = DB::table('metadata_fields')->insertGetId([
            'key' => 'qf53_'.$slug,
            'system_label' => 'Field '.$slug,
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
        $field = MetadataField::query()->findOrFail($id);

        return [$tenant, $field];
    }

    public function test_add_alias_creates_a_row_and_get_canonical_resolves(): void
    {
        [$tenant, $field] = $this->tenantField('add');

        $row = $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        $this->assertSame('outdoors', $row->alias_value);
        $this->assertSame('outdoor', $row->canonical_value);
        $this->assertSame('manual', $row->source);
        $this->assertNotEmpty($row->normalization_hash);

        $this->assertSame('outdoor', $this->service->getCanonical($field, $tenant, 'Outdoors'));
        $this->assertSame('outdoor', $this->service->getCanonical($field, $tenant, 'OUTDOORS'));
        // Unknown values pass through normalized.
        $this->assertSame('lifestyle', $this->service->getCanonical($field, $tenant, 'Lifestyle'));
    }

    public function test_add_alias_is_idempotent_when_pointed_at_same_canonical(): void
    {
        [$tenant, $field] = $this->tenantField('idem');
        $a = $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');
        $b = $this->service->addAlias($field, $tenant, 'OUTDOORS', 'OUTDOOR');
        $this->assertSame($a->id, $b->id, 'Same alias normalized form must reuse the existing row.');
    }

    public function test_add_alias_refuses_when_alias_already_points_elsewhere(): void
    {
        [$tenant, $field] = $this->tenantField('repoint');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        $this->expectException(InvalidArgumentException::class);
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Nature');
    }

    public function test_add_alias_refuses_to_create_a_chain(): void
    {
        [$tenant, $field] = $this->tenantField('chain');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        // Trying to add Outside → Outdoors would create a chain
        // (Outside → Outdoors → Outdoor). Refuse.
        $this->expectException(InvalidArgumentException::class);
        $this->service->addAlias($field, $tenant, 'Outside', 'Outdoors');
    }

    public function test_add_alias_refuses_when_canonical_is_already_an_alias(): void
    {
        [$tenant, $field] = $this->tenantField('canon-is-alias');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        $this->expectException(InvalidArgumentException::class);
        // Outdoors is already an alias → can't be reused as a canonical.
        $this->service->addAlias($field, $tenant, 'Outside', 'Outdoors');
    }

    public function test_add_alias_refuses_when_alias_normalizes_to_canonical(): void
    {
        [$tenant, $field] = $this->tenantField('selfref');

        $this->expectException(InvalidArgumentException::class);
        $this->service->addAlias($field, $tenant, 'OUTDOOR', 'outdoor');
    }

    public function test_remove_alias_returns_true_when_existing_and_false_otherwise(): void
    {
        [$tenant, $field] = $this->tenantField('remove');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        $this->assertTrue($this->service->removeAlias($field, $tenant, 'Outdoors'));
        $this->assertFalse($this->service->removeAlias($field, $tenant, 'Outdoors'));
        $this->assertSame('outdoors', $this->service->getCanonical($field, $tenant, 'Outdoors'));
    }

    public function test_aliases_are_strictly_tenant_scoped(): void
    {
        [$tenantA, $fieldA] = $this->tenantField('tenant-a');
        [$tenantB, $fieldB] = $this->tenantField('tenant-b');

        $this->service->addAlias($fieldA, $tenantA, 'Outdoors', 'Outdoor');

        // Same field id, different tenant → no leak.
        $this->assertSame('outdoors', $this->service->getCanonical($fieldA, $tenantB, 'Outdoors'));
        // Different field, same tenant → also no leak.
        $this->assertSame('outdoors', $this->service->getCanonical($fieldB, $tenantA, 'Outdoors'));
    }

    public function test_batch_resolve_canonical_returns_a_map_for_known_and_passthrough_for_unknown(): void
    {
        [$tenant, $field] = $this->tenantField('batch');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');
        $this->service->addAlias($field, $tenant, 'Outside', 'Outdoor');

        $map = $this->service->batchResolveCanonical($field, $tenant, [
            'Outdoors',
            'Outside',
            'Lifestyle',
        ]);
        $this->assertSame('outdoor', $map['outdoors']);
        $this->assertSame('outdoor', $map['outside']);
        // Unknown value is its own canonical.
        $this->assertSame('lifestyle', $map['lifestyle']);
    }

    public function test_get_aliases_for_canonical_returns_only_pointing_aliases(): void
    {
        [$tenant, $field] = $this->tenantField('list');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');
        $this->service->addAlias($field, $tenant, 'Outside', 'Outdoor');
        $this->service->addAlias($field, $tenant, 'Lifestyles', 'Lifestyle');

        $aliases = $this->service->getAliasesFor($field, $tenant, 'Outdoor');
        $this->assertSame(
            ['outdoors', 'outside'],
            $aliases->pluck('alias_value')->all()
        );
    }

    public function test_alias_count_for_field_is_tenant_scoped(): void
    {
        [$tenantA, $fieldA] = $this->tenantField('count-a');
        $this->service->addAlias($fieldA, $tenantA, 'Outdoors', 'Outdoor');
        $this->service->addAlias($fieldA, $tenantA, 'Outside', 'Outdoor');

        $this->assertSame(2, $this->service->aliasCountForField($fieldA, $tenantA));

        [$tenantB] = $this->tenantField('count-b');
        $this->assertSame(0, $this->service->aliasCountForField($fieldA, $tenantB));
    }

    public function test_unknown_source_throws(): void
    {
        [$tenant, $field] = $this->tenantField('bad-source');
        $this->expectException(InvalidArgumentException::class);
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor', null, 'totally_invented');
    }

    public function test_is_alias_and_is_canonical_helpers(): void
    {
        [$tenant, $field] = $this->tenantField('helpers');
        $this->service->addAlias($field, $tenant, 'Outdoors', 'Outdoor');

        $this->assertTrue($this->service->isAlias($field, $tenant, 'Outdoors'));
        $this->assertFalse($this->service->isAlias($field, $tenant, 'Outdoor'));
        $this->assertTrue($this->service->isCanonical($field, $tenant, 'Outdoor'));
        $this->assertFalse($this->service->isCanonical($field, $tenant, 'Outdoors'));
    }
}

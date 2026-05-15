<?php

namespace Tests\Feature\Hygiene;

use App\Models\MetadataField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hygiene\MetadataValueMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesActivatedTenantBrandAdmin;
use Tests\TestCase;

/**
 * Phase 5.3 — HTTP-surface coverage for the metadata hygiene admin
 * endpoints. The detailed business behaviour is verified in the per-service
 * tests; this class only asserts:
 *   - permission gating
 *   - tenant scope (no leakage)
 *   - validation rejections
 *   - happy-path shapes for list / add / merge / duplicates
 */
class MetadataHygieneControllerTest extends TestCase
{
    use CreatesActivatedTenantBrandAdmin;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: \App\Models\Brand, 2: User} */
    private function bootstrap(string $slug, bool $withManagePermission = true): array
    {
        Permission::findOrCreate('metadata.tenant.visibility.manage', 'web');

        [$tenant, $brand, $user] = $this->createActivatedTenantBrandAdmin([
            'name' => 'P53C '.$slug,
            'slug' => 'p53c-'.$slug,
            'manual_plan_override' => 'starter',
        ], [
            'email' => 'p53c-'.$slug.'@example.com',
            'first_name' => 'P',
            'last_name' => 'C',
        ]);

        if ($withManagePermission) {
            $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            $role->givePermissionTo('metadata.tenant.visibility.manage');
            $user->assignRole($role);
            $user->setRoleForTenant($tenant, 'admin');
        }

        return [$tenant, $brand, $user];
    }

    private function makeField(string $key, string $type = 'select'): MetadataField
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

    public function test_list_aliases_returns_empty_payload_for_clean_field(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('list-empty');
        $field = $this->makeField('hyg_list_empty');

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson("/app/api/tenant/metadata/fields/{$field->id}/hygiene/aliases");

        $response->assertOk();
        $this->assertSame([], $response->json('aliases'));
        $this->assertSame($field->id, $response->json('field.id'));
    }

    public function test_add_alias_persists_and_round_trips_through_list(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('add-alias');
        $field = $this->makeField('hyg_add_alias');

        $url = "/app/api/tenant/metadata/fields/{$field->id}/hygiene/aliases";
        $add = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, ['alias' => 'Outdoors', 'canonical' => 'Outdoor']);
        $add->assertCreated();
        $this->assertSame('outdoors', $add->json('alias.alias_value'));
        $this->assertSame('outdoor', $add->json('alias.canonical_value'));

        $list = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson($url);
        $list->assertOk();
        $this->assertCount(1, $list->json('aliases'));
    }

    public function test_add_alias_rejects_chain(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('reject-chain');
        $field = $this->makeField('hyg_reject_chain');
        $url = "/app/api/tenant/metadata/fields/{$field->id}/hygiene/aliases";

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, ['alias' => 'Outdoors', 'canonical' => 'Outdoor'])
            ->assertCreated();

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($url, ['alias' => 'Outside', 'canonical' => 'Outdoors']);

        $resp->assertStatus(422);
        $this->assertSame('invalid_alias', $resp->json('error'));
    }

    public function test_remove_alias_returns_204_and_clears_row(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('remove-alias');
        $field = $this->makeField('hyg_remove_alias');
        $base = "/app/api/tenant/metadata/fields/{$field->id}/hygiene";

        $created = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson($base.'/aliases', ['alias' => 'Outdoors', 'canonical' => 'Outdoor']);
        $aliasId = $created->json('alias.id');

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->deleteJson($base.'/aliases/'.$aliasId);
        $resp->assertStatus(204);
        $this->assertSame(0, DB::table('metadata_value_aliases')->count());
    }

    public function test_duplicate_candidates_returns_groups_for_noisy_field(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('dup');
        $field = $this->makeField('hyg_dup');
        DB::table('metadata_options')->insert([
            ['metadata_field_id' => $field->id, 'value' => 'Outdoor', 'system_label' => 'Outdoor', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()],
            ['metadata_field_id' => $field->id, 'value' => 'Outdoors', 'system_label' => 'Outdoors', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson("/app/api/tenant/metadata/fields/{$field->id}/hygiene/duplicates");
        $resp->assertOk();
        $this->assertNotEmpty($resp->json('candidates'));
    }

    public function test_merge_endpoint_rewrites_asset_metadata_and_returns_summary(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('merge-endpoint');
        $field = $this->makeField('hyg_merge', 'select');
        $assetId = $this->makeAsset($tenant, $brand, $field, 'Outdoors');

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->postJson("/app/api/tenant/metadata/fields/{$field->id}/hygiene/merge", [
                'from' => 'Outdoors',
                'to' => 'Outdoor',
            ]);
        $resp->assertOk();
        $this->assertSame(1, $resp->json('rows_updated'));
        $this->assertTrue($resp->json('alias_recorded'));
        $this->assertSame(
            '"Outdoor"',
            DB::table('asset_metadata')->where('asset_id', $assetId)->value('value_json')
        );
    }

    public function test_endpoints_require_manage_permission(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('no-perm', withManagePermission: false);
        // Override the pivot role from the trait default ('admin') to a
        // non-managing role so `hasPermissionForTenant` returns false.
        DB::table('tenant_user')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->update(['role' => 'member']);
        $user->setRoleForTenant($tenant, 'member');

        $field = $this->makeField('hyg_no_perm');

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson("/app/api/tenant/metadata/fields/{$field->id}/hygiene/aliases");
        $resp->assertStatus(403);
    }

    public function test_endpoints_reject_unknown_field_with_404(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('unknown-field');
        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/app/api/tenant/metadata/fields/9999999/hygiene/aliases');
        $resp->assertStatus(404);
    }

    public function test_recent_merges_endpoint_returns_history(): void
    {
        [$tenant, $brand, $user] = $this->bootstrap('history');
        $field = $this->makeField('hyg_history', 'select');
        $this->makeAsset($tenant, $brand, $field, 'Outdoors');
        app(MetadataValueMergeService::class)->merge($field, $tenant, 'Outdoors', 'Outdoor');

        $resp = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson("/app/api/tenant/metadata/fields/{$field->id}/hygiene/merges");
        $resp->assertOk();
        $this->assertCount(1, $resp->json('merges'));
        $this->assertSame('outdoors', $resp->json('merges.0.from_value'));
    }
}

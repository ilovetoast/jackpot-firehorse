<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HelpConceptsGlossaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * @return array{0: Tenant, 1: \App\Models\Brand, 2: User}
     */
    private function actingOwnerWithAllPermissions(): array
    {
        $tenant = Tenant::create([
            'name' => 'HC',
            'slug' => 't-help-concepts',
            'manual_plan_override' => 'enterprise',
        ]);
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $user = User::create([
            'email' => 'help-concepts@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'H',
            'last_name' => 'C',
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);
        $user->syncPermissions(Permission::all());

        return [$tenant, $brand, $user];
    }

    public function test_search_what_is_an_asset_surfaces_concepts_asset_first(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('what is an asset'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('concepts.asset', $keys[0]);
    }

    public function test_search_asset_vs_execution_includes_both_concept_topics_in_top_results(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('asset vs execution'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $slice = array_slice($keys, 0, 8);
        $this->assertContains('concepts.asset', $slice);
        $this->assertContains('concepts.execution', $slice);
    }

    public function test_search_what_are_tags_prioritizes_concepts_metadata_over_manage_hub(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('what are tags'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('concepts.metadata', $keys[0]);
        $managePos = array_search('manage.tags_fields_metadata', $keys, true);
        $metaPos = array_search('concepts.metadata', $keys, true);
        $this->assertNotFalse($managePos);
        $this->assertNotFalse($metaPos);
        $this->assertLessThan($managePos, $metaPos);
    }

    public function test_search_what_is_an_execution_prioritizes_concepts_execution(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('what is an execution'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('concepts.execution', $keys[0]);
    }

    public function test_search_metadata_structure_still_prioritizes_manage_tags_fields_metadata(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('metadata structure'));

        $response->assertOk();
        $keys = array_column($response->json('results'), 'key');
        $this->assertNotEmpty($keys);
        $this->assertSame('manage.tags_fields_metadata', $keys[0]);
    }

    public function test_help_api_serializes_concept_topic_shape(): void
    {
        [$tenant, $brand, $user] = $this->actingOwnerWithAllPermissions();

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->getJson('/app/help/actions?q='.rawurlencode('glossary asset'));

        $response->assertOk();
        $results = $response->json('results');
        $hit = collect($results)->firstWhere('key', 'concepts.asset');
        $this->assertIsArray($hit);
        $this->assertSame('Concepts', $hit['category']);
        $this->assertArrayHasKey('title', $hit);
        $this->assertArrayHasKey('short_answer', $hit);
        $this->assertArrayHasKey('steps', $hit);
        $this->assertIsArray($hit['steps']);
        $this->assertArrayHasKey('related', $hit);
        $this->assertIsArray($hit['related']);
        foreach ($hit['related'] as $rel) {
            $this->assertArrayHasKey('key', $rel);
            $this->assertArrayHasKey('title', $rel);
        }
    }
}

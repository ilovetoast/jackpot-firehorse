<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BrandDNA\BrandVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Brand Guidelines Customize Controller — feature tests.
 *
 * Validates the sidebar editor's JSON patch endpoint:
 * - Presentation overrides persist without affecting DNA
 * - Presentation content overrides work correctly
 * - DNA patches (content mode) update the correct fields
 * - Feature gating blocks free plan users
 * - AI regeneration preserves overrides
 */
class BrandGuidelinesCustomizeTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected BrandModel $brandModel;
    protected BrandModelVersion $activeVersion;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'asset.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'view brand', 'guard_name' => 'web']);
        Permission::create(['name' => 'brand_settings.manage', 'guard_name' => 'web']);

        $this->tenant = Tenant::create(['name' => 'Customize Tenant', 'slug' => 'customize-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'accent_color' => '#06b6d4',
        ]);

        Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Assets',
            'slug' => 'assets',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
            'requires_approval' => false,
        ]);

        StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->user = User::create([
            'email' => 'customize@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['asset.view', 'view brand', 'brand_settings.manage']);
        $this->user->setRoleForTenant($this->tenant, 'admin');
        $this->user->assignRole($role);

        $this->brandModel = BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => true,
        ]);

        $this->activeVersion = BrandModelVersion::create([
            'brand_model_id' => $this->brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'identity' => [
                    'mission' => 'Original mission statement',
                    'positioning' => 'Original positioning',
                    'industry' => 'Technology',
                ],
                'personality' => [
                    'primary_archetype' => 'Creator',
                    'voice_description' => 'Original voice',
                ],
                'presentation' => ['style' => 'clean'],
                'presentation_overrides' => ['global' => [], 'sections' => []],
                'presentation_content' => [],
            ],
            'status' => 'active',
        ]);

        $this->brandModel->update(['active_version_id' => $this->activeVersion->id]);
    }

    protected function postCustomize(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->postJson(
                "/app/brands/{$this->brand->id}/guidelines/customize",
                $data
            );
    }

    public function test_overrides_do_not_affect_dna_values(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => [
                    'global' => ['spacing' => 'generous'],
                    'sections' => [
                        'sec-hero' => ['visible' => false, 'background' => ['type' => 'solid', 'color' => '#ff0000']],
                        'sec-purpose' => ['content' => ['show_industry' => false]],
                    ],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->activeVersion->refresh();
        $payload = $this->activeVersion->model_payload;

        $this->assertSame('Original mission statement', $payload['identity']['mission']);
        $this->assertSame('Original positioning', $payload['identity']['positioning']);
        $this->assertSame('Creator', $payload['personality']['primary_archetype']);

        $this->assertSame('generous', $payload['presentation_overrides']['global']['spacing']);
        $this->assertFalse($payload['presentation_overrides']['sections']['sec-hero']['visible']);
    }

    public function test_presentation_content_overrides_persist(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_content' => [
                    'sec-purpose' => [
                        'mission_html' => '<p>Custom <strong>formatted</strong> mission</p>',
                        'positioning_html' => null,
                    ],
                    'sec-voice' => [
                        'voice_html' => '<p>Edited brand voice</p>',
                    ],
                ],
                'presentation_overrides' => ['global' => [], 'sections' => []],
            ],
        ]);

        $response->assertOk();

        $this->activeVersion->refresh();
        $payload = $this->activeVersion->model_payload;

        $this->assertSame(
            '<p>Custom <strong>formatted</strong> mission</p>',
            $payload['presentation_content']['sec-purpose']['mission_html']
        );
        $this->assertSame('Original mission statement', $payload['identity']['mission']);
    }

    public function test_dna_edit_mode_updates_correct_fields(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => ['global' => [], 'sections' => []],
            ],
            'dna_patches' => [
                'identity' => [
                    'mission' => 'Updated mission via content mode',
                ],
            ],
        ]);

        $response->assertOk();

        $this->activeVersion->refresh();
        $payload = $this->activeVersion->model_payload;

        $this->assertSame('Updated mission via content mode', $payload['identity']['mission']);
        $this->assertSame('Original positioning', $payload['identity']['positioning']);
    }

    public function test_reset_removes_overrides_falls_back_to_dna(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $this->postCustomize([
            'payload' => [
                'presentation_overrides' => [
                    'global' => ['spacing' => 'generous'],
                    'sections' => ['sec-hero' => ['visible' => false]],
                ],
            ],
        ]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => ['global' => [], 'sections' => []],
                'presentation_content' => [],
            ],
        ]);

        $response->assertOk();

        $this->activeVersion->refresh();
        $payload = $this->activeVersion->model_payload;

        $this->assertSame([], $payload['presentation_overrides']['global']);
        $this->assertSame([], $payload['presentation_overrides']['sections']);
    }

    public function test_ai_regeneration_preserves_overrides(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $this->postCustomize([
            'payload' => [
                'presentation_overrides' => [
                    'global' => ['spacing' => 'compact'],
                    'sections' => ['sec-hero' => ['visible' => false]],
                ],
                'presentation_content' => [
                    'sec-purpose' => ['mission_html' => '<p>Custom</p>'],
                ],
            ],
        ]);

        $versionService = app(BrandVersionService::class);
        $aiPatch = [
            'identity' => ['mission' => 'AI-generated mission'],
            'personality' => ['primary_archetype' => 'Explorer'],
        ];
        $allPaths = ['sources', 'identity', 'personality', 'typography', 'scoring_rules', 'visual', 'brand_colors'];
        $versionService->patchActivePayload($this->brand, $aiPatch, $allPaths);

        $this->activeVersion->refresh();
        $payload = $this->activeVersion->model_payload;

        $this->assertSame('compact', $payload['presentation_overrides']['global']['spacing']);
        $this->assertFalse($payload['presentation_overrides']['sections']['sec-hero']['visible']);
        $this->assertSame('<p>Custom</p>', $payload['presentation_content']['sec-purpose']['mission_html']);

        $this->assertSame('AI-generated mission', $payload['identity']['mission']);
    }

    public function test_feature_gate_blocks_free_plan(): void
    {
        config(['plans.free.brand_guidelines.customization' => false]);

        $this->tenant->update(['plan_override' => 'free']);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => ['global' => [], 'sections' => []],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_customize_endpoint_returns_json(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => ['global' => ['spacing' => 'default'], 'sections' => []],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['success', 'version_id']);
        $this->assertTrue($response->json('success'));
    }

    public function test_rejects_invalid_payload_keys(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $response = $this->postCustomize([
            'payload' => [
                'identity' => ['mission' => 'Hack attempt'],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_requires_active_version(): void
    {
        config(['plans.pro.brand_guidelines.customization' => true]);

        $this->brandModel->update(['active_version_id' => null]);

        $response = $this->postCustomize([
            'payload' => [
                'presentation_overrides' => ['global' => [], 'sections' => []],
            ],
        ]);

        $response->assertStatus(404);
    }
}

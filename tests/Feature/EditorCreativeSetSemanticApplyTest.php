<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\CreativeSetApplyCommandsService;
use App\Support\StudioApplySkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorCreativeSetSemanticApplyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Brand $brand;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Studio Co',
            'slug' => 'studio-co',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Studio Brand',
            'slug' => 'studio-brand',
        ]);

        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant->id);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentWithHeadline(string $headlineId, string $bgId, string $content = 'Hello'): array
    {
        return [
            'layers' => [
                [
                    'id' => $bgId,
                    'type' => 'generative_image',
                    'name' => 'Background',
                    'visible' => true,
                    'locked' => false,
                    'z' => 0,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                    'prompt' => ['scene' => 'test'],
                    'applyBrandDna' => false,
                    'status' => 'idle',
                    'fit' => 'cover',
                ],
                [
                    'id' => $headlineId,
                    'type' => 'text',
                    'name' => 'Headline',
                    'studioSyncRole' => 'headline',
                    'visible' => true,
                    'locked' => false,
                    'z' => 1,
                    'content' => $content,
                    'style' => [
                        'fontFamily' => 'sans-serif',
                        'fontSize' => 40,
                        'fontWeight' => 700,
                        'lineHeight' => 1.1,
                        'letterSpacing' => 0,
                        'color' => '#111111',
                        'textAlign' => 'center',
                        'verticalAlign' => 'top',
                    ],
                    'transform' => ['x' => 10, 'y' => 20, 'width' => 400, 'height' => 80],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentMinimalBg(string $bgId): array
    {
        return [
            'layers' => [
                [
                    'id' => $bgId,
                    'type' => 'generative_image',
                    'name' => 'Background',
                    'visible' => true,
                    'locked' => false,
                    'z' => 0,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                    'prompt' => ['scene' => 'test'],
                    'applyBrandDna' => false,
                    'status' => 'idle',
                    'fit' => 'cover',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentWithCtaGroup(string $bgId, string $fillId, string $textId, string $groupId): array
    {
        return [
            'layers' => [
                [
                    'id' => $bgId,
                    'type' => 'generative_image',
                    'name' => 'Background',
                    'visible' => true,
                    'locked' => false,
                    'z' => 0,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                    'prompt' => ['scene' => 'test'],
                    'applyBrandDna' => false,
                    'status' => 'idle',
                    'fit' => 'cover',
                ],
                [
                    'id' => $fillId,
                    'type' => 'fill',
                    'name' => 'CTA button',
                    'studioSyncRole' => 'cta',
                    'groupId' => $groupId,
                    'fillRole' => 'cta_button',
                    'fillKind' => 'solid',
                    'color' => '#000000',
                    'visible' => true,
                    'locked' => false,
                    'z' => 1,
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 120, 'height' => 40],
                ],
                [
                    'id' => $textId,
                    'type' => 'text',
                    'name' => 'CTA',
                    'studioSyncRole' => 'cta',
                    'groupId' => $groupId,
                    'visible' => true,
                    'locked' => false,
                    'z' => 2,
                    'content' => 'Buy',
                    'style' => [
                        'fontFamily' => 'sans-serif',
                        'fontSize' => 16,
                        'fontWeight' => 600,
                        'lineHeight' => 1.1,
                        'letterSpacing' => 0,
                        'color' => '#ffffff',
                        'textAlign' => 'center',
                        'verticalAlign' => 'middle',
                    ],
                    'transform' => ['x' => 0, 'y' => 0, 'width' => 120, 'height' => 36],
                ],
            ],
        ];
    }

    public function test_apply_preview_returns_eligible_and_skip_counts(): void
    {
        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithHeadline('h1', 'g1', 'Alpha'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $this->documentWithHeadline('h2', 'g2', 'Beta'),
        ]);
        $c3 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V3',
            'document_json' => $this->documentMinimalBg('g3'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Test set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c3->id,
            'sort_order' => 2,
            'label' => 'C',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply-preview", [
                'source_composition_id' => $c1->id,
                'commands' => [
                    ['type' => 'update_text_content', 'role' => 'headline', 'text' => 'Alpha'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('sibling_compositions_targeted', 2);
        $response->assertJsonPath('sibling_compositions_eligible', 1);
        $response->assertJsonPath('sibling_compositions_would_skip', 1);
        $this->assertArrayHasKey(StudioApplySkipReason::TARGET_LAYER_MAPPING_FAILED, $response->json('skipped_by_reason'));
    }

    public function test_apply_updates_matching_sibling_headline_text(): void
    {
        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithHeadline('h1', 'g1', 'Source text'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $this->documentWithHeadline('h2', 'g2', 'Old'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Test set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply", [
                'source_composition_id' => $c1->id,
                'commands' => [
                    ['type' => 'update_text_content', 'role' => 'headline', 'text' => 'Source text'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('sibling_compositions_updated', 1);
        $response->assertJsonPath('skipped', []);
        $c2fresh = $c2->fresh();
        $layers = $c2fresh->document_json['layers'] ?? [];
        $headline = collect($layers)->firstWhere('id', 'h2');
        $this->assertSame('Source text', $headline['content'] ?? null);
    }

    public function test_apply_cta_visibility_syncs_entire_group_on_sibling(): void
    {
        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithCtaGroup('b1', 'f1', 't1', 'grp'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $this->documentWithCtaGroup('b2', 'f2', 't2', 'grp'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'CTA set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply", [
                'source_composition_id' => $c1->id,
                'commands' => [
                    ['type' => 'update_layer_visibility', 'role' => 'cta', 'visible' => false],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('sibling_compositions_updated', 1);
        $layers = $c2->fresh()->document_json['layers'] ?? [];
        $fill = collect($layers)->firstWhere('id', 'f2');
        $text = collect($layers)->firstWhere('id', 't2');
        $this->assertFalse((bool) ($fill['visible'] ?? true));
        $this->assertFalse((bool) ($text['visible'] ?? true));
    }

    public function test_apply_role_transform_updates_sibling_logo_layer(): void
    {
        $doc = static function (string $bgId, string $logoId): array {
            return [
                'layers' => [
                    [
                        'id' => $bgId,
                        'type' => 'generative_image',
                        'name' => 'Background',
                        'visible' => true,
                        'locked' => false,
                        'z' => 0,
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
                        'prompt' => ['scene' => 'test'],
                        'applyBrandDna' => false,
                        'status' => 'idle',
                        'fit' => 'cover',
                    ],
                    [
                        'id' => $logoId,
                        'type' => 'image',
                        'name' => 'Logo',
                        'studioSyncRole' => 'logo',
                        'visible' => true,
                        'locked' => false,
                        'z' => 1,
                        'assetId' => '00000000-0000-0000-0000-000000000001',
                        'src' => 'https://example.test/logo.png',
                        'naturalWidth' => 200,
                        'naturalHeight' => 80,
                        'transform' => ['x' => 5, 'y' => 6, 'width' => 100, 'height' => 40],
                        'fit' => 'contain',
                    ],
                ],
            ];
        };

        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $doc('bg1', 'logo1'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $doc('bg2', 'logo2'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Logo set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply", [
                'source_composition_id' => $c1->id,
                'commands' => [
                    ['type' => 'update_role_transform', 'role' => 'logo', 'x' => 12, 'y' => 34, 'width' => 90, 'height' => 36],
                ],
            ]);

        $response->assertOk();
        $logo2 = collect($c2->fresh()->document_json['layers'])->firstWhere('id', 'logo2');
        $this->assertEqualsWithDelta(12.0, (float) ($logo2['transform']['x'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(34.0, (float) ($logo2['transform']['y'] ?? 0), 0.001);
    }

    public function test_apply_selected_versions_updates_only_listed_compositions(): void
    {
        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithHeadline('h1', 'g1', 'Main'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $this->documentWithHeadline('h2', 'g2', 'Two'),
        ]);
        $c3 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V3',
            'document_json' => $this->documentWithHeadline('h3', 'g3', 'Three'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Pick set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        foreach ([$c1, $c2, $c3] as $i => $c) {
            CreativeSetVariant::create([
                'creative_set_id' => $set->id,
                'composition_id' => $c->id,
                'sort_order' => $i,
                'label' => (string) ($i + 1),
                'status' => CreativeSetVariant::STATUS_READY,
                'axis' => null,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply", [
                'source_composition_id' => $c1->id,
                'scope' => 'selected_versions',
                'target_composition_ids' => [$c3->id],
                'commands' => [
                    ['type' => 'update_text_content', 'role' => 'headline', 'text' => 'Main'],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('sibling_compositions_targeted', 1);
        $response->assertJsonPath('sibling_compositions_updated', 1);
        $h2 = collect($c2->fresh()->document_json['layers'] ?? [])->firstWhere('id', 'h2');
        $h3 = collect($c3->fresh()->document_json['layers'] ?? [])->firstWhere('id', 'h3');
        $this->assertSame('Two', $h2['content'] ?? null);
        $this->assertSame('Main', $h3['content'] ?? null);
    }

    public function test_apply_selected_versions_rejects_composition_not_in_set(): void
    {
        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithHeadline('h1', 'g1'),
        ]);
        $c2 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V2',
            'document_json' => $this->documentWithHeadline('h2', 'g2'),
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/apply", [
                'source_composition_id' => $c1->id,
                'scope' => 'selected_versions',
                'target_composition_ids' => [99_999_999],
                'commands' => [
                    ['type' => 'update_text_content', 'role' => 'headline', 'text' => 'X'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_service_rejects_disallowed_command_type_before_apply(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $c1 = Composition::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'V1',
            'document_json' => $this->documentWithHeadline('h1', 'g1'),
        ]);
        $set = CreativeSet::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $svc = app(CreativeSetApplyCommandsService::class);
        $svc->applyToAllVariants($set->fresh(['variants']), $this->user, $c1->id, [
            ['type' => 'patch_layer', 'layer_id' => 'x', 'patch' => []],
        ]);
    }
}

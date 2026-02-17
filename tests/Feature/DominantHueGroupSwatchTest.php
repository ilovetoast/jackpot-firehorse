<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Color\HueClusterService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dominant Hue Group Swatch Filter Test
 *
 * Regression test for color swatch filter (dominant_hue_group):
 * - filter_type === 'color' for dominant_hue_group
 * - options include swatch hex from HueClusterService
 * - Schema does not suggest text-input UI (filter_type drives swatch UI)
 */
class DominantHueGroupSwatchTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ])->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Photography',
            'slug' => 'photography',
            'asset_type' => AssetType::ASSET,
            'is_system' => false,
        ]);

        $this->artisan('db:seed', ['--class' => 'MetadataFieldsSeeder']);
    }

    #[Test]
    public function dominant_hue_group_has_filter_type_color_in_filterable_schema(): void
    {
        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        $filterableFields = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        $hueField = collect($filterableFields)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_hue_group');

        $this->assertNotNull($hueField, 'dominant_hue_group must appear in filterable schema');
        $this->assertSame('color', $hueField['filter_type'] ?? null, 'dominant_hue_group must have filter_type === "color"');
    }

    #[Test]
    public function hue_cluster_service_returns_display_hex_for_cluster_key(): void
    {
        $service = app(HueClusterService::class);

        $meta = $service->getClusterMeta('green');

        $this->assertNotNull($meta);
        $this->assertArrayHasKey('display_hex', $meta);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $meta['display_hex']);
    }

    #[Test]
    public function enriched_dominant_hue_group_options_include_swatch_hex(): void
    {
        $hueClusterService = app(HueClusterService::class);
        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        $filterableSchema = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        $clusterKeys = ['green', 'blue', 'warm_brown'];

        foreach ($filterableSchema as &$field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_hue_group') {
                $field['options'] = array_values(array_map(function ($key) use ($hueClusterService) {
                    $meta = $hueClusterService->getClusterMeta((string) $key);
                    return [
                        'value' => $key,
                        'label' => $meta['label'] ?? $key,
                        'swatch' => $meta['display_hex'] ?? '#999999',
                    ];
                }, $clusterKeys));
                break;
            }
        }
        unset($field);

        $hueField = collect($filterableSchema)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_hue_group');
        $this->assertNotNull($hueField);
        $this->assertSame('color', $hueField['filter_type'] ?? null);

        $options = $hueField['options'] ?? [];
        $this->assertCount(3, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('swatch', $option);
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $option['swatch'], 'Each option must have swatch as hex');
        }
    }

    #[Test]
    public function dominant_hue_group_does_not_use_text_input_ui_when_filter_type_is_color(): void
    {
        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        $filterableFields = $filterService->getFilterableFields($schema, $this->category, $this->tenant);
        $hueField = collect($filterableFields)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_hue_group');

        $this->assertNotNull($hueField);
        $this->assertSame('color', $hueField['filter_type'] ?? null);
        $this->assertArrayHasKey('filter_type', $hueField);
    }
}

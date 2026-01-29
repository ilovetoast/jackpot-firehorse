<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ColorBucketService;
use App\Services\MetadataFilterService;
use App\Services\MetadataSchemaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dominant Color Bucket Swatch Filter Test
 *
 * Regression test for color swatch filter upgrade:
 * - filter_type === 'color' for dominant_color_bucket
 * - options include swatch hex values
 * - Schema does not suggest text-input UI (filter_type drives swatch UI)
 */
class DominantColorBucketSwatchTest extends TestCase
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

    /** @test */
    public function dominant_color_bucket_has_filter_type_color_in_filterable_schema(): void
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

        $bucketField = collect($filterableFields)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_color_bucket');

        $this->assertNotNull($bucketField, 'dominant_color_bucket must appear in filterable schema');
        $this->assertSame('color', $bucketField['filter_type'] ?? null, 'dominant_color_bucket must have filter_type === "color"');
    }

    /** @test */
    public function color_bucket_service_returns_hex_for_lab_bucket_string(): void
    {
        $service = app(ColorBucketService::class);

        $hex = $service->bucketToHex('L50_A10_B20');

        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $hex, 'bucketToHex must return 6-digit hex');
    }

    /** @test */
    public function color_bucket_service_returns_hex_for_macro_bucket_names(): void
    {
        $service = app(ColorBucketService::class);

        foreach (['red', 'black', 'white', 'blue', 'green'] as $name) {
            $hex = $service->bucketToHex($name);
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $hex, "bucketToHex('{$name}') must return hex");
        }
    }

    /** @test */
    public function enriched_dominant_color_bucket_options_include_swatch_hex(): void
    {
        $colorBucketService = app(ColorBucketService::class);
        $schemaResolver = app(MetadataSchemaResolver::class);
        $filterService = app(MetadataFilterService::class);

        $schema = $schemaResolver->resolve(
            $this->tenant->id,
            $this->brand->id,
            $this->category->id,
            'image'
        );

        $filterableSchema = $filterService->getFilterableFields($schema, $this->category, $this->tenant);

        $bucketValues = ['L50_A10_B20', 'red', 'blue'];

        foreach ($filterableSchema as &$field) {
            $fieldKey = $field['field_key'] ?? $field['key'] ?? null;
            if ($fieldKey === 'dominant_color_bucket') {
                $field['options'] = array_values(array_map(function ($bucketValue) use ($colorBucketService) {
                    return [
                        'value' => $bucketValue,
                        'label' => $bucketValue,
                        'swatch' => $colorBucketService->bucketToHex((string) $bucketValue),
                    ];
                }, $bucketValues));
                break;
            }
        }
        unset($field);

        $bucketField = collect($filterableSchema)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_color_bucket');
        $this->assertNotNull($bucketField);
        $this->assertSame('color', $bucketField['filter_type'] ?? null);

        $options = $bucketField['options'] ?? [];
        $this->assertCount(3, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('swatch', $option);
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $option['swatch'], 'Each option must have swatch as hex');
        }
    }

    /** @test */
    public function dominant_color_bucket_does_not_use_text_input_ui_when_filter_type_is_color(): void
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
        $bucketField = collect($filterableFields)->first(fn ($f) => ($f['field_key'] ?? $f['key'] ?? null) === 'dominant_color_bucket');

        $this->assertNotNull($bucketField);
        $this->assertSame('color', $bucketField['filter_type'] ?? null);
        // Frontend should render swatches when filter_type === 'color', not text input.
        // We assert the schema contract: filter_type is the UI hint; type may remain 'text' for storage.
        $this->assertArrayHasKey('filter_type', $bucketField);
    }
}

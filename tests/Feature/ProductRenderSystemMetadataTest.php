<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\StorageBucketStatus;
use App\Enums\ThumbnailStatus;
use App\Enums\UploadStatus;
use App\Enums\UploadType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Product Render metadata display: system fields (dominant_colors, dominant_hue_group,
 * orientation, resolution_class) must appear in editable metadata JSON response.
 */
class ProductRenderSystemMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected Category $category;
    protected User $user;
    protected StorageBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);

        $this->bucket = StorageBucket::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'test-bucket',
            'status' => StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);

        $this->seed(\Database\Seeders\MetadataFieldsSeeder::class);
        $this->seed(\Database\Seeders\SystemCategoryTemplateSeeder::class);

        $systemCategory = SystemCategory::where('slug', 'product-renders')->first();
        $this->category = Category::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'name' => 'Product Renders',
            'slug' => 'product-renders',
            'asset_type' => AssetType::DELIVERABLE,
            'is_system' => false,
            'requires_approval' => false,
            'system_category_id' => $systemCategory?->id,
        ]);
    }

    /**
     * Product render asset: editable metadata JSON includes dominant_colors,
     * dominant_hue_group, orientation, resolution_class.
     */
    public function test_product_render_shows_system_metadata(): void
    {
        $session = UploadSession::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'storage_bucket_id' => $this->bucket->id,
            'status' => UploadStatus::COMPLETED,
            'type' => UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        $asset = Asset::create([
            'tenant_id' => $this->tenant->id,
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $this->bucket->id,
            'type' => AssetType::DELIVERABLE,
            'status' => AssetStatus::VISIBLE,
            'original_filename' => 'product-render.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test.jpg',
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'dominant_hue_group' => 'blue',
            'metadata' => [
                'category_id' => $this->category->id,
                'metadata_extracted' => true,
                'ai_tagging_completed' => true,
                'dominant_colors' => [['hex' => '#1E3A8A', 'coverage' => 0.6], ['hex' => '#3B82F6', 'coverage' => 0.4]],
            ],
        ]);

        $dominantColorsFieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
        $hueGroupFieldId = DB::table('metadata_fields')->where('key', 'dominant_hue_group')->value('id');
        $orientationFieldId = DB::table('metadata_fields')->where('key', 'orientation')->value('id');
        $resolutionFieldId = DB::table('metadata_fields')->where('key', 'resolution_class')->value('id');

        $this->assertNotNull($dominantColorsFieldId, 'dominant_colors field must exist');
        $this->assertNotNull($hueGroupFieldId, 'dominant_hue_group field must exist');
        $this->assertNotNull($orientationFieldId, 'orientation field must exist');
        $this->assertNotNull($resolutionFieldId, 'resolution_class field must exist');

        DB::table('asset_metadata')->insert([
            [
                'asset_id' => $asset->id,
                'metadata_field_id' => $dominantColorsFieldId,
                'value_json' => json_encode([['hex' => '#1E3A8A', 'coverage' => 0.6], ['hex' => '#3B82F6', 'coverage' => 0.4]]),
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'asset_id' => $asset->id,
                'metadata_field_id' => $hueGroupFieldId,
                'value_json' => json_encode('blue'),
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'asset_id' => $asset->id,
                'metadata_field_id' => $orientationFieldId,
                'value_json' => json_encode('landscape'),
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'asset_id' => $asset->id,
                'metadata_field_id' => $resolutionFieldId,
                'value_json' => json_encode('high'),
                'source' => 'system',
                'confidence' => 0.95,
                'producer' => 'system',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id, 'brand_id' => $this->brand->id])
            ->getJson("/app/assets/{$asset->id}/metadata/editable");

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('fields', $data);

        $fieldsByKey = collect($data['fields'])->keyBy('key');

        $this->assertTrue($fieldsByKey->has('dominant_colors'), 'Response must include dominant_colors');
        $this->assertTrue($fieldsByKey->has('dominant_hue_group'), 'Response must include dominant_hue_group');
        $this->assertTrue($fieldsByKey->has('orientation'), 'Response must include orientation');
        $this->assertTrue($fieldsByKey->has('resolution_class'), 'Response must include resolution_class');

        $dominantColors = $fieldsByKey->get('dominant_colors');
        $this->assertNotNull($dominantColors['current_value']);
        $this->assertIsArray($dominantColors['current_value']);
        $this->assertCount(2, $dominantColors['current_value']);

        $hueGroup = $fieldsByKey->get('dominant_hue_group');
        $this->assertSame('blue', $hueGroup['current_value']);

        $orientation = $fieldsByKey->get('orientation');
        $this->assertSame('landscape', $orientation['current_value']);

        $resolution = $fieldsByKey->get('resolution_class');
        $this->assertSame('high', $resolution['current_value']);
    }
}

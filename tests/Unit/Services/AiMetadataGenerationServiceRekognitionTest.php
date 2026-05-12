<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Vision\AwsRekognitionVisionTagProvider;
use App\Services\AI\Vision\Contracts\VisionTagCandidateProvider;
use App\Services\AI\Vision\VisionTagCandidate;
use App\Services\AI\Vision\VisionTagCandidateResult;
use App\Services\AiMetadataGenerationService;
use App\Services\TenantBucketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for AiMetadataGenerationService when AWS Rekognition is the active
 * vision tag provider.
 *
 * Verifies that:
 *  - Tags are produced by Rekognition (and the OpenAI vision call is fields-only, never
 *    returns tags in the same call when fields exist)
 *  - The same sanitizer/category-ban/blocklist pipeline runs on Rekognition labels
 *  - AIAgentRun row is created with tokens=0 and per-image cost
 *  - tags-only path skips OpenAI entirely (no analyzeImage call)
 *  - Permanent failures degrade to zero tags; opt-in fallback runs OpenAI tags-only
 */
class AiMetadataGenerationServiceRekognitionTest extends TestCase
{
    use RefreshDatabase;

    protected $mockOpenAi;

    protected $mockRekognition;

    protected $mockBucketService;

    protected AiMetadataGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.metadata_tagging.vision_provider', 'aws_rekognition');
        Config::set('ai.metadata_tagging.aws_rekognition.enabled', true);
        Config::set('ai.metadata_tagging.aws_rekognition.cost_usd_per_image', 0.001);
        Config::set('ai.metadata_tagging.aws_rekognition.min_confidence', 70);
        Config::set('ai.metadata_tagging.aws_rekognition.max_labels', 20);
        Config::set('ai.metadata_tagging.aws_rekognition.feature_types', ['GENERAL_LABELS']);
        Config::set('ai.metadata_tagging.aws_rekognition.include_image_properties', false);
        Config::set('ai.metadata_tagging.rekognition_fallback_to_openai', false);

        $this->mockOpenAi = Mockery::mock(AIProviderInterface::class);
        $this->mockRekognition = Mockery::mock(VisionTagCandidateProvider::class);
        $this->mockBucketService = Mockery::mock(TenantBucketService::class);
        $this->mockBucketService->shouldReceive('getObjectContents')
            ->andReturn('fake-image-bytes');

        $this->service = new AiMetadataGenerationService(
            $this->mockOpenAi,
            null,
            $this->mockBucketService,
            $this->mockRekognition,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tags_only_path_uses_rekognition_and_skips_openai(): void
    {
        $asset = $this->createAssetWithCategory('Photography');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $this->mockOpenAi->shouldNotReceive('analyzeImage');
        $this->mockOpenAi->shouldNotReceive('calculateCost');

        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andReturn($this->buildRekognitionResult([
                ['Soccer', 0.92],
                ['Person', 0.91],
                ['Athlete', 0.95],
                ['Photography', 0.97], // category restatement — must be rejected
                ['Photo', 0.96],       // category alias — must be rejected
            ]));

        $results = $this->service->generateMetadata($asset);

        $this->assertSame(0, $results['candidates_created']);
        $this->assertGreaterThanOrEqual(3, $results['tags_created']);
        $this->assertSame(AwsRekognitionVisionTagProvider::PROVIDER_KEY, $results['vision_tag_provider']);
        $this->assertSame(0, $results['tokens_in']);
        $this->assertSame(0, $results['tokens_out']);
        $this->assertEqualsWithDelta(0.001, $results['cost'], 0.000001);

        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->pluck('tag')
            ->all();

        $this->assertContains('soccer', array_map('strtolower', $tags));
        $this->assertContains('athlete', array_map('strtolower', $tags));
        $this->assertNotContains('photography', array_map('strtolower', $tags));
        $this->assertNotContains('photo', array_map('strtolower', $tags));
    }

    public function test_combined_path_uses_openai_for_fields_only_and_rekognition_for_tags(): void
    {
        $asset = $this->createAssetWithCategory('Photography');
        $field = $this->createAiEligibleField('photo_type', $asset->tenant_id);
        $this->createFieldOption($field->id, 'studio');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        // OpenAI is asked for fields only — and it MUST not return tags (the prompt orders an empty array).
        $this->mockOpenAi->shouldReceive('analyzeImage')
            ->once()
            ->withArgs(function ($base64DataUrl, $prompt, $options) {
                $this->assertStringContainsString('STRUCTURED FIELDS', $prompt);
                $this->assertStringContainsString('"tags" array MUST be empty', $prompt);

                return true;
            })
            ->andReturn([
                'text' => json_encode(['fields' => ['photo_type' => ['value' => 'studio', 'confidence' => 0.95]], 'tags' => []]),
                'tokens_in' => 200,
                'tokens_out' => 80,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->mockOpenAi->shouldReceive('calculateCost')->andReturn(0.0007);

        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andReturn($this->buildRekognitionResult([
                ['Bourbon Bottle', 0.93],
                ['Label', 0.91],
            ]));

        $results = $this->service->generateMetadata($asset);

        $this->assertSame(1, $results['candidates_created']);
        $this->assertGreaterThanOrEqual(1, $results['tags_created']);
        $this->assertSame(AwsRekognitionVisionTagProvider::PROVIDER_KEY, $results['vision_tag_provider']);
        $this->assertEqualsWithDelta(0.0007 + 0.001, $results['cost'], 0.000001);

        // AIAgentRun for Rekognition recorded with tokens=0 and per-image unit metadata.
        $run = DB::table('ai_agent_runs')
            ->where('agent_id', 'metadata_image_tags_rekognition')
            ->where('tenant_id', $asset->tenant_id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(0, (int) $run->tokens_in);
        $this->assertSame(0, (int) $run->tokens_out);
        $this->assertEqualsWithDelta(0.001, (float) $run->estimated_cost, 0.000001);
        $meta = json_decode($run->metadata, true) ?? [];
        $this->assertSame('per_image', $meta['billing_type'] ?? null);
        $this->assertSame('image', $meta['unit_type'] ?? null);
        $this->assertSame(1, $meta['unit_count'] ?? null);
        $this->assertContains('GENERAL_LABELS', $meta['features'] ?? []);
    }

    public function test_logos_category_rejects_rekognition_logo_label(): void
    {
        $asset = $this->createAssetWithCategory('Logos', 'logos');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andReturn($this->buildRekognitionResult([
                ['Logo', 0.99],
                ['Brand Mark', 0.95],
                ['Wordmark', 0.94],
            ]));

        $this->service->generateMetadata($asset);

        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->pluck('tag')
            ->map(fn ($v) => strtolower($v))
            ->all();

        $this->assertNotContains('logo', $tags);
        $this->assertNotContains('brand mark', $tags);
        $this->assertContains('wordmark', $tags);
    }

    public function test_provider_failure_without_fallback_yields_zero_tags(): void
    {
        $asset = $this->createAssetWithCategory('Photography');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andThrow(new \Aws\Rekognition\Exception\RekognitionException(
                'Invalid S3 object',
                Mockery::mock(\Aws\CommandInterface::class),
                ['code' => 'InvalidS3ObjectException']
            ));

        $this->mockOpenAi->shouldNotReceive('analyzeImage');

        $results = $this->service->generateMetadata($asset);

        $this->assertSame(0, $results['tags_created']);
        $this->assertSame('attempted_empty', $results['ai_tag_inference_status']);
        $this->assertStringStartsWith('rekognition_error:', (string) ($results['ai_tag_inference_detail'] ?? ''));

        $run = DB::table('ai_agent_runs')
            ->where('agent_id', 'metadata_image_tags_rekognition')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
    }

    public function test_provider_failure_with_fallback_runs_openai_tags_only(): void
    {
        Config::set('ai.metadata_tagging.rekognition_fallback_to_openai', true);

        $asset = $this->createAssetWithCategory('Photography');
        $this->createSelectField('tags', $asset->tenant_id, [
            'type' => 'multiselect',
            'ai_eligible' => true,
        ]);

        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andThrow(new \Aws\Rekognition\Exception\RekognitionException(
                'Invalid S3 object',
                Mockery::mock(\Aws\CommandInterface::class),
                ['code' => 'InvalidS3ObjectException']
            ));

        $this->mockOpenAi->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode(['fields' => [], 'tags' => [['value' => 'soccer ball', 'confidence' => 0.95]]]),
                'tokens_in' => 80,
                'tokens_out' => 30,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->mockOpenAi->shouldReceive('calculateCost')->andReturn(0.00015);

        $results = $this->service->generateMetadata($asset);

        $this->assertGreaterThanOrEqual(1, $results['tags_created']);
        $tags = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->pluck('tag')
            ->all();
        $this->assertContains('soccer ball', $tags);
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: float}>  $labelsAndConfidence01
     */
    protected function buildRekognitionResult(array $labelsAndConfidence01): VisionTagCandidateResult
    {
        $candidates = [];
        $rawLabels = [];
        foreach ($labelsAndConfidence01 as [$name, $confidence01]) {
            $candidates[] = new VisionTagCandidate(
                value: $name,
                confidence: $confidence01,
                provider: 'aws_rekognition',
                evidence: sprintf('aws rekognition label: %s, confidence %.1f', $name, $confidence01 * 100),
                rawLabelName: $name,
            );
            $rawLabels[] = ['Name' => $name, 'Confidence' => $confidence01 * 100];
        }

        return new VisionTagCandidateResult(
            provider: 'aws_rekognition',
            model: 'rekognition-detect-labels',
            sourceType: 's3_object',
            sourceBucket: 'test-bucket',
            sourceKey: 'assets/orig/photo.jpg',
            sourceMime: 'image/jpeg',
            sourceAssetVersionId: null,
            sourceWidth: null,
            sourceHeight: null,
            rawResponse: ['Labels' => $rawLabels],
            candidates: $candidates,
            usage: [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'unit_type' => 'image',
                'unit_count' => 1,
                'estimated_cost_usd' => 0.001,
                'credits' => 0,
                'features' => ['GENERAL_LABELS'],
                'max_labels' => 20,
                'min_confidence' => 70.0,
            ],
        );
    }

    protected function createAssetWithCategory(string $categoryName = 'Photography', ?string $slug = null): Asset
    {
        $asset = $this->createAsset();
        $category = Category::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_type' => \App\Enums\AssetType::ASSET,
            'name' => $categoryName,
            'slug' => $slug ?? Str::slug($categoryName),
            'is_system' => false,
        ]);
        $asset->metadata = array_merge($asset->metadata ?? [], [
            'category_id' => $category->id,
            'thumbnails' => ['medium' => ['path' => 'assets/test/medium.jpg']],
        ]);
        $asset->thumbnail_status = \App\Enums\ThumbnailStatus::COMPLETED;
        $asset->save();

        return $asset;
    }

    protected function createAsset(): Asset
    {
        $tenant = Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Rek Tenant',
            'slug' => 'rek-tenant',
        ]);
        $brand = Brand::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'Rek Brand',
            'slug' => 'rek-brand',
        ]);
        $bucket = StorageBucket::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'test-bucket',
            'status' => \App\Enums\StorageBucketStatus::ACTIVE,
            'region' => 'us-east-1',
        ]);
        $session = UploadSession::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'storage_bucket_id' => $bucket->id,
            'status' => \App\Enums\UploadStatus::COMPLETED,
            'type' => \App\Enums\UploadType::DIRECT,
            'expected_size' => 1024,
            'uploaded_size' => 1024,
        ]);

        return Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'photo.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/orig/photo.jpg',
            'metadata' => [],
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);
    }

    protected function createAiEligibleField(string $key, int $tenantId, array $overrides = []): \stdClass
    {
        return $this->createSelectField($key, $tenantId, array_merge([
            'ai_eligible' => true,
        ], $overrides));
    }

    protected function createSelectField(string $key, int $tenantId, array $overrides = []): \stdClass
    {
        $fieldData = array_merge([
            'key' => $key,
            'system_label' => ucfirst($key),
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'tenant',
            'tenant_id' => $tenantId,
            'is_user_editable' => true,
            'population_mode' => 'manual',
            'is_filterable' => true,
            'ai_eligible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
        $fieldId = DB::table('metadata_fields')->insertGetId($fieldData);

        return (object) array_merge($fieldData, ['id' => $fieldId]);
    }

    protected function createFieldOption(int $fieldId, string $value): \stdClass
    {
        $optionId = DB::table('metadata_options')->insertGetId([
            'metadata_field_id' => $fieldId,
            'value' => $value,
            'system_label' => ucfirst($value),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) ['id' => $optionId, 'metadata_field_id' => $fieldId, 'value' => $value];
    }
}

<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiMetadataSuggestionJob;
use App\Jobs\AiTagAutoApplyJob;
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
use App\Services\TenantBucketService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end verification that the AI bus chain runs in correct succession when
 * AWS Rekognition is the active vision tag provider.
 *
 * Sequence under test (sync queue, real Bus::chain dispatch):
 *
 *   1. Asset arrives with thumbnail_status = COMPLETED
 *      (i.e. the parallel pipeline chain GenerateThumbnailsJob → … has finished)
 *   2. AiMetadataGenerationJob:
 *        - waitForThumbnail() returns immediately (COMPLETED)
 *        - calls Rekognition for tag candidates  → asset_tag_candidates rows
 *        - calls OpenAI vision (fields-only)     → asset_metadata_candidates rows
 *        - records AIAgentRun: metadata_image_tags_rekognition (tokens=0, per-image)
 *   3. AiTagAutoApplyJob (next in chain) processes asset_tag_candidates
 *   4. AiMetadataSuggestionJob (next in chain) reads asset_metadata_candidates and
 *      writes asset.metadata['_ai_suggestions'] for the field metadata UI
 *
 * If any step is out of order or skipped, the assertions here fail.
 */
class RekognitionAiBusChainSuccessionTest extends TestCase
{
    use RefreshDatabase;

    protected $mockOpenAi;

    protected $mockRekognition;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.metadata_tagging.vision_provider', 'aws_rekognition');
        Config::set('ai.metadata_tagging.aws_rekognition.enabled', true);
        Config::set('ai.metadata_tagging.aws_rekognition.cost_usd_per_image', 0.001);
        Config::set('ai.metadata_tagging.aws_rekognition.feature_types', ['GENERAL_LABELS']);
        Config::set('ai.metadata_tagging.aws_rekognition.include_image_properties', false);
        Config::set('ai.metadata_tagging.aws_rekognition.min_confidence', 70);
        Config::set('ai.metadata_tagging.rekognition_fallback_to_openai', false);
        Config::set('ai_metadata.suggestions.enabled', true);
        // Sync queue so Bus::chain runs every job inline (no real workers).
        Config::set('queue.default', 'sync');

        $this->mockOpenAi = Mockery::mock(AIProviderInterface::class);
        $this->app->instance(AIProviderInterface::class, $this->mockOpenAi);

        $bucket = Mockery::mock(TenantBucketService::class);
        $bucket->shouldReceive('getObjectContents')->andReturn('fake-image-bytes');
        $this->app->instance(TenantBucketService::class, $bucket);

        $this->mockRekognition = Mockery::mock(VisionTagCandidateProvider::class);
        $this->app->instance(VisionTagCandidateProvider::class, $this->mockRekognition);
        $this->app->instance(AwsRekognitionVisionTagProvider::class, $this->mockRekognition);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_thumbnail_then_metadata_then_field_suggestions_run_in_succession(): void
    {
        $asset = $this->createAssetWithThumbnailReady();

        // Field-only OpenAI vision call — fields populated, no tags.
        $this->mockOpenAi->shouldReceive('analyzeImage')
            ->once()
            ->withArgs(function ($_imageDataUrl, $prompt) {
                $this->assertStringContainsString('STRUCTURED FIELDS', $prompt);
                $this->assertStringContainsString('"tags" array MUST be empty', $prompt);
                $this->assertStringNotContainsString('GENERAL TAGS', $prompt);

                return true;
            })
            ->andReturn([
                'text' => json_encode([
                    'fields' => [
                        'photo_type' => ['value' => 'studio', 'confidence' => 0.96],
                    ],
                    'tags' => [],
                ]),
                'tokens_in' => 200,
                'tokens_out' => 80,
                'model' => 'gpt-4o-mini',
                'metadata' => [],
            ]);
        $this->mockOpenAi->shouldReceive('calculateCost')
            ->andReturn(0.0007);

        // Rekognition tag candidates including a category restatement that must be filtered.
        $this->mockRekognition->shouldReceive('getProviderName')->andReturn('aws_rekognition');
        $this->mockRekognition->shouldReceive('detectTagsForAsset')
            ->once()
            ->andReturn(new VisionTagCandidateResult(
                provider: 'aws_rekognition',
                model: 'rekognition-detect-labels',
                sourceType: 's3_object',
                sourceBucket: 'test-bucket',
                sourceKey: 'assets/test/medium.jpg',
                sourceMime: 'image/jpeg',
                sourceAssetVersionId: null,
                sourceWidth: null,
                sourceHeight: null,
                rawResponse: ['Labels' => [
                    ['Name' => 'Bourbon Bottle', 'Confidence' => 95.0],
                    ['Name' => 'Label', 'Confidence' => 92.5],
                    ['Name' => 'Photography', 'Confidence' => 99.0], // category restatement
                ]],
                candidates: [
                    new VisionTagCandidate('Bourbon Bottle', 0.95, 'aws_rekognition', evidence: 'aws rekognition label: Bourbon Bottle, confidence 95.0'),
                    new VisionTagCandidate('Label', 0.925, 'aws_rekognition', evidence: 'aws rekognition label: Label, confidence 92.5'),
                    new VisionTagCandidate('Photography', 0.99, 'aws_rekognition', evidence: 'aws rekognition label: Photography, confidence 99.0'),
                ],
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
            ));

        Bus::chain([
            new AiMetadataGenerationJob($asset->id),
            new AiTagAutoApplyJob($asset->id),
            new AiMetadataSuggestionJob($asset->id),
        ])->dispatch();

        $asset->refresh();
        $meta = $asset->metadata;

        // 1. AiMetadataGenerationJob completed — pipeline status flag is set.
        $this->assertTrue($meta['ai_tagging_completed'] ?? false, 'AI tagging not marked complete');
        $this->assertNotNull($meta['_ai_metadata_generated_at'] ?? null, 'Generated-at not stamped');
        $this->assertSame('completed', $meta['_ai_metadata_status'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($meta['ai_tag_candidates_created'] ?? 0));

        // 2. Tag candidates were created via Rekognition; category restatement filtered out.
        $tagCandidateRows = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->pluck('tag')
            ->all();
        $lower = array_map('strtolower', $tagCandidateRows);
        $this->assertContains('bourbon bottle', $lower);
        $this->assertContains('label', $lower);
        $this->assertNotContains('photography', $lower, 'Category restatement should be rejected by sanitizer');

        // 3. Field candidates were created via OpenAI fields-only call.
        $fieldCandidate = DB::table('asset_metadata_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->first();
        $this->assertNotNull($fieldCandidate, 'OpenAI fields-only call should populate asset_metadata_candidates');
        $this->assertSame('"studio"', $fieldCandidate->value_json);

        // 4. Two AIAgentRun rows: one OpenAI fields, one Rekognition tags (tokens=0, per-image).
        $rekRun = DB::table('ai_agent_runs')
            ->where('agent_id', 'metadata_image_tags_rekognition')
            ->where('tenant_id', $asset->tenant_id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($rekRun, 'Rekognition AIAgentRun should be recorded');
        $this->assertSame('success', $rekRun->status);
        $this->assertSame(0, (int) $rekRun->tokens_in);
        $this->assertSame(0, (int) $rekRun->tokens_out);
        $this->assertEqualsWithDelta(0.001, (float) $rekRun->estimated_cost, 0.000001);
        $rekMeta = json_decode($rekRun->metadata, true) ?? [];
        $this->assertSame('per_image', $rekMeta['billing_type'] ?? null);
        $this->assertSame('image', $rekMeta['unit_type'] ?? null);

        $openAiRun = DB::table('ai_agent_runs')
            ->where('agent_id', 'metadata_generator')
            ->where('tenant_id', $asset->tenant_id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($openAiRun, 'OpenAI metadata_generator AIAgentRun should be recorded');
        $this->assertSame('success', $openAiRun->status);
        $this->assertGreaterThan(0, (int) $openAiRun->tokens_in);

        // 5. AiMetadataSuggestionJob (the field metadata bus link) ran AFTER the candidates were saved.
        $this->assertTrue($meta['ai_metadata_suggestions_completed'] ?? false, 'Field metadata suggestion job did not complete');
        $this->assertNotNull($meta['ai_metadata_suggestions_completed_at'] ?? null);
        // suggestions count >= 1 because the photo_type candidate (0.96) clears the 0.90 threshold.
        $this->assertGreaterThanOrEqual(1, (int) ($meta['ai_metadata_suggestions_count'] ?? 0));

        $aiSuggestions = $meta['_ai_suggestions'] ?? [];
        $this->assertArrayHasKey('photo_type', $aiSuggestions, 'Field metadata suggestion missing for photo_type');
        $this->assertSame('studio', $aiSuggestions['photo_type']['value'] ?? null);
    }

    public function test_chain_skips_field_suggestion_when_thumbnail_was_unavailable(): void
    {
        // Asset with explicit thumbnail-unavailable skip; the chain must not falsely mark suggestions complete.
        $asset = $this->createAssetWithThumbnailReady();
        $asset->thumbnail_status = \App\Enums\ThumbnailStatus::FAILED;
        $meta = $asset->metadata;
        unset($meta['thumbnails']);
        $asset->update(['thumbnail_status' => \App\Enums\ThumbnailStatus::FAILED, 'metadata' => $meta]);

        $this->mockOpenAi->shouldNotReceive('analyzeImage');
        $this->mockRekognition->shouldNotReceive('detectTagsForAsset');
        $this->mockRekognition->shouldReceive('getProviderName')->andReturn('aws_rekognition');

        Bus::chain([
            new AiMetadataGenerationJob($asset->id),
            new AiMetadataSuggestionJob($asset->id),
        ])->dispatch();

        $asset->refresh();
        $this->assertTrue($asset->metadata['_ai_metadata_skipped'] ?? false);
        $this->assertSame('thumbnail_unavailable', $asset->metadata['_ai_metadata_skip_reason'] ?? null);
        // Suggestion job sees the thumbnail_unavailable poison flag and bails without marking completed.
        $this->assertFalse($asset->metadata['ai_metadata_suggestions_completed'] ?? false);
    }

    // ---- helpers --------------------------------------------------------

    protected function createAssetWithThumbnailReady(): Asset
    {
        $tenant = Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Chain Tenant',
            'slug' => 'chain-tenant',
        ]);
        $brand = Brand::firstOrCreate(['id' => 1, 'tenant_id' => $tenant->id], [
            'name' => 'Chain Brand',
            'slug' => 'chain-brand',
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
        $category = Category::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'asset_type' => \App\Enums\AssetType::ASSET,
            'name' => 'Photography',
            'slug' => 'photography',
            'is_system' => false,
        ]);

        $asset = Asset::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'upload_session_id' => $session->id,
            'storage_bucket_id' => $bucket->id,
            'mime_type' => 'image/jpeg',
            'original_filename' => 'photo.jpg',
            'size_bytes' => 1024,
            'storage_root_path' => 'assets/test/photo.jpg',
            'metadata' => [
                'category_id' => $category->id,
                'thumbnails' => ['medium' => ['path' => 'assets/test/medium.jpg']],
            ],
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
        ]);

        // photo_type: ai-eligible, user-editable, has options. Suggestion service requires all three.
        $fieldId = DB::table('metadata_fields')->insertGetId([
            'tenant_id' => $tenant->id,
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'tenant',
            'is_user_editable' => true,
            'population_mode' => 'manual',
            'is_filterable' => true,
            'ai_eligible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('metadata_options')->insert([
            'metadata_field_id' => $fieldId,
            'value' => 'studio',
            'system_label' => 'Studio',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // tags: AI-eligible multiselect so tagInferenceDesired() returns true.
        DB::table('metadata_fields')->insert([
            'tenant_id' => $tenant->id,
            'key' => 'tags',
            'system_label' => 'Tags',
            'type' => 'multiselect',
            'applies_to' => 'all',
            'scope' => 'tenant',
            'is_user_editable' => true,
            'population_mode' => 'manual',
            'is_filterable' => true,
            'ai_eligible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $asset;
    }
}

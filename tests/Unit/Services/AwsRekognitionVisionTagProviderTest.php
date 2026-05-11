<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\UploadSession;
use App\Services\AI\Vision\AwsRekognitionVisionTagProvider;
use App\Services\AI\Vision\VisionTagCandidateResult;
use App\Services\TenantBucketService;
use Aws\Rekognition\RekognitionClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AWS Rekognition vision tag provider — image-tags-only path.
 *
 * Validates the provider sends S3Object (no presigned URLs), respects supported formats,
 * routes IMAGE_PROPERTIES off by default, computes per-image cost from config, and
 * surfaces raw Rekognition labels as VisionTagCandidate objects with confidence scaled
 * to 0–1. Sanitizer/category-ban behavior is verified end-to-end in
 * AiMetadataGenerationServiceRekognitionTest.
 */
class AwsRekognitionVisionTagProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.metadata_tagging.aws_rekognition.enabled', true);
        Config::set('ai.metadata_tagging.aws_rekognition.region', 'us-east-1');
        Config::set('ai.metadata_tagging.aws_rekognition.feature_types', ['GENERAL_LABELS']);
        Config::set('ai.metadata_tagging.aws_rekognition.max_labels', 20);
        Config::set('ai.metadata_tagging.aws_rekognition.min_confidence', 70);
        Config::set('ai.metadata_tagging.aws_rekognition.cost_usd_per_image', 0.001);
        Config::set('ai.metadata_tagging.aws_rekognition.include_image_properties', false);
        Config::set('ai.metadata_tagging.aws_rekognition.image_properties_cost_usd_per_image', 0.00075);
        Config::set('ai.metadata_tagging.aws_rekognition.minimum_billable_credits', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_jpeg_original_is_sent_directly_via_s3_object(): void
    {
        $asset = $this->createImageAsset('jpg', 'image/jpeg', 'assets/orig/photo.jpg');

        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse());

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $result = $provider->detectTagsForAsset($asset);

        $this->assertInstanceOf(VisionTagCandidateResult::class, $result);
        $this->assertSame('aws_rekognition', $result->provider);
        $this->assertSame('rekognition-detect-labels', $result->model);
        $this->assertSame('s3_object', $result->sourceType);
        $this->assertSame($asset->storageBucket->name, $result->sourceBucket);
        $this->assertSame('assets/orig/photo.jpg', $result->sourceKey);
    }

    public function test_unsupported_original_falls_back_to_jpeg_thumbnail(): void
    {
        $asset = $this->createImageAsset('psd', 'image/vnd.adobe.photoshop', 'assets/orig/source.psd');
        $meta = $asset->metadata ?? [];
        $meta['thumbnails'] = ['medium' => ['path' => 'assets/preview/source.jpg']];
        $asset->update(['metadata' => $meta]);

        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse(), function (array $params) {
            $this->assertSame('assets/preview/source.jpg', $params['Image']['S3Object']['Name']);
            $this->assertSame('test-bucket', $params['Image']['S3Object']['Bucket']);
            $this->assertSame(['GENERAL_LABELS'], $params['Features']);
        });

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $result = $provider->detectTagsForAsset($asset->fresh());

        $this->assertSame('assets/preview/source.jpg', $result->sourceKey);
    }

    public function test_image_properties_are_disabled_by_default(): void
    {
        $asset = $this->createImageAsset('jpg', 'image/jpeg', 'assets/orig/photo.jpg');

        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse(), function (array $params) {
            $this->assertSame(['GENERAL_LABELS'], $params['Features']);
            $this->assertNotContains('IMAGE_PROPERTIES', $params['Features']);
        });

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);
        $result = $provider->detectTagsForAsset($asset);

        $this->assertEqualsWithDelta(0.001, $result->usage['estimated_cost_usd'], 0.000001);
    }

    public function test_image_properties_can_be_enabled_with_added_cost(): void
    {
        Config::set('ai.metadata_tagging.aws_rekognition.include_image_properties', true);

        $asset = $this->createImageAsset('png', 'image/png', 'assets/orig/photo.png');

        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse(), function (array $params) {
            $this->assertContains('IMAGE_PROPERTIES', $params['Features']);
        });

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);
        $result = $provider->detectTagsForAsset($asset);

        $this->assertEqualsWithDelta(0.00175, $result->usage['estimated_cost_usd'], 0.000001);
    }

    public function test_returns_zero_tokens_and_one_image_unit(): void
    {
        $asset = $this->createImageAsset('jpg', 'image/jpeg', 'assets/orig/photo.jpg');
        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse());
        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $result = $provider->detectTagsForAsset($asset);

        $this->assertSame(0, $result->usage['input_tokens']);
        $this->assertSame(0, $result->usage['output_tokens']);
        $this->assertSame(0, $result->usage['total_tokens']);
        $this->assertSame('image', $result->usage['unit_type']);
        $this->assertSame(1, $result->usage['unit_count']);
    }

    public function test_no_supported_source_throws(): void
    {
        $asset = $this->createImageAsset('psd', 'image/vnd.adobe.photoshop', 'assets/orig/source.psd');

        $client = Mockery::mock(RekognitionClient::class);
        $client->shouldNotReceive('detectLabels');

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $this->expectException(\RuntimeException::class);
        $provider->detectTagsForAsset($asset);
    }

    public function test_invalid_s3_object_exception_propagates(): void
    {
        $asset = $this->createImageAsset('jpg', 'image/jpeg', 'assets/orig/photo.jpg');

        $client = Mockery::mock(RekognitionClient::class);
        $client->shouldReceive('detectLabels')
            ->once()
            ->andThrow(new \Aws\Rekognition\Exception\RekognitionException(
                'Invalid S3 object',
                Mockery::mock(\Aws\CommandInterface::class),
                ['code' => 'InvalidS3ObjectException']
            ));

        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $this->expectException(\Aws\Rekognition\Exception\RekognitionException::class);
        $provider->detectTagsForAsset($asset);
    }

    /**
     * Confidence is converted from 0–100 to 0–1 and label name is preserved as raw_label_name.
     */
    public function test_candidates_use_normalized_confidence_and_keep_raw_label_evidence(): void
    {
        $asset = $this->createImageAsset('jpg', 'image/jpeg', 'assets/orig/photo.jpg');
        $client = $this->mockRekognitionClientReturning($this->soccerLabelsResponse());
        $provider = new AwsRekognitionVisionTagProvider(app(TenantBucketService::class), $client);

        $result = $provider->detectTagsForAsset($asset);

        $this->assertNotEmpty($result->candidates);
        $first = $result->candidates[0];
        $this->assertEqualsWithDelta(0.982, $first->confidence, 0.0001);
        $this->assertSame('Soccer', $first->rawLabelName);
        $this->assertStringContainsString('aws rekognition label: Soccer', (string) $first->evidence);
        $this->assertContains('Sport', $first->rawCategories);
        $this->assertContains('Sports Equipment', $first->rawParents);
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function soccerLabelsResponse(): array
    {
        return [
            'Labels' => [
                [
                    'Name' => 'Soccer',
                    'Confidence' => 98.2,
                    'Categories' => [['Name' => 'Sport']],
                    'Parents' => [['Name' => 'Sports Equipment']],
                ],
                [
                    'Name' => 'Person',
                    'Confidence' => 91.7,
                    'Categories' => [['Name' => 'Person']],
                    'Parents' => [],
                ],
            ],
        ];
    }

    protected function mockRekognitionClientReturning(array $awsResultData, ?callable $assertCallback = null): RekognitionClient
    {
        $client = Mockery::mock(RekognitionClient::class);
        $expectation = $client->shouldReceive('detectLabels')->once();
        if ($assertCallback !== null) {
            $expectation->with(Mockery::on(function ($params) use ($assertCallback) {
                $assertCallback($params);

                return true;
            }));
        }
        $expectation->andReturn(new Result($awsResultData));

        return $client;
    }

    protected function createImageAsset(string $extension, string $mime, string $storageRootPath): Asset
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
            'mime_type' => $mime,
            'original_filename' => 'photo.'.$extension,
            'size_bytes' => 1024,
            'storage_root_path' => $storageRootPath,
            'metadata' => [],
            'status' => \App\Enums\AssetStatus::VISIBLE,
            'type' => \App\Enums\AssetType::ASSET,
            'thumbnail_status' => \App\Enums\ThumbnailStatus::COMPLETED,
        ]);
    }
}

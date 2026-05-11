<?php

namespace App\Services\AI\Vision;

use App\Models\Asset;
use App\Models\Category;
use App\Services\AI\Vision\Contracts\VisionTagCandidateProvider;
use App\Services\TenantBucketService;
use App\Support\ThumbnailMetadata;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AWS Rekognition DetectLabels provider for free-form image tag candidates.
 *
 * Why this exists:
 * - OpenAI vision was hallucinating tags on packaging/sell-sheet/render assets.
 *   Rekognition is conservative, billed per image (not tokens), and we already
 *   have all assets on S3 with IAM-authenticated workers.
 *
 * Source-image rules (image tags only — never run on non-images):
 * - Original is sent only when it is JPEG or PNG (Rekognition supported set).
 * - Otherwise the existing generated medium thumbnail or preview is sent
 *   (those are JPEG/PNG/WEBP — we filter to jpg/png and skip otherwise).
 * - Always direct S3Object {Bucket, Name}: no presigned URLs, no CloudFront.
 * - Bucket region must match Rekognition region (validated at config time).
 *
 * Sanitation:
 * - We DO NOT save raw Rekognition labels. The caller runs every candidate through
 *   the existing canonicalize → phrase-aliases → sanitizer → category-ban →
 *   blocklist → duplicate-check pipeline before persisting.
 * - Parents/Categories are recorded as evidence for admin diagnostics only.
 *
 * Cost:
 * - Per-image USD from config (DetectLabels GENERAL_LABELS); IMAGE_PROPERTIES is
 *   off by default because AWS charges it separately.
 * - Tokens are 0/0 (Rekognition is not a token-billed provider).
 */
class AwsRekognitionVisionTagProvider implements VisionTagCandidateProvider
{
    public const PROVIDER_KEY = 'aws_rekognition';

    public const MODEL_KEY = 'rekognition-detect-labels';

    /** Rekognition supports only JPEG/PNG for direct upload. We gate on extension+mime. */
    protected const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    protected const SUPPORTED_MIMES = ['image/jpeg', 'image/png'];

    public function __construct(
        protected TenantBucketService $bucketService,
        protected ?RekognitionClient $client = null,
    ) {
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_KEY;
    }

    /**
     * Test seam: allow injecting a mocked RekognitionClient (no AWS network in unit tests).
     */
    public function setClient(RekognitionClient $client): void
    {
        $this->client = $client;
    }

    public function detectTagsForAsset(Asset $asset, ?Category $category = null): VisionTagCandidateResult
    {
        $cfg = (array) config('ai.metadata_tagging.aws_rekognition', []);
        if (! ($cfg['enabled'] ?? true)) {
            throw new RuntimeException('AWS Rekognition vision tag provider is disabled in config (ai.metadata_tagging.aws_rekognition.enabled).');
        }

        $bucket = $asset->storageBucket;
        if (! $bucket || ! is_string($bucket->name) || $bucket->name === '') {
            throw new RuntimeException(sprintf(
                'Asset %s has no resolvable storage bucket — Rekognition requires direct S3 access.',
                (string) $asset->id
            ));
        }

        $source = $this->resolveBestSourceImage($asset);
        if ($source === null) {
            throw new RuntimeException(sprintf(
                'Asset %s has no JPEG/PNG source (original or generated preview) suitable for Rekognition.',
                (string) $asset->id
            ));
        }

        $features = $this->resolveFeatureTypes($cfg);
        $maxLabels = max(1, min(100, (int) ($cfg['max_labels'] ?? 20)));
        $minConfidence = max(0.0, min(100.0, (float) ($cfg['min_confidence'] ?? 70)));

        $client = $this->client ?? $this->createDefaultClient($cfg);

        $logCtx = [
            'asset_id' => (string) $asset->id,
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'category_id' => $category?->id,
            'category_name' => $category?->name,
            'category_slug' => $category?->slug,
            'provider' => self::PROVIDER_KEY,
            'model' => self::MODEL_KEY,
            'source_bucket' => $bucket->name,
            'source_key' => $source['key'],
            'source_mime' => $source['mime'],
            'source_extension' => $source['extension'],
            'source_origin' => $source['origin'],
            'features' => $features,
            'max_labels' => $maxLabels,
            'min_confidence' => $minConfidence,
        ];

        Log::debug('[AwsRekognitionVisionTagProvider] Calling DetectLabels', $logCtx);

        $params = [
            'Image' => [
                'S3Object' => [
                    'Bucket' => $bucket->name,
                    'Name' => $source['key'],
                ],
            ],
            'Features' => $features,
            'MaxLabels' => $maxLabels,
            'MinConfidence' => $minConfidence,
        ];

        $response = $client->detectLabels($params);
        $rawResponse = $this->normalizeResponse($response);

        $candidates = $this->convertLabelsToCandidates($rawResponse);

        $costUsd = (float) ($cfg['cost_usd_per_image'] ?? 0.001);
        if (in_array('IMAGE_PROPERTIES', $features, true)) {
            $costUsd += (float) ($cfg['image_properties_cost_usd_per_image'] ?? 0.0);
        }

        Log::info('[AwsRekognitionVisionTagProvider] DetectLabels success', array_merge($logCtx, [
            'raw_label_count' => count($rawResponse['Labels'] ?? []),
            'candidate_count' => count($candidates),
            'estimated_cost_usd' => $costUsd,
        ]));

        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'unit_type' => 'image',
            'unit_count' => 1,
            'estimated_cost_usd' => $costUsd,
            'credits' => max(0, (int) ($cfg['minimum_billable_credits'] ?? 0)),
            'features' => $features,
            'max_labels' => $maxLabels,
            'min_confidence' => $minConfidence,
        ];

        return new VisionTagCandidateResult(
            provider: self::PROVIDER_KEY,
            model: self::MODEL_KEY,
            sourceType: 's3_object',
            sourceBucket: $bucket->name,
            sourceKey: $source['key'],
            sourceMime: $source['mime'],
            sourceAssetVersionId: $asset->current_asset_version_id ?? null,
            sourceWidth: $source['width'],
            sourceHeight: $source['height'],
            rawResponse: $rawResponse,
            candidates: $candidates,
            usage: $usage,
        );
    }

    /**
     * Build the candidate list. We trust nothing — confidence converted to 0–1,
     * categories/parents kept as evidence for admin/debug only (never as tag values).
     *
     * @param  array<string, mixed>  $rawResponse
     * @return list<VisionTagCandidate>
     */
    protected function convertLabelsToCandidates(array $rawResponse): array
    {
        $labels = $rawResponse['Labels'] ?? [];
        if (! is_array($labels)) {
            return [];
        }

        $out = [];
        foreach ($labels as $label) {
            if (! is_array($label)) {
                continue;
            }
            $name = $label['Name'] ?? null;
            $confidence = $label['Confidence'] ?? null;
            if (! is_string($name) || trim($name) === '' || ! is_numeric($confidence)) {
                continue;
            }
            $confidence01 = max(0.0, min(1.0, ((float) $confidence) / 100.0));

            $categories = [];
            foreach (($label['Categories'] ?? []) as $cat) {
                if (is_array($cat) && isset($cat['Name']) && is_string($cat['Name'])) {
                    $categories[] = $cat['Name'];
                }
            }
            $parents = [];
            foreach (($label['Parents'] ?? []) as $parent) {
                if (is_array($parent) && isset($parent['Name']) && is_string($parent['Name'])) {
                    $parents[] = $parent['Name'];
                }
            }

            $out[] = new VisionTagCandidate(
                value: $name,
                confidence: $confidence01,
                provider: self::PROVIDER_KEY,
                evidence: sprintf(
                    'aws rekognition label: %s, confidence %.1f',
                    $name,
                    (float) $confidence
                ),
                rawLabelName: $name,
                rawCategories: $categories,
                rawParents: $parents,
            );
        }

        return $out;
    }

    /**
     * Pick the best S3 object to send to Rekognition.
     *
     * Order of preference:
     *   1. Original if it's JPEG/PNG (best fidelity)
     *   2. Generated medium thumbnail / preview if it's JPEG/PNG
     *
     * Returns null when nothing suitable is available — caller should not retry forever.
     *
     * @return array{key: string, mime: string, extension: string, width: int|null, height: int|null, origin: string}|null
     */
    protected function resolveBestSourceImage(Asset $asset): ?array
    {
        $originalKey = (string) ($asset->storage_root_path ?? '');
        $originalMime = strtolower((string) ($asset->mime_type ?? ''));
        $originalExt = strtolower(pathinfo((string) ($asset->original_filename ?? $originalKey), PATHINFO_EXTENSION));
        if ($originalKey !== ''
            && $this->isSupportedExtension($originalExt)
            && $this->isSupportedMime($originalMime)
        ) {
            return [
                'key' => $originalKey,
                'mime' => $originalMime !== '' ? $originalMime : $this->inferMimeFromExtension($originalExt),
                'extension' => $originalExt,
                'width' => is_numeric($asset->width ?? null) ? (int) $asset->width : null,
                'height' => is_numeric($asset->height ?? null) ? (int) $asset->height : null,
                'origin' => 'original',
            ];
        }

        $metadata = $asset->metadata ?? [];

        $previewKey = (string) (ThumbnailMetadata::stylePath($metadata, 'medium') ?? '');
        $previewExt = strtolower(pathinfo($previewKey, PATHINFO_EXTENSION));
        if ($previewKey !== '' && $this->isSupportedExtension($previewExt)) {
            return [
                'key' => $previewKey,
                'mime' => $this->inferMimeFromExtension($previewExt),
                'extension' => $previewExt,
                'width' => $this->extractDim($metadata, ['thumbnail_dimensions', 'medium', 'width']),
                'height' => $this->extractDim($metadata, ['thumbnail_dimensions', 'medium', 'height']),
                'origin' => 'thumbnail_medium',
            ];
        }

        $rasterKey = (string) (ThumbnailMetadata::previewPath($metadata) ?? '');
        $rasterExt = strtolower(pathinfo($rasterKey, PATHINFO_EXTENSION));
        if ($rasterKey !== '' && $this->isSupportedExtension($rasterExt)) {
            return [
                'key' => $rasterKey,
                'mime' => $this->inferMimeFromExtension($rasterExt),
                'extension' => $rasterExt,
                'width' => null,
                'height' => null,
                'origin' => 'preview_raster',
            ];
        }

        return null;
    }

    protected function isSupportedExtension(string $extension): bool
    {
        return in_array(strtolower(trim($extension)), self::SUPPORTED_EXTENSIONS, true);
    }

    protected function isSupportedMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            return true;
        }

        return in_array($mime, self::SUPPORTED_MIMES, true);
    }

    protected function inferMimeFromExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<int|string, mixed>  $metadata
     * @param  list<string>  $path
     */
    protected function extractDim(array $metadata, array $path): ?int
    {
        $cur = $metadata;
        foreach ($path as $key) {
            if (! is_array($cur) || ! array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }

        return is_numeric($cur) ? (int) $cur : null;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    protected function resolveFeatureTypes(array $cfg): array
    {
        $features = $cfg['feature_types'] ?? ['GENERAL_LABELS'];
        $features = is_array($features) ? array_values(array_unique(array_filter(array_map('strval', $features)))) : ['GENERAL_LABELS'];

        if ($features === []) {
            $features = ['GENERAL_LABELS'];
        }

        if (! ($cfg['include_image_properties'] ?? false)) {
            $features = array_values(array_filter($features, fn (string $f) => $f !== 'IMAGE_PROPERTIES'));
        } elseif (! in_array('IMAGE_PROPERTIES', $features, true)) {
            $features[] = 'IMAGE_PROPERTIES';
        }

        return $features;
    }

    /**
     * Construct a default RekognitionClient honoring config region + ambient AWS credentials
     * (env / IAM role). We deliberately do not pass keys here — leave it to the SDK chain.
     *
     * @param  array<string, mixed>  $cfg
     */
    protected function createDefaultClient(array $cfg): RekognitionClient
    {
        $region = (string) ($cfg['region'] ?? config('filesystems.disks.s3.region', 'us-east-1'));

        return new RekognitionClient([
            'version' => 'latest',
            'region' => $region,
        ]);
    }

    /**
     * The AWS SDK returns Result objects (\ArrayAccess). Normalize to plain array for raw_response storage.
     *
     * @param  mixed  $response
     * @return array<string, mixed>
     */
    protected function normalizeResponse($response): array
    {
        if (is_array($response)) {
            return $response;
        }
        if ($response instanceof \Aws\Result) {
            return $response->toArray();
        }
        if ($response instanceof \ArrayAccess || is_iterable($response)) {
            $out = [];
            foreach ($response as $k => $v) {
                $out[$k] = $v;
            }

            return $out;
        }

        return [];
    }
}

<?php

namespace App\Assets\Metadata;

use App\Assets\Metadata\Extractors\AudioMetadataExtractor;
use App\Assets\Metadata\Extractors\ImageMetadataExtractor;
use App\Assets\Metadata\Extractors\PdfMetadataExtractor;
use App\Assets\Metadata\Extractors\VideoMetadataExtractor;
use App\Models\Asset;
use App\Models\AssetMetadataPayload;
use App\Models\AssetVersion;
use App\Services\TenantBucketService;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates Layer B (payload) and Layer C (index) from embedded file metadata.
 * Best-effort: callers should catch/log; must not fail the asset pipeline.
 *
 * Always persists payload + rebuilds the derived index so stale rows are removed when extractors
 * return less data (e.g. ffprobe missing on a later run).
 */
class EmbeddedMetadataExtractionService
{
    /** @var list<\App\Assets\Metadata\Extractors\Contracts\EmbeddedMetadataExtractor> */
    protected array $extractors;

    public function __construct(
        protected EmbeddedMetadataRegistry $registry,
        protected EmbeddedMetadataNormalizer $normalizer,
        protected EmbeddedMetadataIndexBuilder $indexBuilder,
        protected EmbeddedMetadataSystemMapper $systemMapper,
        ?array $extractors = null
    ) {
        $this->extractors = $extractors ?? [
            new ImageMetadataExtractor,
            new PdfMetadataExtractor,
            new VideoMetadataExtractor,
            new AudioMetadataExtractor,
        ];
    }

    /**
     * Download → extract → normalize → persist payload → rebuild index → optional system mapping.
     */
    public function extractAndPersist(Asset $asset, ?AssetVersion $version = null): void
    {
        $mime = $version ? $version->mime_type : $asset->mime_type;
        $ext = strtolower((string) pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION));
        $storagePath = $version ? $version->file_path : $asset->storage_root_path;

        $merged = $this->emptyBuckets();
        $otherWarnings = [];

        if (! $storagePath || ! $mime) {
            $otherWarnings['skipped'] = 'missing_storage_path_or_mime';
            $this->persistNormalizedState($asset, $merged, $otherWarnings);

            return;
        }

        $tempPath = null;
        try {
            $tempPath = $this->downloadOriginalToTemp($asset, $storagePath);
            if (! $tempPath || ! is_readable($tempPath)) {
                $otherWarnings['download'] = $tempPath ? 'unreadable' : 'failed_or_empty';
            } else {
                foreach ($this->extractors as $extractor) {
                    if (! $extractor->supports($mime, $ext)) {
                        continue;
                    }
                    try {
                        $partial = $extractor->extract($tempPath, $mime, $ext);
                        $this->mergeBuckets($merged, $partial);
                    } catch (\Throwable $e) {
                        Log::warning('[EmbeddedMetadataExtractionService] Extractor failed (continuing)', [
                            'asset_id' => $asset->id,
                            'extractor' => $extractor::class,
                            'message' => $e->getMessage(),
                        ]);
                        $otherWarnings['extractor_'.class_basename($extractor)] = $e->getMessage();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[EmbeddedMetadataExtractionService] Extraction phase failed (will still persist empty state)', [
                'asset_id' => $asset->id,
                'message' => $e->getMessage(),
            ]);
            $otherWarnings['exception'] = $e->getMessage();
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        $this->persistNormalizedState($asset, $merged, $otherWarnings);
    }

    /**
     * @param  array<string, array<string, mixed>>  $merged
     * @param  array<string, string>  $otherWarnings
     */
    protected function persistNormalizedState(Asset $asset, array $merged, array $otherWarnings = []): void
    {
        try {
            if ($otherWarnings !== []) {
                $merged['other'] = array_merge($merged['other'] ?? [], $otherWarnings);
            }

            $normalized = $this->normalizer->normalizePayload($merged);

            $payload = AssetMetadataPayload::query()->updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'source' => 'embedded',
                ],
                [
                    'schema_version' => $this->registry->schemaVersion(),
                    'payload_json' => $normalized,
                    'extracted_at' => now(),
                ]
            );

            $this->indexBuilder->rebuild($asset->fresh(), $normalized);
            $this->systemMapper->apply($asset->fresh(), $normalized);

            Log::info('[EmbeddedMetadataExtractionService] Embedded metadata persisted', [
                'asset_id' => $asset->id,
                'payload_id' => $payload->id,
                'namespaces' => array_keys($normalized),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[EmbeddedMetadataExtractionService] Persist/rebuild failed (non-fatal)', [
                'asset_id' => $asset->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function emptyBuckets(): array
    {
        return [
            'exif' => [],
            'iptc' => [],
            'xmp' => [],
            'image' => [],
            'pdf' => [],
            'video' => [],
            'audio' => [],
            'other' => [],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $merged
     * @param  array<string, mixed>  $partial
     */
    protected function mergeBuckets(array &$merged, array $partial): void
    {
        foreach ($partial as $namespace => $data) {
            if (! is_string($namespace) || ! is_array($data)) {
                continue;
            }
            if (! isset($merged[$namespace])) {
                $merged[$namespace] = [];
            }
            foreach ($data as $k => $v) {
                $merged[$namespace][$k] = $v;
            }
        }
    }

    protected function downloadOriginalToTemp(Asset $asset, string $storagePath): ?string
    {
        $bucket = $asset->storageBucket;

        try {
            if ($bucket) {
                $contents = app(TenantBucketService::class)->getObjectContents($bucket, $storagePath);
            } else {
                $contents = Storage::disk('s3')->get($storagePath);
            }

            if ($contents === null || $contents === '') {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'emb_meta_');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $contents);

            return $tmp;
        } catch (S3Exception $e) {
            Log::warning('[EmbeddedMetadataExtractionService] S3 download failed', [
                'asset_id' => $asset->id,
                'key' => $storagePath,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('[EmbeddedMetadataExtractionService] Download failed', [
                'asset_id' => $asset->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

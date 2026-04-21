<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Brand;
use App\Models\StorageBucket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\GenerativeAiProvenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persists AI provider output (HTTPS URL or data URL) to tenant S3 and creates or versions an {@link Asset}.
 *
 * When `generative_layer_uuid` is present in `$context`, reuses the same asset id for that layer and appends
 * {@link AssetVersion} rows (cap: config editor.generative.max_versions_per_layer).
 */
final class EditorGenerativeImagePersistService
{
    public function __construct(
        protected AssetPathGenerator $pathGenerator,
        protected TenantBucketService $tenantBucketService,
        protected CompositionAssetReferenceStateService $compositionRefState
    ) {}

    /**
     * @param  array<string, mixed>  $context
     *                                         Optional: generative_layer_uuid, composition_id
     * @return array{url: string, asset_id: string}
     *
     * @throws \InvalidArgumentException
     */
    public function persistFromProviderReference(
        string $urlOrDataUrl,
        Tenant $tenant,
        User $user,
        Brand $brand,
        array $context = []
    ): array {
        if ($tenant->uuid === null || $tenant->uuid === '') {
            throw new \InvalidArgumentException('Tenant UUID is required for asset storage.');
        }

        $binary = $this->loadBinary($urlOrDataUrl);
        $normalized = $this->normalizeImageForStorage($binary);

        $binary = $normalized['binary'];
        $extension = $normalized['extension'];
        $mimeType = $normalized['mime_type'];

        $size = strlen($binary);
        $dims = @getimagesizefromstring($binary);
        $width = isset($dims[0]) ? (int) $dims[0] : null;
        $height = isset($dims[1]) ? (int) $dims[1] : null;

        $bucket = $this->tenantBucketService->resolveActiveBucketOrFail($tenant);

        $layerUuid = isset($context['generative_layer_uuid']) ? trim((string) $context['generative_layer_uuid']) : '';
        if ($layerUuid !== '') {
            $existing = $this->findGenerativeLayerAsset($tenant, $brand, $layerUuid);
            if ($existing !== null) {
                return $this->appendVersionToGenerativeLayerAsset(
                    $tenant,
                    $brand,
                    $user,
                    $existing,
                    $binary,
                    $extension,
                    $mimeType,
                    $size,
                    $width,
                    $height,
                    $layerUuid,
                    $context
                );
            }
        }

        $result = DB::transaction(function () use (
            $tenant,
            $brand,
            $user,
            $bucket,
            $binary,
            $extension,
            $mimeType,
            $size,
            $width,
            $height,
            $layerUuid,
            $context
        ) {
            $assetId = (string) Str::uuid();
            $sourceAssetId = isset($context['source_asset_id']) ? trim((string) $context['source_asset_id']) : '';
            $operation = isset($context['operation']) ? (string) $context['operation'] : '';
            if ($layerUuid !== '' && $sourceAssetId !== '' && $operation === 'generative_edit') {
                $dual = $this->createGenerativeLayerAssetPreservingDamOriginal(
                    $tenant,
                    $brand,
                    $user,
                    $bucket,
                    $assetId,
                    $layerUuid,
                    $context,
                    $binary,
                    $extension,
                    $mimeType,
                    $size,
                    $width,
                    $height,
                    $sourceAssetId
                );
                if ($dual !== null) {
                    return $dual;
                }
            }

            $path = $this->pathGenerator->generateOriginalPathForAssetId(
                $tenant,
                $assetId,
                1,
                $extension
            );

            $title = 'AI Generated '.now()->format('M j, Y g:i a');

            $meta = [
                'generated_at' => now()->toIso8601String(),
                'ai_generated' => true,
                'asset_role' => 'generative_layer',
                'jackpot_ai_provenance' => $this->buildLayerProvenance($user, $brand, $tenant, $context),
            ];
            if ($layerUuid !== '') {
                $meta['generative_layer_uuid'] = $layerUuid;
            }
            if (! empty($context['composition_id'])) {
                $meta['composition_id'] = (string) $context['composition_id'];
            }

            $asset = Asset::forceCreate([
                'id' => $assetId,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'user_id' => $user->id,
                'storage_bucket_id' => $bucket->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::AI_GENERATED,
                'title' => $title,
                'original_filename' => 'ai-generated-'.$assetId.'.'.$extension,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'storage_root_path' => $path,
                'thumbnail_status' => ThumbnailStatus::COMPLETED,
                'analysis_status' => 'complete',
                'approval_status' => ApprovalStatus::NOT_REQUIRED,
                'published_at' => null,
                'source' => 'generative_editor',
                'builder_staged' => false,
                'intake_state' => 'normal',
                'metadata' => $meta,
            ]);

            Storage::disk('s3')->put($path, $binary, 'private');

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $binary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            Log::info('AI image persisted', [
                'path' => $path,
                'bytes' => $size,
                'asset_id' => $asset->id,
            ]);

            $url = route('api.editor.assets.file', ['asset' => $asset->id], absolute: true);

            return ['url' => $url, 'asset_id' => $asset->id];
        });
        $created = Asset::query()->find($result['asset_id']);
        if ($created !== null) {
            $this->compositionRefState->refreshForAsset($created);
        }

        return $result;
    }

    /**
     * When the editor forks a new generative-layer asset for the first edit of a DAM
     * image, store the pre-edit file as version 1 and the AI result as version 2 so the
     * UI timeline shows a real original before numbered AI outputs.
     *
     * @param  array<string, mixed>  $context
     * @return array{url: string, asset_id: string}|null null → caller uses single-version path
     */
    private function createGenerativeLayerAssetPreservingDamOriginal(
        Tenant $tenant,
        Brand $brand,
        User $user,
        StorageBucket $bucket,
        string $assetId,
        string $layerUuid,
        array $context,
        string $aiBinary,
        string $aiExtension,
        string $aiMime,
        int $aiSize,
        ?int $aiWidth,
        ?int $aiHeight,
        string $sourceAssetId,
    ): ?array {
        $sourceAsset = Asset::query()
            ->whereKey($sourceAssetId)
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->first();
        if ($sourceAsset === null) {
            return null;
        }

        try {
            $sourceRaw = EditorAssetOriginalBytesLoader::loadFromStorage($sourceAsset);
        } catch (\Throwable $e) {
            Log::warning('[EditorGenerativeImagePersistService] Could not snapshot source asset for editor edit; falling back to AI-only version 1', [
                'source_asset_id' => $sourceAssetId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $srcNorm = $this->normalizeImageForStorage($sourceRaw);
        $srcBinary = $srcNorm['binary'];
        $srcExt = $srcNorm['extension'];
        $srcMime = $srcNorm['mime_type'];
        $srcSize = strlen($srcBinary);
        $srcDims = @getimagesizefromstring($srcBinary);
        $srcWidth = isset($srcDims[0]) ? (int) $srcDims[0] : null;
        $srcHeight = isset($srcDims[1]) ? (int) $srcDims[1] : null;

        $path1 = $this->pathGenerator->generateOriginalPathForAssetId(
            $tenant,
            $assetId,
            1,
            $srcExt
        );
        $path2 = $this->pathGenerator->generateOriginalPathForAssetId(
            $tenant,
            $assetId,
            2,
            $aiExtension
        );

        Storage::disk('s3')->put($path1, $srcBinary, 'private');
        Storage::disk('s3')->put($path2, $aiBinary, 'private');

        $title = 'AI Generated '.now()->format('M j, Y g:i a');
        $meta = [
            'generated_at' => now()->toIso8601String(),
            'ai_generated' => true,
            'asset_role' => 'generative_layer',
            'editor_edit_source_asset_id' => $sourceAssetId,
            'jackpot_ai_provenance' => $this->buildLayerProvenance($user, $brand, $tenant, $context),
            'generative_layer_uuid' => $layerUuid,
        ];
        if (! empty($context['composition_id'])) {
            $meta['composition_id'] = (string) $context['composition_id'];
        }

        $asset = Asset::forceCreate([
            'id' => $assetId,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::AI_GENERATED,
            'title' => $title,
            'original_filename' => 'ai-generated-'.$assetId.'.'.$aiExtension,
            'mime_type' => $aiMime,
            'size_bytes' => $aiSize,
            'width' => $aiWidth,
            'height' => $aiHeight,
            'storage_root_path' => $path2,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'analysis_status' => 'complete',
            'approval_status' => ApprovalStatus::NOT_REQUIRED,
            'published_at' => null,
            'source' => 'generative_editor',
            'builder_staged' => false,
            'intake_state' => 'normal',
            'metadata' => $meta,
        ]);

        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $path1,
            'file_size' => $srcSize,
            'mime_type' => $srcMime,
            'width' => $srcWidth,
            'height' => $srcHeight,
            'checksum' => hash('sha256', $srcBinary),
            'is_current' => false,
            'pipeline_status' => 'complete',
            'uploaded_by' => $user->id,
        ]);

        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 2,
            'file_path' => $path2,
            'file_size' => $aiSize,
            'mime_type' => $aiMime,
            'width' => $aiWidth,
            'height' => $aiHeight,
            'checksum' => hash('sha256', $aiBinary),
            'is_current' => true,
            'pipeline_status' => 'complete',
            'uploaded_by' => $user->id,
        ]);

        Log::info('AI image persisted (dual version: DAM snapshot + edit)', [
            'asset_id' => $asset->id,
            'source_asset_id' => $sourceAssetId,
            'v1_bytes' => $srcSize,
            'v2_bytes' => $aiSize,
        ]);

        return [
            'url' => route('api.editor.assets.file', ['asset' => $asset->id], absolute: true),
            'asset_id' => $asset->id,
        ];
    }

    private function findGenerativeLayerAsset(Tenant $tenant, Brand $brand, string $layerUuid): ?Asset
    {
        return Asset::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('source', 'generative_editor')
            ->where('metadata->generative_layer_uuid', $layerUuid)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{url: string, asset_id: string}
     */
    private function appendVersionToGenerativeLayerAsset(
        Tenant $tenant,
        Brand $brand,
        User $user,
        Asset $asset,
        string $binary,
        string $extension,
        string $mimeType,
        int $size,
        ?int $width,
        ?int $height,
        string $layerUuid,
        array $context
    ): array {
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            throw new \InvalidArgumentException('Generative layer asset does not belong to this workspace.');
        }

        $maxRetain = max(1, (int) config('editor.generative.max_versions_per_layer', 20));

        return DB::transaction(function () use (
            $tenant,
            $user,
            $asset,
            $binary,
            $extension,
            $mimeType,
            $size,
            $width,
            $height,
            $layerUuid,
            $context,
            $maxRetain
        ) {
            $asset->refresh();

            $maxVersion = (int) (AssetVersion::query()
                ->where('asset_id', $asset->id)
                ->max('version_number') ?? 0);

            $nextVersion = $maxVersion + 1;

            $path = $this->pathGenerator->generateOriginalPathForAssetId(
                $tenant,
                $asset->id,
                $nextVersion,
                $extension
            );

            $previousPath = $asset->storage_root_path;

            Storage::disk('s3')->put($path, $binary, 'private');

            AssetVersion::query()
                ->where('asset_id', $asset->id)
                ->update(['is_current' => false]);

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => $nextVersion,
                'file_path' => $path,
                'file_size' => $size,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'checksum' => hash('sha256', $binary),
                'is_current' => true,
                'pipeline_status' => 'complete',
                'uploaded_by' => $user->id,
            ]);

            $meta = $asset->metadata ?? [];
            if (! is_array($meta)) {
                $meta = [];
            }
            $meta['generated_at'] = now()->toIso8601String();
            $meta['ai_generated'] = true;
            $meta['asset_role'] = 'generative_layer';
            $meta['generative_layer_uuid'] = $layerUuid;
            $meta['jackpot_ai_provenance'] = $this->buildLayerProvenance($user, $brand, $tenant, $context);
            if (! empty($context['composition_id'])) {
                $meta['composition_id'] = (string) $context['composition_id'];
            }

            $asset->update([
                'storage_root_path' => $path,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
                'mime_type' => $mimeType,
                'metadata' => $meta,
            ]);

            if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $path) {
                try {
                    Storage::disk('s3')->delete($previousPath);
                } catch (\Throwable $e) {
                    Log::warning('[EditorGenerativeImagePersistService] Could not delete previous generative object', [
                        'asset_id' => $asset->id,
                        'path' => $previousPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->pruneOldGenerativeVersions($asset->id, $maxRetain);

            Log::info('Generative layer version appended', [
                'asset_id' => $asset->id,
                'version' => $nextVersion,
                'bytes' => $size,
            ]);

            $url = route('api.editor.assets.file', ['asset' => $asset->id], absolute: true);

            $this->compositionRefState->refreshForAsset($asset->fresh());

            return ['url' => $url, 'asset_id' => $asset->id];
        });
    }

    /**
     * Remove oldest version rows and S3 objects beyond the cap (keeps newest N).
     */
    private function pruneOldGenerativeVersions(string $assetId, int $maxRetain): void
    {
        $versions = AssetVersion::query()
            ->where('asset_id', $assetId)
            ->whereNull('deleted_at')
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'file_path']);

        if ($versions->count() <= $maxRetain) {
            return;
        }

        $toRemove = $versions->slice($maxRetain);
        foreach ($toRemove as $row) {
            $path = $row->file_path;
            try {
                if (is_string($path) && $path !== '') {
                    Storage::disk('s3')->delete($path);
                }
            } catch (\Throwable) {
                // best-effort
            }
            AssetVersion::query()->whereKey($row->id)->delete();
        }
    }

    /**
     * Re-encode with GD when possible: opaque images → JPEG (smaller). Images that use
     * transparency → PNG so alpha survives storage (JPEG would flatten cutouts to a solid
     * color or force users to see “checkerboard” baked in by the model as opaque pixels).
     *
     * @return array{binary: string, extension: string, mime_type: string}
     */
    private function normalizeImageForStorage(string $binary): array
    {
        if (! function_exists('imagecreatefromstring')) {
            $mime = $this->detectMime($binary);

            return [
                'binary' => $binary,
                'extension' => $this->extensionFromMime($mime),
                'mime_type' => $mime,
            ];
        }

        $im = @imagecreatefromstring($binary);
        if ($im === false) {
            $mime = $this->detectMime($binary);

            return [
                'binary' => $binary,
                'extension' => $this->extensionFromMime($mime),
                'mime_type' => $mime,
            ];
        }

        try {
            $hasTransparency = $this->gdImageUsesTransparency($im);

            if ($hasTransparency) {
                if (! imageistruecolor($im)) {
                    imagepalettetotruecolor($im);
                }
                imagealphablending($im, false);
                imagesavealpha($im, true);
                ob_start();
                imagepng($im, null, 6);
                $png = ob_get_clean();
                if (! is_string($png) || $png === '') {
                    $mime = $this->detectMime($binary);

                    return [
                        'binary' => $binary,
                        'extension' => $this->extensionFromMime($mime),
                        'mime_type' => $mime,
                    ];
                }

                return [
                    'binary' => $png,
                    'extension' => 'png',
                    'mime_type' => 'image/png',
                ];
            }

            ob_start();
            imagejpeg($im, null, 90);
            $jpeg = ob_get_clean();
            if (! is_string($jpeg) || $jpeg === '') {
                $mime = $this->detectMime($binary);

                return [
                    'binary' => $binary,
                    'extension' => $this->extensionFromMime($mime),
                    'mime_type' => $mime,
                ];
            }

            return [
                'binary' => $jpeg,
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
            ];
        } finally {
            if (isset($im) && (is_resource($im) || $im instanceof \GdImage)) {
                imagedestroy($im);
            }
        }
    }

    /**
     * True if any sampled pixel (or palette metadata) uses non-opaque alpha.
     */
    private function gdImageUsesTransparency(\GdImage $im): bool
    {
        if (! imageistruecolor($im)) {
            if (imagecolortransparent($im) >= 0) {
                return true;
            }
            $n = imagecolorstotal($im);
            for ($i = 0; $i < $n; $i++) {
                $c = imagecolorsforindex($im, $i);
                if (isset($c['alpha']) && (int) $c['alpha'] > 0) {
                    return true;
                }
            }

            return false;
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            return false;
        }

        $steps = (int) max(8, min(24, (int) round(max($w, $h) / 80)));
        for ($sx = 0; $sx <= $steps; $sx++) {
            for ($sy = 0; $sy <= $steps; $sy++) {
                $x = (int) floor($sx * ($w - 1) / max(1, $steps));
                $y = (int) floor($sy * ($h - 1) / max(1, $steps));
                $rgba = imagecolorat($im, $x, $y);
                $a = ($rgba >> 24) & 127;
                if ($a > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectMime(string $binary): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $m = $finfo->buffer($binary);
            if (is_string($m) && $m !== '') {
                return $m === 'image/jpg' ? 'image/jpeg' : $m;
            }
        }

        return 'application/octet-stream';
    }

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildLayerProvenance(User $user, Brand $brand, Tenant $tenant, array $context): array
    {
        $operation = isset($context['operation']) && is_string($context['operation']) && $context['operation'] !== ''
            ? $context['operation']
            : (! empty($context['source_asset_id']) ? 'generative_edit' : 'generative_generate');

        return GenerativeAiProvenance::forPersistedGenerativeOutput($user, $brand, $tenant, $context, $operation);
    }

    /**
     * Decode provider output (https URL or data URL) to raw image bytes.
     *
     * @throws \InvalidArgumentException
     */
    public function binaryFromProviderReference(string $urlOrDataUrl): string
    {
        return $this->loadBinary($urlOrDataUrl);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function loadBinary(string $urlOrDataUrl): string
    {
        $t = trim($urlOrDataUrl);
        if ($t === '') {
            throw new \InvalidArgumentException('Empty image reference.');
        }

        if (str_starts_with($t, 'data:')) {
            if (! preg_match('#^data:image/[^;]+;base64,(.+)$#', $t, $m)) {
                throw new \InvalidArgumentException('Invalid data URL image.');
            }
            $binary = base64_decode($m[1], true);
            if ($binary === false || $binary === '') {
                throw new \InvalidArgumentException('Malformed base64 image data.');
            }

            return $binary;
        }

        if (! str_starts_with($t, 'http://') && ! str_starts_with($t, 'https://')) {
            throw new \InvalidArgumentException('Image reference must be a data URL or https URL.');
        }

        $this->assertSafeRemoteImageUrl($t);

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'image/*'])
            ->get($t);

        if (! $response->successful()) {
            throw new \InvalidArgumentException('Failed to download generated image.');
        }

        $body = $response->body();
        if ($body === '') {
            throw new \InvalidArgumentException('Downloaded image is empty.');
        }

        return $body;
    }

    private function assertSafeRemoteImageUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Invalid remote image URL.');
        }

        $host = strtolower($host);
        $ok =
            str_contains($host, 'amazonaws.com')
            || str_contains($host, 'cloudfront.net')
            || str_contains($host, 'blob.core.windows.net')
            || str_contains($host, 'openai.com')
            || str_contains($host, 'oaiusercontent.com')
            || str_contains($host, 'googleusercontent.com')
            || str_contains($host, 'googleapis.com')
            || str_contains($host, 'storage.googleapis.com')
            || str_contains($host, 'gstatic.com')
            || str_contains($host, 'bfl.ai');

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '' && $host === strtolower($appHost)) {
            $ok = true;
        }

        if (! $ok) {
            throw new \InvalidArgumentException('Remote image host is not allowed.');
        }
    }
}

<?php

namespace App\Services;

use App\Models\StorageBucket;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Symfony\Component\Mime\MimeTypes;

class FileInspectionService
{
    /**
     * Inspect the actual file and return canonical file metadata.
     * Phase 6.5: Captures StorageClass from S3 headObject when bucket is provided.
     *
     * @param string $s3Path S3 object key (e.g. assets/{id}/v1/original.tif)
     * @param StorageBucket|null $bucket When provided, fetches from this bucket; otherwise uses default s3 disk
     */
    public function inspect(string $s3Path, ?StorageBucket $bucket = null): array
    {
        $storageClass = null;
        $headData = null;
        if ($bucket) {
            $headData = app(TenantBucketService::class)->headObject($bucket, $s3Path);
            $storageClass = $headData['StorageClass'] ?? 'STANDARD';
        }

        // Glacier: skip download (getObject would fail or trigger restore). Return metadata from headObject.
        $archived = in_array($storageClass ?? '', ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'], true);
        if ($archived && $headData) {
            return [
                'mime_type' => $headData['ContentType'] ?? 'application/octet-stream',
                'file_size' => $headData['ContentLength'] ?? 0,
                'width' => null,
                'height' => null,
                'is_image' => false,
                'storage_class' => $storageClass,
            ];
        }

        return $this->buildInspectResultFromLocalTemp($s3Path, $bucket, $headData, $storageClass);
    }

    /**
     * Cheap remote metadata only (HEAD / size / Content-Type). No object body download, no Imagick.
     * Used before worker-budget decisions so small workers never pull huge originals only to bail.
     *
     * @return array{mime_type: string, file_size: int, storage_class?: string|null}
     */
    public function peekRemoteMetadata(string $s3Path, ?StorageBucket $bucket = null): array
    {
        $storageClass = null;
        $headData = null;
        if ($bucket) {
            $headData = app(TenantBucketService::class)->headObject($bucket, $s3Path);
            $storageClass = $headData['StorageClass'] ?? 'STANDARD';
        }

        $archived = in_array($storageClass ?? '', ['GLACIER', 'DEEP_ARCHIVE', 'GLACIER_IR'], true);
        if ($archived && $headData) {
            return [
                'mime_type' => $this->refineMimeFromPath($s3Path, (string) ($headData['ContentType'] ?? 'application/octet-stream')),
                'file_size' => (int) ($headData['ContentLength'] ?? 0),
                'storage_class' => $storageClass,
            ];
        }

        if ($bucket && $headData) {
            $mime = $this->refineMimeFromPath($s3Path, (string) ($headData['ContentType'] ?? 'application/octet-stream'));

            return [
                'mime_type' => $mime,
                'file_size' => (int) ($headData['ContentLength'] ?? 0),
                'storage_class' => $storageClass,
            ];
        }

        $disk = Storage::disk('s3');
        if (! $disk->exists($s3Path)) {
            return [
                'mime_type' => 'application/octet-stream',
                'file_size' => 0,
            ];
        }
        $remoteSize = (int) $disk->size($s3Path);
        try {
            $rawMime = $disk->mimeType($s3Path);
        } catch (\Throwable) {
            $rawMime = null;
        }
        $mime = $this->refineMimeFromPath($s3Path, (string) ($rawMime ?: 'application/octet-stream'));

        return [
            'mime_type' => $mime,
            'file_size' => $remoteSize,
        ];
    }

    /**
     * Download (when under max) and run finfo + optional Imagick dimension probe.
     *
     * @param  array<string, mixed>|null  $headData
     */
    private function buildInspectResultFromLocalTemp(string $s3Path, ?StorageBucket $bucket, ?array $headData, ?string $storageClass): array
    {
        $maxInspect = (int) config('assets.processing.inspect_max_full_download_bytes', 150 * 1024 * 1024);

        if ($bucket && $headData && $maxInspect > 0) {
            $contentLength = (int) ($headData['ContentLength'] ?? 0);
            if ($contentLength > $maxInspect) {
                $mime = $this->refineMimeFromPath($s3Path, (string) ($headData['ContentType'] ?? 'application/octet-stream'));

                return [
                    'mime_type' => $mime,
                    'file_size' => $contentLength,
                    'width' => null,
                    'height' => null,
                    'is_image' => str_starts_with($mime, 'image/'),
                    'storage_class' => $storageClass,
                ];
            }
        }

        if (! $bucket && $maxInspect > 0) {
            $disk = Storage::disk('s3');
            if ($disk->exists($s3Path)) {
                $remoteSize = (int) $disk->size($s3Path);
                if ($remoteSize > $maxInspect) {
                    try {
                        $rawMime = $disk->mimeType($s3Path);
                    } catch (\Throwable) {
                        $rawMime = null;
                    }
                    $mime = $this->refineMimeFromPath($s3Path, (string) ($rawMime ?: 'application/octet-stream'));

                    return [
                        'mime_type' => $mime,
                        'file_size' => $remoteSize,
                        'width' => null,
                        'height' => null,
                        'is_image' => str_starts_with($mime, 'image/'),
                    ];
                }
            }
        }

        $contents = $bucket
            ? app(TenantBucketService::class)->getObjectContents($bucket, $s3Path)
            : Storage::disk('s3')->get($s3Path);

        $tmp = tmpfile();
        $tmpPath = stream_get_meta_data($tmp)['uri'];

        file_put_contents($tmpPath, $contents);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        // Fallback when finfo returns generic type (e.g. TIFF sometimes reported as application/octet-stream)
        if ($mime === 'application/octet-stream' && function_exists('mime_content_type')) {
            $fallback = mime_content_type($tmpPath);
            if ($fallback && $fallback !== 'application/octet-stream') {
                $mime = $fallback;
            }
        }

        // Map extension from the S3 key (e.g. octet-stream → video/quicktime for …/original.mov).
        $mime = $this->refineMimeFromPath($s3Path, $mime);
        // iPhone / QuickTime often sniff as audio/mp4 while the object is a video container.
        $mime = $this->coerceVideoMimeFromStoragePath($s3Path, $mime);

        $size = filesize($tmpPath);

        $width = null;
        $height = null;
        $isImage = false;

        try {
            $imagick = new Imagick($tmpPath);
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            $isImage = true;
            $imagick->clear();
            $imagick->destroy();
        } catch (\Exception $e) {
            // Not raster image (vector, pdf, etc.)
        }

        fclose($tmp);

        $result = [
            'mime_type' => $mime,
            'file_size' => $size,
            'width' => $width,
            'height' => $height,
            'is_image' => $isImage,
        ];
        if ($storageClass !== null) {
            $result['storage_class'] = $storageClass;
        }

        return $result;
    }

    /**
     * Refine generic S3 Content-Type using the object key extension only.
     *
     * Do not use MimeTypes::guessMimeType($path): it delegates to FileinfoMimeTypeGuesser and
     * requires a readable local file — S3 keys like tenants/.../original.psd are not filesystem paths.
     */
    private function refineMimeFromPath(string $path, string $mime): string
    {
        if ($mime !== 'application/octet-stream' && $mime !== 'binary/octet-stream') {
            return $mime;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return $mime;
        }

        $types = MimeTypes::getDefault()->getMimeTypes($ext);

        return ($types[0] ?? null) ?: $mime;
    }

    /**
     * When the storage key ends in .mov / .m4v but magic sniffing returns an audio or generic MP4 MIME,
     * normalize to a video/* type so downstream {@see FileTypeService} routes to FFmpeg thumbnails.
     */
    private function coerceVideoMimeFromStoragePath(string $s3Path, string $mime): string
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($s3Path, PATHINFO_EXTENSION));
        if ($ext !== 'mov' && $ext !== 'm4v') {
            return $mime;
        }
        if (! in_array($mime, ['audio/mp4', 'audio/x-m4a', 'application/mp4'], true)) {
            return $mime;
        }

        return $ext === 'm4v' ? 'video/x-m4v' : 'video/quicktime';
    }
}

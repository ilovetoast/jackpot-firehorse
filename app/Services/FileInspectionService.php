<?php

namespace App\Services;

use App\Models\StorageBucket;
use Illuminate\Support\Facades\Storage;
use Imagick;

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
}

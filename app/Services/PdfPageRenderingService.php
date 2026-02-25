<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use RuntimeException;

class PdfPageRenderingService
{
    public function __construct(
        protected TenantBucketService $tenantBucketService,
        protected AssetPathGenerator $assetPathGenerator
    ) {
    }

    /**
     * Get PDF page count for an asset (for thumbnail guardrails).
     * Uses stored pdf_page_count when available; otherwise downloads and detects.
     *
     * @param bool $forceDetect If true, always detect from file; otherwise use stored count when > 0
     * @return int Page count (0 if not a PDF or detection fails)
     */
    public function getPdfPageCount(Asset $asset, bool $forceDetect = false): int
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (!str_contains($mime, 'pdf')) {
            return 0;
        }

        if (!$forceDetect) {
            $stored = (int) ($asset->pdf_page_count ?? 0);
            if ($stored > 0) {
                return $stored;
            }
        }

        $tempPath = null;
        try {
            $version = $asset->relationLoaded('currentVersion') ? $asset->currentVersion : $asset->currentVersion()->first();
            $tempPath = $this->downloadSourcePdfToTemp($asset, $version);
            return $this->detectPageCount($tempPath);
        } catch (\Throwable $e) {
            return 0;
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Download source PDF to a local temp path.
     */
    public function downloadSourcePdfToTemp(Asset $asset, ?AssetVersion $version = null): string
    {
        $bucket = $asset->storageBucket;
        $sourcePath = $version?->file_path ?: $asset->storage_root_path;

        if (!$bucket || !$sourcePath) {
            throw new RuntimeException('Asset source path or storage bucket is missing.');
        }

        $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_src_');
        if ($tempPdfPath === false) {
            throw new RuntimeException('Unable to create temporary PDF file.');
        }

        $this->tenantBucketService->getS3Client()->getObject([
            'Bucket' => $bucket->name,
            'Key' => $sourcePath,
            'SaveAs' => $tempPdfPath,
        ]);

        if (!file_exists($tempPdfPath) || filesize($tempPdfPath) === 0) {
            @unlink($tempPdfPath);
            throw new RuntimeException('Downloaded PDF is empty or missing.');
        }

        return $tempPdfPath;
    }

    /**
     * Detect PDF page count from a local file.
     */
    public function detectPageCount(string $localPdfPath): int
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is required for PDF page counting.');
        }
        if (!file_exists($localPdfPath)) {
            throw new RuntimeException('PDF file does not exist for page counting.');
        }

        $maxSize = (int) config('assets.thumbnail.pdf.max_size_bytes', 150 * 1024 * 1024);
        $size = filesize($localPdfPath) ?: 0;
        if ($size > $maxSize) {
            throw new RuntimeException("PDF exceeds maximum allowed size ({$maxSize} bytes).");
        }

        $imagick = new \Imagick();
        try {
            $imagick->pingImage($localPdfPath);
            $pageCount = (int) $imagick->getNumberImages();
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }

        if ($pageCount < 1) {
            throw new RuntimeException('Unable to determine PDF page count.');
        }

        return $pageCount;
    }

    /**
     * Render one PDF page to a local WebP image.
     *
     * @param  array<string, mixed>  $styleConfig  width, height, quality (ignored when $useViewerPreset is true)
     * @return array{local_path: string, width: int, height: int, size_bytes: int, mime_type: string}
     */
    public function renderPageToWebp(string $localPdfPath, int $page, array $styleConfig = [], bool $useViewerPreset = false): array
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick extension is required for PDF rendering.');
        }
        if ($page < 1) {
            throw new RuntimeException('PDF page must be >= 1.');
        }

        if ($useViewerPreset) {
            $dpi = (int) config('assets.thumbnail.pdf.viewer_dpi', 150);
            $maxSize = (int) config('assets.thumbnail.pdf.viewer_max_size', 1600);
            $quality = 88;
            $targetWidth = $maxSize;
            $targetHeight = $maxSize;
        } else {
            $dpi = (int) config('assets.thumbnail.pdf.render_dpi', 220);
            $quality = (int) ($styleConfig['quality'] ?? config('assets.thumbnail_styles.large.quality', 92));
            $targetWidth = (int) ($styleConfig['width'] ?? config('assets.thumbnail_styles.large.width', 4096));
            $targetHeight = (int) ($styleConfig['height'] ?? config('assets.thumbnail_styles.large.height', 4096));
        }

        $imagick = new \Imagick();
        try {
            $imagick->setResolution($dpi, $dpi);
            $imagick->readImage($localPdfPath . '[' . ($page - 1) . ']');
            $imagick->setIteratorIndex(0);
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $imagick->thumbnailImage($targetWidth, $targetHeight, true, true);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompression(\Imagick::COMPRESSION_WEBP);
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage();

            $outputPath = tempnam(sys_get_temp_dir(), 'pdf_page_') . '.webp';
            if (!$imagick->writeImage($outputPath)) {
                throw new RuntimeException('Unable to write rendered PDF page image.');
            }

            $width = (int) $imagick->getImageWidth();
            $height = (int) $imagick->getImageHeight();
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException('Rendered PDF page image is empty.');
        }

        return [
            'local_path' => $outputPath,
            'width' => $width,
            'height' => $height,
            'size_bytes' => (int) filesize($outputPath),
            'mime_type' => 'image/webp',
        ];
    }

    /**
     * Upload rendered PDF page image to tenant bucket.
     */
    public function uploadRenderedPage(
        Asset $asset,
        ?AssetVersion $version,
        int $page,
        string $localPath,
        string $mimeType = 'image/webp'
    ): string {
        $bucket = $asset->storageBucket;
        if (!$bucket) {
            throw new RuntimeException('Asset storage bucket is missing.');
        }

        $versionNumber = $version?->version_number
            ?? ($asset->relationLoaded('currentVersion')
                ? ($asset->currentVersion?->version_number ?? 1)
                : (int) ($asset->currentVersion()->value('version_number') ?? 1));

        $extension = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'webp';
        $targetPath = $this->assetPathGenerator->generatePdfPagePath(
            $asset->tenant,
            $asset,
            $versionNumber,
            $page,
            $extension
        );

        $this->tenantBucketService->getS3Client()->putObject([
            'Bucket' => $bucket->name,
            'Key' => $targetPath,
            'Body' => fopen($localPath, 'rb'),
            'ContentType' => $mimeType,
            'Metadata' => [
                'original-asset-id' => $asset->id,
                'pdf-page' => (string) $page,
                'generated-at' => now()->toIso8601String(),
            ],
        ]);

        return $targetPath;
    }
}

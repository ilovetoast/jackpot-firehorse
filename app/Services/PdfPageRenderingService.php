<?php

namespace App\Services;

use App\Models\Asset;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PdfPageRenderingService
{
    /**
     * Determine whether an asset should be handled as PDF.
     */
    public function isPdfAsset(Asset $asset): bool
    {
        $mime = strtolower((string) ($asset->mime_type ?? ''));
        $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));

        return $mime === 'application/pdf' || $ext === 'pdf';
    }

    /**
     * Resolve and persist page count for a PDF asset.
     */
    public function getPdfPageCount(Asset $asset, bool $refresh = false): int
    {
        if (! $this->isPdfAsset($asset)) {
            return 0;
        }

        $existingCount = (int) ($asset->pdf_page_count ?? 0);
        if (! $refresh && $existingCount > 0) {
            return $existingCount;
        }

        $pageCount = $this->countPagesFromSource($asset);
        $maxAllowed = (int) config('pdf.max_allowed_pages', 500);

        $asset->forceFill([
            'pdf_page_count' => $pageCount,
            'pdf_unsupported_large' => $pageCount > $maxAllowed,
        ])->save();

        return $pageCount;
    }

    /**
     * Check whether rendered page file exists in storage.
     */
    public function pageExists(Asset $asset, int $page): bool
    {
        $bucketName = $asset->storageBucket?->name;
        $path = $this->resolvePagePath($asset, $page);
        if (! $bucketName || $path === '') {
            return false;
        }

        $s3Client = $this->createS3Client();

        try {
            $s3Client->headObject([
                'Bucket' => $bucketName,
                'Key' => $path,
            ]);

            return true;
        } catch (S3Exception $e) {
            if (
                $e->getStatusCode() === 404
                || in_array($e->getAwsErrorCode(), ['404', 'NotFound', 'NoSuchKey'], true)
            ) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Resolve canonical rendered page path.
     */
    public function resolvePagePath(Asset $asset, int $page): string
    {
        if ($page < 1 || ! $asset->tenant) {
            return '';
        }

        $versionNumber = $asset->currentVersion?->version_number
            ?? $this->extractVersionNumberFromPath((string) $asset->storage_root_path)
            ?? 1;

        return app(AssetPathGenerator::class)->generatePdfPagePath(
            $asset->tenant,
            $asset,
            $versionNumber,
            $page
        );
    }

    /**
     * Render a single PDF page and upload it to S3.
     *
     * @return array{path: string, size_bytes: int, rendered: bool}
     */
    public function renderPage(Asset $asset, int $page, bool $force = false): array
    {
        if (! $this->isPdfAsset($asset)) {
            throw new \RuntimeException('Asset is not a PDF.');
        }
        if ($page < 1) {
            throw new \RuntimeException('Page number must be >= 1.');
        }

        $pageCount = $this->getPdfPageCount($asset);
        if ($pageCount < 1) {
            throw new \RuntimeException('PDF page count could not be determined.');
        }
        if ($page > $pageCount) {
            throw new \RuntimeException("Requested page {$page} exceeds page count {$pageCount}.");
        }

        $maxAllowed = (int) config('pdf.max_allowed_pages', 500);
        if ($pageCount > $maxAllowed) {
            $asset->forceFill(['pdf_unsupported_large' => true])->save();
            throw new \RuntimeException("PDF exceeds max_allowed_pages guardrail ({$pageCount} > {$maxAllowed}).");
        }

        $bucketName = $asset->storageBucket?->name;
        if (! $bucketName) {
            throw new \RuntimeException('Asset storage bucket is missing.');
        }

        $outputPath = $this->resolvePagePath($asset, $page);
        if ($outputPath === '') {
            throw new \RuntimeException('Unable to resolve PDF page output path.');
        }

        if (! $force && $this->pageExists($asset, $page)) {
            return [
                'path' => $outputPath,
                'size_bytes' => 0,
                'rendered' => false,
            ];
        }

        $tempPdfPath = $this->downloadSourcePdfToTemp($asset, $bucketName);
        $tempOutputPath = tempnam(sys_get_temp_dir(), 'pdf_page_');
        if ($tempOutputPath === false) {
            @unlink($tempPdfPath);
            throw new \RuntimeException('Failed to create temporary output file.');
        }
        $tempOutputPath .= '.webp';

        $imagick = null;
        try {
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('Imagick extension is required for PDF rendering.');
            }

            $dpi = (int) config('pdf.dpi', 150);
            $quality = (int) config('pdf.compression_quality', 82);
            $targetWidth = (int) config('pdf.large_preview_width', 1600);

            $imagick = new \Imagick();
            $imagick->setResolution($dpi, $dpi);
            $imagick->readImage($tempPdfPath . '[' . ($page - 1) . ']');
            $imagick->setIteratorIndex(0);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->resizeImage($targetWidth, 0, \Imagick::FILTER_LANCZOS, 1);
            $imagick->stripImage();
            $imagick->writeImage($tempOutputPath);

            $renderedBytes = (int) @filesize($tempOutputPath);
            if ($renderedBytes <= 0) {
                throw new \RuntimeException('Rendered PDF page file is missing or empty.');
            }

            $s3Client = $this->createS3Client();
            $stream = fopen($tempOutputPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open rendered PDF page for upload.');
            }

            try {
                $s3Client->putObject([
                    'Bucket' => $bucketName,
                    'Key' => $outputPath,
                    'Body' => $stream,
                    'ContentType' => 'image/webp',
                    'CacheControl' => config('pdf.cache_control', 'public, max-age=31536000, immutable'),
                    'Metadata' => [
                        'asset-id' => (string) $asset->id,
                        'page' => (string) $page,
                        'rendered-at' => now()->toIso8601String(),
                    ],
                ]);
            } finally {
                fclose($stream);
            }

            DB::table('assets')
                ->where('id', $asset->id)
                ->update([
                    'pdf_rendered_pages_count' => DB::raw('COALESCE(pdf_rendered_pages_count, 0) + 1'),
                    'pdf_rendered_storage_bytes' => DB::raw('COALESCE(pdf_rendered_storage_bytes, 0) + ' . $renderedBytes),
                    'updated_at' => now(),
                ]);

            return [
                'path' => $outputPath,
                'size_bytes' => $renderedBytes,
                'rendered' => true,
            ];
        } finally {
            if ($imagick instanceof \Imagick) {
                try {
                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Throwable) {
                    // Best-effort cleanup only.
                }
            }

            if (file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
            if (file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }
        }
    }

    /**
     * Count pages by pinging the source PDF.
     */
    protected function countPagesFromSource(Asset $asset): int
    {
        $bucketName = $asset->storageBucket?->name;
        if (! $bucketName) {
            throw new \RuntimeException('Cannot count PDF pages: asset bucket missing.');
        }

        $tempPdfPath = $this->downloadSourcePdfToTemp($asset, $bucketName);
        $imagick = null;

        try {
            if (! extension_loaded('imagick')) {
                throw new \RuntimeException('Imagick extension is required to inspect PDF page count.');
            }

            $imagick = new \Imagick();
            $imagick->pingImage($tempPdfPath);
            $count = (int) $imagick->getNumberImages();

            return max(0, $count);
        } finally {
            if ($imagick instanceof \Imagick) {
                try {
                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Throwable) {
                    // Best-effort cleanup only.
                }
            }

            if (file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }

    /**
     * Download source PDF from S3 into a temporary file.
     */
    protected function downloadSourcePdfToTemp(Asset $asset, string $bucketName): string
    {
        $sourcePath = (string) ($asset->storage_root_path ?? '');
        if ($sourcePath === '') {
            throw new \RuntimeException('Asset storage path is missing.');
        }

        $s3Client = $this->createS3Client();
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf_src_');
        if ($tempPdfPath === false) {
            throw new \RuntimeException('Failed to allocate temporary file for source PDF.');
        }

        try {
            $result = $s3Client->getObject([
                'Bucket' => $bucketName,
                'Key' => $sourcePath,
            ]);

            file_put_contents($tempPdfPath, (string) $result['Body']);
            $size = (int) @filesize($tempPdfPath);
            if ($size <= 0) {
                throw new \RuntimeException('Downloaded source PDF is empty.');
            }

            return $tempPdfPath;
        } catch (\Throwable $e) {
            @unlink($tempPdfPath);

            Log::error('[PdfPageRenderingService] Failed to download PDF source', [
                'asset_id' => $asset->id,
                'bucket' => $bucketName,
                'source_path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create S3 client configured for AWS or MinIO.
     */
    protected function createS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Parse version number from canonical v{n} storage paths.
     */
    protected function extractVersionNumberFromPath(string $path): ?int
    {
        if (preg_match('/\/v(\d+)\//', $path, $matches) !== 1) {
            return null;
        }

        $version = (int) ($matches[1] ?? 0);

        return $version > 0 ? $version : null;
    }
}

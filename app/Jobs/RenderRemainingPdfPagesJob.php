<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\AssetVariantPathResolver;
use App\Services\TenantBucketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Renders remaining PDF pages in background after first page is viewed.
 * Skips pages that already exist at deterministic S3 path (idempotent).
 */
class RenderRemainingPdfPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $assetId,
        public readonly ?string $assetVersionId = null
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(TenantBucketService $tenantBucketService): void
    {
        $version = $this->assetVersionId
            ? AssetVersion::with(['asset.storageBucket', 'asset.tenant', 'asset.currentVersion'])->find($this->assetVersionId)
            : null;
        $asset = $version?->asset ?: Asset::with(['storageBucket', 'tenant', 'currentVersion'])->find($this->assetId);

        if (!$asset || !$asset->storageBucket) {
            return;
        }

        $pageCount = (int) ($asset->pdf_page_count ?? 0);
        if ($pageCount < 2) {
            Log::info('[RenderRemainingPdfPagesJob] Skipping - page count < 2', [
                'asset_id' => $asset->id,
                'pdf_page_count' => $pageCount,
            ]);
            return;
        }

        $versionNumber = $asset->currentVersion?->version_number ?? 1;
        $dispatched = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $path = AssetVariantPathResolver::resolvePdfPagePath($asset, $page, $versionNumber);
            if (!$tenantBucketService->objectExists($asset->storageBucket, $path)) {
                PdfPageRenderJob::dispatch($asset->id, $page, $asset->currentVersion?->id);
                $dispatched[] = $page;
            }
        }
        if ($dispatched !== []) {
            Log::info('[RenderRemainingPdfPagesJob] Dispatched PDF page render jobs', [
                'asset_id' => $asset->id,
                'pages' => $dispatched,
                'page_count' => $pageCount,
            ]);
        }
    }
}

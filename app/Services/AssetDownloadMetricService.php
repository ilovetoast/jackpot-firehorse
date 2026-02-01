<?php

namespace App\Services;

use App\Enums\DownloadSource;
use App\Enums\MetricType;
use App\Models\Asset;
use App\Models\AssetMetric;
use App\Models\Download;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Phase D9.1: Record asset-level download metrics when a download is actually delivered.
 * Centralizes logic for SINGLE_ASSET and ZIP-based deliveries. No increments for
 * landing page views, processing, or failures.
 */
class AssetDownloadMetricService
{
    /**
     * Record asset download counts for a successfully delivered download.
     * Call only when the file is being served (not on landing page, not on unlock).
     *
     * @param  string  $context  'single_asset' or 'zip'
     */
    public function recordFromDownload(Download $download, string $context): void
    {
        if ($download->source === DownloadSource::SINGLE_ASSET) {
            $this->recordSingleAsset($download);
            return;
        }

        $this->recordZipAssets($download);
    }

    /**
     * Single-asset: increment metric for the one asset in this download.
     */
    private function recordSingleAsset(Download $download): void
    {
        $asset = $download->assets()->first();
        if (! $asset) {
            Log::warning('[AssetDownloadMetricService] Single-asset download has no asset', [
                'download_id' => $download->id,
            ]);
            return;
        }

        $this->recordOne($asset, $download);
    }

    /**
     * ZIP-based: record one metric per asset in the download (one increment per asset per delivery).
     */
    private function recordZipAssets(Download $download): void
    {
        $assets = $download->assets()->get();
        foreach ($assets as $asset) {
            $this->recordOne($asset, $download);
        }
    }

    /**
     * Record one download metric for an asset (append-only asset_metrics row).
     * Aligns with Dashboard / asset stats that count MetricType::DOWNLOAD rows.
     */
    private function recordOne(Asset $asset, Download $download): void
    {
        try {
            AssetMetric::create([
                'tenant_id' => $download->tenant_id,
                'brand_id' => $download->brand_id,
                'asset_id' => $asset->id,
                'user_id' => Auth::id(),
                'metric_type' => MetricType::DOWNLOAD,
                'metadata' => [
                    'download_id' => $download->id,
                    'context' => $download->source === DownloadSource::SINGLE_ASSET ? 'single_asset' : 'zip',
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AssetDownloadMetricService] Failed to record asset download metric', [
                'asset_id' => $asset->id,
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

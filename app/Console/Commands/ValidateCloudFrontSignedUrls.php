<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\AssetUrlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validates CloudFront signed URL functionality for staging/production.
 * Intended for deployment: fail the step if validation fails (exit non-zero).
 *
 * Loads the first asset, generates a signed URL via AssetUrlService, and performs
 * an HTTP GET. Exits with failure if the response is not 200.
 */
class ValidateCloudFrontSignedUrls extends Command
{
    protected $signature = 'validate:cloudfront';

    protected $description = 'Validate CloudFront signed URL generation and access (for staging/production deployment)';

    public function handle(AssetUrlService $assetUrlService): int
    {
        $asset = Asset::with('tenant')->orderBy('id')->first();

        if (! $asset) {
            $this->error('No assets in database; cannot validate signed URL.');
            Log::warning('[ValidateCloudFrontSignedUrls] No assets in database.');
            return Command::FAILURE;
        }

        $path = $assetUrlService->getAdminThumbnailPath($asset);

        if (! $path) {
            $this->error('First asset has no thumbnail path; cannot generate signed URL.');
            Log::warning('[ValidateCloudFrontSignedUrls] No thumbnail path for first asset.', ['asset_id' => $asset->id]);
            return Command::FAILURE;
        }

        try {
            $signedUrl = $assetUrlService->getSignedCloudFrontUrl($path);
        } catch (\Throwable $e) {
            $this->error('Failed to generate signed URL: ' . $e->getMessage());
            Log::error('[ValidateCloudFrontSignedUrls] Signing failed.', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }

        $this->info('Generated signed URL for asset ' . $asset->id . ', performing GET...');

        $response = Http::timeout(5)->get($signedUrl);

        if ($response->failed()) {
            $this->error('CloudFront signed URL validation failed. HTTP ' . $response->status());
            Log::error('[ValidateCloudFrontSignedUrls] GET failed.', [
                'asset_id' => $asset->id,
                'status' => $response->status(),
                'url' => $signedUrl,
            ]);
            return Command::FAILURE;
        }

        $this->info('CloudFront signed URL validation passed (200).');
        return Command::SUCCESS;
    }
}

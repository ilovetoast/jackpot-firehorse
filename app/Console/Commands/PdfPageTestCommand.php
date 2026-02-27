<?php

namespace App\Console\Commands;

use App\Http\Controllers\AssetPdfPageController;
use App\Models\Asset;
use Illuminate\Console\Command;

/**
 * Simulate the drawer's on-demand PDF page request (GET /app/assets/{id}/pdf-page/{page}).
 *
 * Use to debug PDF preview behaviour while processing has just started:
 * 1. Start reprocess for a PDF asset (e.g. from the UI).
 * 2. Run this command immediately: sail artisan pdf:test-page {asset_id}
 *
 * You should see status "processing" with message "Pipeline is running; page will be ready
 * when thumbnails complete." (no duplicate PdfPageRenderJob dispatched).
 */
class PdfPageTestCommand extends Command
{
    protected $signature = 'pdf:test-page 
                            {asset : Asset UUID} 
                            {--page=1 : Page number (default 1)}';

    protected $description = "Simulate drawer's PDF page request (same as GET /app/assets/{id}/pdf-page/{page}) for debugging";

    public function handle(AssetPdfPageController $controller): int
    {
        $assetId = $this->argument('asset');
        $page = (int) $this->option('page');

        $asset = Asset::with('tenant', 'currentVersion')->find($assetId);
        if (!$asset) {
            $this->error("Asset not found: {$assetId}");
            return self::FAILURE;
        }

        if ($asset->tenant) {
            app()->instance('tenant', $asset->tenant);
        }

        $this->line("Asset: {$asset->id} ({$asset->original_filename})");
        $this->line("thumbnail_status: " . ($asset->thumbnail_status?->value ?? $asset->thumbnail_status ?? 'null'));
        $this->line("Page: {$page}");
        $this->newLine();

        $result = $controller->resolvePdfPage($asset, $page);
        $payload = $result['payload'];
        $status = $result['http_status'];

        $this->line('HTTP status: ' . $status);
        $this->line('Response (same as drawer would receive):');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (isset($payload['status'])) {
            if ($payload['status'] === 'processing' && isset($payload['message'])) {
                $this->newLine();
                $this->info('[Debug] ' . $payload['message']);
            }
            if ($payload['status'] === 'ready' && !empty($payload['url'])) {
                $this->newLine();
                $this->info('Page URL: ' . $payload['url']);
            }
        }

        return self::SUCCESS;
    }
}

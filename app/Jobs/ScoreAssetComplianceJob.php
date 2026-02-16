<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\BrandDNA\BrandComplianceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Score an asset against Brand DNA compliance rules.
 * Dispatched after metadata update or AI tagging completion.
 *
 * Prevents premature scoring: if dominant colors or metadata extraction incomplete,
 * delays 5 seconds and retries (max 3 attempts). If still incomplete, marks score null.
 */
class ScoreAssetComplianceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $assetId
    ) {}

    public function handle(BrandComplianceService $service): void
    {
        $asset = Asset::with('brand.brandModel')->find($this->assetId);
        if (! $asset || ! $asset->brand_id) {
            return;
        }

        $brand = $asset->brand;
        if (! $brand) {
            return;
        }

        if (! $this->isMetadataReadyForScoring($asset)) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);

                return;
            }
            $service->deleteScoreIfExists($asset, $brand);
            Log::info('[ScoreAssetComplianceJob] Metadata incomplete after retries, score not written', [
                'asset_id' => $this->assetId,
                'attempts' => $this->attempts(),
            ]);

            return;
        }

        $service->scoreAsset($asset, $brand);
    }

    protected function isMetadataReadyForScoring(Asset $asset): bool
    {
        $brandModel = $asset->brand?->brandModel;
        if (! $brandModel || ! $brandModel->is_enabled || ! $brandModel->activeVersion) {
            return true;
        }

        $payload = $brandModel->activeVersion->model_payload ?? [];
        $rules = $payload['scoring_rules'] ?? [];
        $hasColorRules = ! empty($rules['allowed_color_palette'] ?? []) || ! empty($rules['banned_colors'] ?? []);

        if ($hasColorRules) {
            $complianceService = app(BrandComplianceService::class);
            if (! $complianceService->hasDominantColors($asset)) {
                return false;
            }
        }

        return true;
    }
}

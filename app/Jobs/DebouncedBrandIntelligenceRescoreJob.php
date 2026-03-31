<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\BrandIntelligence\BrandIntelligenceScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs after {@see BrandIntelligenceScheduleService::TAG_METADATA_DEBOUNCE_SECONDS} of quiet time
 * following tag/metadata edits. Superseded if the user keeps editing (version token mismatch).
 */
class DebouncedBrandIntelligenceRescoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $assetId,
        public readonly int $expectedVersion
    ) {}

    public function handle(BrandIntelligenceScheduleService $schedule): void
    {
        $asset = Asset::query()->find($this->assetId);
        if (! $asset || ! $asset->brand_id) {
            return;
        }

        $key = 'ebi_rescore_debounce_v:'.$this->assetId;
        $current = Cache::get($key);
        if ($current === null || (int) $current !== $this->expectedVersion) {
            Log::debug('[EBI] Debounced rescore skipped (superseded or expired)', [
                'asset_id' => $this->assetId,
                'expected' => $this->expectedVersion,
                'current' => $current,
            ]);

            return;
        }

        $category = $asset->resolveCategoryForTenant();
        if (! $category || ! $category->isEbiEnabled()) {
            return;
        }

        $status = $asset->analysis_status ?? '';
        if (! in_array($status, ['scoring', 'complete'], true)) {
            return;
        }

        $schedule->purgeAssetScoresAndDispatch($asset);
    }
}

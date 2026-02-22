<?php

namespace App\Jobs;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class IngestPdfPagesForAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $assetId
    ) {
    }

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);
        if (! $asset) {
            return;
        }

        $metadata = $asset->metadata ?? [];
        $metadata['pdf_ai_ingestion_triggered_at'] = now()->toIso8601String();
        $asset->update(['metadata' => $metadata]);

        Log::info('[IngestPdfPagesForAiJob] Triggering AI ingestion chain after full PDF extraction', [
            'asset_id' => $asset->id,
        ]);

        Bus::chain([
            new AiMetadataGenerationJob($asset->id),
            new AiMetadataSuggestionJob($asset->id),
        ])->dispatch();
    }
}

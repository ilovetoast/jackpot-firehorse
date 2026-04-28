<?php

namespace App\Jobs;

use App\Models\StudioLayerExtractionSession;
use App\Services\Studio\AiLayerExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StudioExtractLayersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $sessionId,
    ) {
        $this->onQueue((string) config('studio_layer_extraction.queue', 'ai'));
    }

    public function handle(AiLayerExtractionService $extractionService): void
    {
        $session = StudioLayerExtractionSession::query()->find($this->sessionId);
        if ($session === null) {
            return;
        }

        $extractionService->processSession($session);
    }
}

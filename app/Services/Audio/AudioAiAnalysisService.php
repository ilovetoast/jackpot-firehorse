<?php

namespace App\Services\Audio;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * AI analysis pipeline entry point for audio assets — transcript, tone,
 * and mood. The actual provider (Whisper, AssemblyAI, etc.) is injected
 * via the `assets.audio_ai.provider` config key once the user picks one.
 *
 * Contract for now (Phase 2 scaffold):
 *   - Mark `metadata.audio.ai_status` = 'queued' / 'processing' / 'failed' / 'completed'
 *   - Persist `metadata.audio.transcript`, `metadata.audio.summary`,
 *     `metadata.audio.mood`, `metadata.audio.detected_language` when ready.
 *
 * The service is intentionally a no-op until a provider is registered,
 * so we can ship the registry / job wiring without a hard external
 * dependency. This mirrors how GenerateVideoInsightsJob shipped before
 * its provider was bolted on.
 */
class AudioAiAnalysisService
{
    /**
     * @return array{success: bool, status: string, reason?: string, error?: string}
     */
    public function analyzeForAsset(Asset $asset): array
    {
        $providerKey = config('assets.audio_ai.provider');
        if (! $providerKey) {
            $this->markStatus($asset, 'pending_provider');
            Log::info('[AudioAiAnalysisService] No provider configured — marking pending', [
                'asset_id' => $asset->id,
            ]);

            return ['success' => false, 'status' => 'pending_provider', 'reason' => 'no_provider'];
        }

        $this->markStatus($asset, 'processing');

        try {
            // Future: resolve provider from config('assets.audio_ai.provider'), call
            // ->transcribe / ->moodAnalysis, persist transcript, summary, mood.
            // For now, mark as completed with no payload so the rest of the
            // pipeline can move on and the UI can render an "AI analysis not
            // available yet" hint.
            $this->markStatus($asset, 'completed', [
                'transcript' => null,
                'summary' => null,
                'mood' => null,
                'detected_language' => null,
                'analyzed_at' => now()->toIso8601String(),
            ]);

            return ['success' => true, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->markStatus($asset, 'failed', ['error' => $e->getMessage()]);
            Log::error('[AudioAiAnalysisService] failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function markStatus(Asset $asset, string $status, array $extra = []): void
    {
        $metadata = $asset->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }
        $metadata['audio'] = array_merge($metadata['audio'] ?? [], [
            'ai_status' => $status,
        ], $extra);
        $asset->update(['metadata' => $metadata]);
    }
}

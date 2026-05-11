<?php

namespace App\Services\Audio\Providers;

use App\Models\Asset;

/**
 * Phase 4: Pluggable AI backend for audio asset analysis.
 *
 * One method, one return shape — keeps providers (Whisper, AssemblyAI,
 * Deepgram, …) easy to swap. `AudioAiAnalysisService` is the only caller;
 * the rest of the pipeline never sees this interface directly.
 *
 * Implementations MUST:
 *   - Resolve a playable URL or temp-download for the asset themselves
 *     (we don't pre-fetch — different providers want different transports).
 *   - Respect `config('assets.audio_ai.<provider>.budget_cents_per_asset')`
 *     and abort + return `success: false, reason: 'budget_exceeded'` if
 *     the call would blow the budget.
 *   - Return ALL persisted fields under deterministic keys so the model
 *     accessor / search index can find them: `transcript`, `transcript_chunks`,
 *     `summary`, `mood`, `detected_language`, `provider`, `analyzed_at`.
 */
interface AudioAiProviderInterface
{
    /**
     * Run the provider against an audio asset.
     *
     * @return array{
     *     success: bool,
     *     reason?: string,
     *     error?: string,
     *     transcript?: string|null,
     *     transcript_chunks?: array<int, array{start: float, end: float, text: string}>|null,
     *     summary?: string|null,
     *     mood?: string|array<int, string>|null,
     *     detected_language?: string|null,
     *     provider?: string,
     *     cost_cents?: int,
     *     analyzed_at?: string,
     * }
     */
    public function analyze(Asset $asset): array;
}

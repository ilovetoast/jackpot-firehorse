<?php

namespace App\Services\Audio;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\AiUsageService;
use App\Services\Audio\Providers\AudioAiProviderInterface;
use App\Services\Audio\Providers\WhisperAudioAiProvider;
use Illuminate\Support\Facades\Log;

/**
 * AI analysis pipeline entry point for audio assets — transcript, mood,
 * and summary. The provider implementation is injected via the
 * `assets.audio_ai.provider` config key.
 *
 * Credit accounting (Phase 4 / unified pool):
 *   - Pre-flight: `AiUsageService::checkUsage($tenant, 'audio_insights', $credits)`
 *     where $credits = duration-tiered cost from
 *     {@see AiUsageService::getAudioInsightsCreditCost()}. Sub-1-minute clips
 *     still cost the base. We pre-flight BEFORE downloading the original from
 *     S3 so over-limit tenants don't burn egress + Whisper cents on a call
 *     we'll have to discard.
 *   - On success: `trackUsageWithCost($tenant, 'audio_insights', $credits, $costUsd, ...)`
 *     stores `call_count = $credits` (weight defaults to 1) so per-feature
 *     credit math in the dashboard matches what was actually enforced —
 *     same convention the studio-animation pipeline uses.
 *
 * Contract:
 *   - Mark `metadata.audio.ai_status` = 'queued' / 'processing' / 'pending_provider' /
 *     'failed' / 'completed' / 'budget_exceeded' / 'duration_exceeded' / 'plan_limit_exceeded'.
 *   - Persist `metadata.audio.transcript`, `metadata.audio.transcript_chunks`,
 *     `metadata.audio.summary`, `metadata.audio.mood`,
 *     `metadata.audio.detected_language`, `metadata.audio.provider`,
 *     `metadata.audio.cost_cents`, `metadata.audio.credits_charged`,
 *     `metadata.audio.analyzed_at` when ready.
 *   - Phase 4: Append transcript text into a flattened search column
 *     (`metadata.audio.transcript_search_blob`) so the asset search index
 *     can match audio by spoken words without re-fetching the chunks.
 *
 * Without a configured provider the service is a clean no-op — frontend
 * surfaces "AI analysis is queued" until the operator sets
 * ASSET_AUDIO_AI_PROVIDER (and provides the provider's API key).
 */
class AudioAiAnalysisService
{
    public const FEATURE_KEY = 'audio_insights';

    public function __construct(
        protected AiUsageService $aiUsageService,
    ) {
    }

    /**
     * @return array{success: bool, status: string, reason?: string, error?: string, credits_charged?: int}
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

        $provider = $this->resolveProvider((string) $providerKey);
        if (! $provider) {
            $this->markStatus($asset, 'failed', ['error' => "unknown provider: {$providerKey}"]);

            return ['success' => false, 'status' => 'failed', 'reason' => 'unknown_provider'];
        }

        // Resolve tenant + plan-pool cost up-front so we can short-circuit when
        // the workspace is out of credits (or has AI globally disabled in
        // tenant settings) without burning S3 egress + a Whisper call.
        $tenant = Tenant::find($asset->tenant_id);
        $durationSeconds = (float) ($asset->metadata['audio']['duration_seconds'] ?? 0.0);
        $creditsRequired = $this->aiUsageService->getAudioInsightsCreditCost($durationSeconds / 60.0);

        if ($tenant !== null) {
            try {
                $this->aiUsageService->checkUsage($tenant, self::FEATURE_KEY, $creditsRequired);
            } catch (PlanLimitExceededException $e) {
                $reason = $e->limitType === 'ai_disabled' ? 'ai_disabled' : 'plan_limit_exceeded';
                $this->markStatus($asset, 'plan_limit_exceeded', [
                    'reason' => $reason,
                    'credits_required' => $creditsRequired,
                    'duration_seconds' => $durationSeconds,
                ]);
                Log::warning('[AudioAiAnalysisService] credit pool gate blocked audio AI', [
                    'asset_id' => $asset->id,
                    'tenant_id' => $tenant->id,
                    'credits_required' => $creditsRequired,
                    'duration_seconds' => $durationSeconds,
                    'limit_type' => $e->limitType,
                ]);

                return [
                    'success' => false,
                    'status' => 'plan_limit_exceeded',
                    'reason' => $reason,
                ];
            }
        }

        $this->markStatus($asset, 'processing');

        try {
            $result = $provider->analyze($asset);
        } catch (\Throwable $e) {
            $this->markStatus($asset, 'failed', ['error' => $e->getMessage()]);
            Log::error('[AudioAiAnalysisService] provider threw', [
                'asset_id' => $asset->id,
                'provider' => $providerKey,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }

        if (! ($result['success'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'failed');
            $terminalStatus = match ($reason) {
                'budget_exceeded' => 'budget_exceeded',
                'duration_exceeded' => 'duration_exceeded',
                'no_api_key' => 'pending_provider',
                default => 'failed',
            };
            $this->markStatus($asset, $terminalStatus, [
                'error' => $result['error'] ?? null,
                'reason' => $reason,
            ]);

            return ['success' => false, 'status' => $terminalStatus, 'reason' => $reason];
        }

        // Bill the unified credit pool BEFORE persisting "completed" so a
        // double-dispatch can't silently bill twice for the same asset run
        // (track* throws PlanLimitExceededException on re-check; the catch
        // below records the analysis but skips the duplicate charge).
        $creditsCharged = 0;
        if ($tenant !== null) {
            try {
                $costUsd = isset($result['cost_cents']) ? ((int) $result['cost_cents']) / 100.0 : 0.0;
                $this->aiUsageService->trackUsageWithCost(
                    $tenant,
                    self::FEATURE_KEY,
                    $creditsRequired,
                    $costUsd,
                    null,
                    null,
                    isset($result['provider']) ? 'audio:'.$result['provider'] : 'audio:'.$providerKey,
                );
                $creditsCharged = $creditsRequired;
            } catch (PlanLimitExceededException $e) {
                Log::warning('[AudioAiAnalysisService] post-success credit charge skipped (pool exhausted between preflight and finalize)', [
                    'asset_id' => $asset->id,
                    'tenant_id' => $tenant->id,
                    'credits_required' => $creditsRequired,
                    'limit_type' => $e->limitType,
                ]);
            }
        }

        $this->markStatus($asset, 'completed', [
            'transcript' => $result['transcript'] ?? null,
            'transcript_chunks' => $result['transcript_chunks'] ?? null,
            'transcript_search_blob' => $this->buildSearchBlob($result['transcript'] ?? null),
            'summary' => $result['summary'] ?? null,
            'mood' => $result['mood'] ?? null,
            'detected_language' => $result['detected_language'] ?? null,
            'provider' => $result['provider'] ?? $providerKey,
            'cost_cents' => $result['cost_cents'] ?? null,
            'credits_charged' => $creditsCharged,
            'analyzed_at' => $result['analyzed_at'] ?? now()->toIso8601String(),
        ]);

        return ['success' => true, 'status' => 'completed', 'credits_charged' => $creditsCharged];
    }

    protected function resolveProvider(string $key): ?AudioAiProviderInterface
    {
        return match ($key) {
            'whisper' => app(WhisperAudioAiProvider::class),
            // Future: 'assemblyai' => app(AssemblyAiProvider::class), etc.
            default => null,
        };
    }

    /**
     * Phase 4 search index: lowercase, dedupe-ish, length-cap the
     * transcript so it can be cheaply LIKE-queried without blowing up
     * the search column on long podcasts. The flattened blob lives next
     * to the verbose chunks; chunks remain the source of truth for the
     * lightbox UI.
     */
    protected function buildSearchBlob(?string $transcript): ?string
    {
        if (! is_string($transcript) || $transcript === '') {
            return null;
        }
        $clean = preg_replace('/\s+/u', ' ', mb_strtolower($transcript));

        return mb_substr((string) $clean, 0, 32_000);
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

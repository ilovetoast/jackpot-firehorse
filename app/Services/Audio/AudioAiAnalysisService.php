<?php

namespace App\Services\Audio;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\AIConfigService;
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

    /** Agent ID registered in config/ai.php — also the credit feature key. */
    public const AGENT_ID = 'audio_insights';

    public function __construct(
        protected AiUsageService $aiUsageService,
        protected ?AIConfigService $aiConfigService = null,
    ) {
        $this->aiConfigService ??= app(AIConfigService::class);
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

        // Honor the Admin → AI → Agents toggle. Operators flipping this row
        // off must immediately stop new audio AI runs without redeploying.
        $agentConfig = $this->aiConfigService->getAgentConfig(self::AGENT_ID) ?? [];
        if (array_key_exists('active', $agentConfig) && $agentConfig['active'] === false) {
            $this->markStatus($asset, 'pending_provider', ['reason' => 'agent_disabled']);
            Log::info('[AudioAiAnalysisService] agent disabled in admin', [
                'asset_id' => $asset->id,
            ]);

            return ['success' => false, 'status' => 'pending_provider', 'reason' => 'agent_disabled'];
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
                $this->recordBlockedRun($asset, $tenant, $providerKey, $agentConfig, $reason, $creditsRequired, $durationSeconds);
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

        // Open the agent run row BEFORE calling out so operators see in-flight
        // audio AI calls in Activity (status='failed' until we mark success
        // — same convention the AIService text agents use).
        $agentRun = $this->openAgentRun($asset, $tenant, $providerKey, $agentConfig, $durationSeconds);

        try {
            $result = $provider->analyze($asset);
        } catch (\Throwable $e) {
            $this->markStatus($asset, 'failed', ['error' => $e->getMessage()]);
            $this->failAgentRun($agentRun, $e->getMessage());
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
            $this->failAgentRun($agentRun, $reason, ['provider_error' => $result['error'] ?? null]);

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

        $this->completeAgentRun($agentRun, $result, $creditsCharged);

        return ['success' => true, 'status' => 'completed', 'credits_charged' => $creditsCharged];
    }

    /**
     * Open an `ai_agent_runs` row so the Activity tab + observability tools
     * see audio AI in-flight. Mirrors the convention used by the text-agent
     * path in {@see \App\Services\AIService}: row starts as `failed`, gets
     * marked successful on completion.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function openAgentRun(
        Asset $asset,
        ?Tenant $tenant,
        string $providerKey,
        array $agentConfig,
        float $durationSeconds,
    ): ?AIAgentRun {
        try {
            return AIAgentRun::create([
                'agent_id' => self::AGENT_ID,
                'agent_name' => $agentConfig['name'] ?? 'Audio Insights',
                // Tenant-scoped, system-triggered (no end-user). The pipeline
                // detail lives under metadata.trigger_source for observability.
                'triggering_context' => $tenant !== null ? 'tenant' : 'system',
                'environment' => app()->environment(),
                'tenant_id' => $tenant?->id,
                'user_id' => null,
                'task_type' => AITaskType::AUDIO_INSIGHTS,
                'entity_type' => Asset::class,
                'entity_id' => (string) $asset->id,
                'model_used' => (string) ($agentConfig['default_model'] ?? config('assets.audio_ai.whisper.model', 'whisper-1')),
                'tokens_in' => 0,
                'tokens_out' => 0,
                'estimated_cost' => 0,
                'status' => 'failed',
                'started_at' => now(),
                'metadata' => [
                    'trigger_source' => 'audio_pipeline',
                    'provider' => $providerKey,
                    'duration_seconds' => $durationSeconds,
                ],
            ]);
        } catch (\Throwable $e) {
            // Never let observability bookkeeping break the actual analysis.
            Log::warning('[AudioAiAnalysisService] could not open agent run', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function completeAgentRun(?AIAgentRun $run, array $result, int $creditsCharged): void
    {
        if ($run === null) {
            return;
        }
        try {
            $run->markAsSuccessful(
                tokensIn: 0,
                tokensOut: 0,
                estimatedCost: isset($result['cost_cents']) ? ((int) $result['cost_cents']) / 100.0 : 0.0,
                metadata: array_merge($run->metadata ?? [], [
                    'provider' => $result['provider'] ?? null,
                    'detected_language' => $result['detected_language'] ?? null,
                    'mood' => $result['mood'] ?? null,
                    'transcript_chunk_count' => is_array($result['transcript_chunks'] ?? null)
                        ? count($result['transcript_chunks'])
                        : 0,
                    'credits_charged' => $creditsCharged,
                    'source_kind' => $result['source_kind'] ?? null,
                    'source_size_bytes' => $result['source_size_bytes'] ?? null,
                ]),
                summary: $this->shortLine($result['summary'] ?? null) ?: 'Audio transcription complete',
            );
        } catch (\Throwable $e) {
            Log::warning('[AudioAiAnalysisService] could not mark agent run success', [
                'run_id' => $run->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function failAgentRun(?AIAgentRun $run, string $errorMessage, array $extra = []): void
    {
        if ($run === null) {
            return;
        }
        try {
            $run->markAsFailed($errorMessage, array_merge($run->metadata ?? [], $extra));
        } catch (\Throwable $e) {
            Log::warning('[AudioAiAnalysisService] could not mark agent run failed', [
                'run_id' => $run->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Plan-limit / blocked runs still get a row so admins can see "we tried,
     * the workspace was out of credits." Marked `status=failed` with
     * `blocked_reason` so it shows distinct from real provider failures.
     *
     * @param  array<string, mixed>  $agentConfig
     */
    protected function recordBlockedRun(
        Asset $asset,
        Tenant $tenant,
        string $providerKey,
        array $agentConfig,
        string $reason,
        int $creditsRequired,
        float $durationSeconds,
    ): void {
        try {
            AIAgentRun::create([
                'agent_id' => self::AGENT_ID,
                'agent_name' => $agentConfig['name'] ?? 'Audio Insights',
                'triggering_context' => 'tenant',
                'environment' => app()->environment(),
                'tenant_id' => $tenant->id,
                'user_id' => null,
                'task_type' => AITaskType::AUDIO_INSIGHTS,
                'entity_type' => Asset::class,
                'entity_id' => (string) $asset->id,
                'model_used' => (string) ($agentConfig['default_model'] ?? 'whisper-1'),
                'tokens_in' => 0,
                'tokens_out' => 0,
                'estimated_cost' => 0,
                'status' => 'failed',
                'severity' => 'warning',
                'summary' => 'Audio AI blocked by plan limit',
                'blocked_reason' => $reason,
                'started_at' => now(),
                'completed_at' => now(),
                'metadata' => [
                    'trigger_source' => 'audio_pipeline',
                    'provider' => $providerKey,
                    'duration_seconds' => $durationSeconds,
                    'credits_required' => $creditsRequired,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AudioAiAnalysisService] could not record blocked run', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function shortLine(?string $text): ?string
    {
        if (! is_string($text) || $text === '') {
            return null;
        }
        $clean = preg_replace('/\s+/u', ' ', $text);

        return mb_substr((string) $clean, 0, 220);
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

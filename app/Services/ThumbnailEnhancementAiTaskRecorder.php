<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Models\AssetVersion;

/**
 * AI agent run records for enhanced (template / future AI) previews — audit + dashboards without LLM.
 */
final class ThumbnailEnhancementAiTaskRecorder
{
    public const AGENT_ID = 'thumbnail_enhancement';

    public const SKIP_REASON_TOO_SMALL = 'too_small';

    public const SKIP_REASON_NO_SOURCE = 'no_source';

    /**
     * @param  array<string, mixed>  $optional  template_version, attempt, model, tokens_input, tokens_output, cost
     */
    public function start(
        Asset $asset,
        AssetVersion $version,
        string $inputMode,
        string $templateId,
        array $optional = []
    ): AIAgentRun {
        $base = [
            'asset_id' => $asset->id,
            'version_id' => (string) $version->id,
            'input_mode' => $inputMode,
            'output_mode' => 'enhanced',
            'template' => $templateId,
            'generation_type' => 'template',
            'in_progress' => true,
        ];

        if (array_key_exists('template_version', $optional) && $optional['template_version'] !== null) {
            $base['template_version'] = $optional['template_version'];
        }
        if (array_key_exists('attempt', $optional) && $optional['attempt'] !== null) {
            $base['attempt'] = $optional['attempt'];
        }

        $extras = [];
        foreach (['model', 'tokens_input', 'tokens_output', 'cost'] as $key) {
            if (array_key_exists($key, $optional) && $optional[$key] !== null) {
                $extras[$key] = $optional[$key];
            }
        }

        return AIAgentRun::create([
            'agent_id' => self::AGENT_ID,
            'agent_name' => 'Thumbnail enhancement',
            'triggering_context' => 'tenant',
            'environment' => app()->environment(),
            'tenant_id' => $asset->tenant_id,
            'user_id' => null,
            'task_type' => AITaskType::THUMBNAIL_ENHANCEMENT,
            'entity_type' => 'asset',
            'entity_id' => (string) $asset->id,
            'model_used' => '',
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
            'completed_at' => null,
            'metadata' => array_merge($base, $extras),
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch  Merged into JSON metadata (e.g. input_hash after S3 head).
     */
    public function mergeMetadata(AIAgentRun $run, array $patch): void
    {
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], $patch),
        ]);
    }

    /**
     * @param  array<string, mixed>  $optional  Future: tokens_input, tokens_output, cost, model overrides
     */
    public function succeed(AIAgentRun $run, int $durationMs, array $optional = []): void
    {
        $run->refresh();

        $meta = array_merge($run->metadata ?? [], [
            'in_progress' => false,
            'duration_ms' => $durationMs,
            'model' => array_key_exists('model', $optional) ? $optional['model'] : null,
        ]);

        foreach (['tokens_input', 'tokens_output', 'cost'] as $key) {
            if (array_key_exists($key, $optional) && $optional[$key] !== null) {
                $meta[$key] = $optional[$key];
            }
        }

        $tokensIn = isset($optional['tokens_input']) ? (int) $optional['tokens_input'] : 0;
        $tokensOut = isset($optional['tokens_output']) ? (int) $optional['tokens_output'] : 0;
        $cost = isset($optional['cost']) ? (float) $optional['cost'] : 0.0;

        $run->markAsSuccessful($tokensIn, $tokensOut, $cost, $meta);

        if (isset($optional['model']) && is_string($optional['model']) && $optional['model'] !== '') {
            $run->update(['model_used' => $optional['model']]);
        }
    }

    public function fail(AIAgentRun $run, string $failureMessage): void
    {
        $meta = array_merge($run->metadata ?? [], [
            'in_progress' => false,
            'skipped' => false,
            'failure_message' => $failureMessage,
        ]);
        $run->markAsFailed($failureMessage, $meta);
    }

    /**
     * Guardrail / expected no-op — does not record application_error_events.
     *
     * @param  string  $skipReason  e.g. {@see self::SKIP_REASON_TOO_SMALL}
     */
    public function skip(AIAgentRun $run, string $message, string $skipReason): void
    {
        $meta = array_merge($run->metadata ?? [], [
            'in_progress' => false,
            'skipped' => true,
            'skip_reason' => $skipReason,
            'failure_message' => $message,
        ]);
        $run->markAsSkipped($message, $meta);
    }
}

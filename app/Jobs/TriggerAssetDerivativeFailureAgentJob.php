<?php

namespace App\Jobs;

use App\Enums\AITaskType;
use App\Models\AssetDerivativeFailure;
use App\Services\AIService;
use App\Services\AssetDerivativeFailureEscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase T-1: Triggers AI agent analysis when asset derivative generation fails.
 *
 * Dispatched when:
 * - failure_count >= 2
 * - OR processor timeout / OOM
 *
 * OBSERVABILITY ONLY. Never affects Asset.status or visibility.
 */
class TriggerAssetDerivativeFailureAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $assetDerivativeFailureId
    ) {
    }

    public function handle(AIService $aiService, AssetDerivativeFailureEscalationService $escalationService): void
    {
        $record = AssetDerivativeFailure::with('asset')->find($this->assetDerivativeFailureId);
        if (! $record || ! $record->asset) {
            Log::warning('[TriggerAssetDerivativeFailureAgentJob] Record or asset not found', ['id' => $this->assetDerivativeFailureId]);
            return;
        }

        $trace = $record->metadata['exception_trace'] ?? 'No trace';
        if (is_string($trace) && strlen($trace) > 2000) {
            $trace = substr($trace, 0, 2000) . '...[truncated]';
        }
        $codec = $record->metadata['codec'] ?? 'unknown';
        $mime = $record->metadata['mime'] ?? 'unknown';

        $prompt = <<<PROMPT
Analyze this asset derivative generation failure:

- Asset ID: {$record->asset_id}
- Derivative type: {$record->derivative_type}
- Processor: {$record->processor}
- Failure reason: {$record->failure_reason}
- Failure count: {$record->failure_count}
- Codec: {$codec}
- MIME: {$mime}

Exception trace excerpt:
{$trace}

Respond with a JSON object containing:
1. "summary": Brief 1-2 sentence summary of likely failure cause
2. "severity": Either "info", "warning", "system", or "data"
3. "recommendation": Optional action recommendation

Format: {"summary": "...", "severity": "...", "recommendation": "..."}
PROMPT;

        try {
            $response = $aiService->executeAgent(
                'asset_derivative_failure_analyzer',
                AITaskType::ASSET_DERIVATIVE_FAILURE_ANALYSIS,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $record->asset->tenant_id,
                    'asset_id' => $record->asset_id,
                    'derivative_failure_id' => $record->id,
                ]
            );

            $severity = $this->parseSeverity($response['text'] ?? '');

            Log::info('[TriggerAssetDerivativeFailureAgentJob] Agent completed', [
                'derivative_failure_id' => $record->id,
                'severity' => $severity,
            ]);

            if ($severity === 'system') {
                $escalationService->createTicketIfNeeded($record, $response['text'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::error('[TriggerAssetDerivativeFailureAgentJob] Agent failed', [
                'derivative_failure_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
            $escalationService->createTicketIfNeeded($record, null);
        }
    }

    protected function parseSeverity(string $text): string
    {
        if (stripos($text, '"severity"') !== false && preg_match('/"severity"\s*:\s*"([^"]+)"/', $text, $m)) {
            $s = strtolower(trim($m[1]));
            return ($s === 'system') ? 'system' : (in_array($s, ['info', 'warning', 'data']) ? $s : 'warning');
        }
        if (stripos($text, 'system') !== false && stripos($text, 'severity') !== false) {
            return 'system';
        }

        return 'warning';
    }
}

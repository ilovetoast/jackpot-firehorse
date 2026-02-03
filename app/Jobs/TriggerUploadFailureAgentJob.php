<?php

namespace App\Jobs;

use App\Enums\AITaskType;
use App\Enums\UploadFailureReason;
use App\Models\UploadSession;
use App\Services\AIService;
use App\Services\UploadFailureEscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase U-1: Triggers AI agent analysis when upload fails.
 *
 * Dispatched when:
 * - failure_count >= 2
 * - OR failure_reason in (transfer_failed, finalize_failed, thumbnail_failed)
 *
 * OBSERVABILITY ONLY. Does not block uploads.
 */
class TriggerUploadFailureAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $uploadSessionId
    ) {
    }

    public function handle(AIService $aiService, UploadFailureEscalationService $escalationService): void
    {
        $upload = UploadSession::withTrashed()->find($this->uploadSessionId);
        if (! $upload) {
            Log::warning('[TriggerUploadFailureAgentJob] Upload session not found', ['upload_id' => $this->uploadSessionId]);
            return;
        }

        $trace = $upload->upload_options['upload_failure_trace'] ?? 'No trace';
        if (is_string($trace) && strlen($trace) > 2000) {
            $trace = substr($trace, 0, 2000) . '...[truncated]';
        }

        $stage = $upload->upload_options['upload_failure_stage'] ?? 'unknown';
        $failureReason = $upload->failure_reason instanceof UploadFailureReason
            ? $upload->failure_reason->value
            : ($upload->failure_reason ?? 'unknown');
        $clientType = $upload->upload_options['client_type'] ?? 'unknown';

        $prompt = <<<PROMPT
Analyze this upload failure:

- Upload ID: {$upload->id}
- Tenant ID: {$upload->tenant_id}
- Stage: {$stage}
- Bytes uploaded: {$upload->uploaded_size}
- Expected size: {$upload->expected_size}
- Failure count: {$upload->failure_count}
- Failure reason: {$failureReason}
- Client type: {$clientType}

Exception trace excerpt:
{$trace}

Respond with a JSON object containing:
1. "summary": Brief 1-2 sentence summary of likely failure cause
2. "severity": Either "info", "warning", "system", or "data" (AIAgentSeverity)
3. "recommendation": Optional action recommendation

Format: {"summary": "...", "severity": "...", "recommendation": "..."}
PROMPT;

        try {
            $response = $aiService->executeAgent(
                'upload_failure_analyzer',
                AITaskType::UPLOAD_FAILURE_ANALYSIS,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $upload->tenant_id,
                    'upload_id' => $upload->id,
                ]
            );

            $severity = $this->parseSeverity($response['text'] ?? '');

            Log::info('[TriggerUploadFailureAgentJob] Agent completed', [
                'upload_id' => $upload->id,
                'severity' => $severity,
            ]);

            if ($severity === 'system') {
                $escalationService->createTicketIfNeeded($upload, $response['text'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::error('[TriggerUploadFailureAgentJob] Agent failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
            $escalationService->createTicketIfNeeded($upload, null);
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

    /**
     * Check if agent job should be dispatched for this upload after recordFailure.
     */
    public static function shouldTrigger(UploadSession $upload): bool
    {
        $count = $upload->failure_count ?? 0;
        if ($count >= 2) {
            return true;
        }

        $reason = $upload->failure_reason;
        $reasonValue = $reason instanceof UploadFailureReason ? $reason->value : (string) $reason;
        $criticalReasons = [
            UploadFailureReason::TRANSFER_FAILED->value,
            UploadFailureReason::FINALIZE_FAILED->value,
            UploadFailureReason::THUMBNAIL_FAILED->value,
        ];

        return in_array($reasonValue, $criticalReasons, true);
    }
}

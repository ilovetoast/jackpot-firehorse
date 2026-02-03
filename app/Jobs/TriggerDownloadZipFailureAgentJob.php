<?php

namespace App\Jobs;

use App\Enums\AITaskType;
use App\Models\Download;
use App\Services\AIService;
use App\Services\DownloadZipFailureEscalationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Triggers AI agent analysis when download ZIP build fails.
 *
 * Dispatched when:
 * - failure_reason === timeout
 * - OR failure_count >= 2
 *
 * Agent tasks: summarize failure, classify severity, recommend action.
 * If severity === "system", creates support ticket.
 */
class TriggerDownloadZipFailureAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $downloadId
    ) {}

    public function handle(AIService $aiService, DownloadZipFailureEscalationService $escalationService): void
    {
        $download = Download::withTrashed()->find($this->downloadId);
        if (! $download) {
            Log::warning('[TriggerDownloadZipFailureAgentJob] Download not found', ['download_id' => $this->downloadId]);
            return;
        }

        $assetCount = $download->assets()->count();
        $totalBytes = $download->download_options['estimated_bytes'] ?? 0;
        $workerTimeout = config('queue.connections.' . config('queue.default') . '.retry_after', 900);

        $prompt = $this->buildPrompt($download, $assetCount, $totalBytes, $workerTimeout);

        try {
            $response = $aiService->executeAgent(
                'download_zip_failure_analyzer',
                AITaskType::DOWNLOAD_ZIP_FAILURE_ANALYSIS,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'tenant_id' => $download->tenant_id,
                ]
            );

            $severity = $this->parseSeverity($response['text'] ?? '');

            Log::info('[TriggerDownloadZipFailureAgentJob] Agent completed', [
                'download_id' => $download->id,
                'severity' => $severity,
            ]);

            if ($severity === 'system') {
                $escalationService->createTicketIfNeeded($download, $response['text'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::error('[TriggerDownloadZipFailureAgentJob] Agent failed', [
                'download_id' => $download->id,
                'error' => $e->getMessage(),
            ]);
            // Still create ticket if failure_count >= 3 (escalation service handles this)
            $escalationService->createTicketIfNeeded($download, null);
        }
    }

    protected function buildPrompt(Download $download, int $assetCount, $totalBytes, int $workerTimeout): string
    {
        $traceExcerpt = $download->download_options['zip_failure_trace'] ?? 'No trace';
        if (is_string($traceExcerpt) && strlen($traceExcerpt) > 2000) {
            $traceExcerpt = substr($traceExcerpt, 0, 2000) . '...[truncated]';
        }

        $failureReason = $download->failure_reason?->value ?? 'unknown';

        return <<<PROMPT
Analyze this download ZIP build failure:

- Download ID: {$download->id}
- Tenant ID: {$download->tenant_id}
- Asset count: {$assetCount}
- Estimated total bytes: {$totalBytes}
- Failure reason: {$failureReason}
- Failure count: {$download->failure_count}
- Worker timeout (seconds): {$workerTimeout}

Job trace excerpt:
{$traceExcerpt}

Respond with a JSON object containing:
1. "summary": Brief 1-2 sentence summary of likely failure cause
2. "severity": Either "user-fixable" (e.g. too many assets, user can split download) or "system" (e.g. timeout, infra, needs engineering)
3. "recommendation": One of: "auto_retry", "suggest_split_download", "escalate_to_engineering"

Format: {"summary": "...", "severity": "...", "recommendation": "..."}
PROMPT;
    }

    protected function parseSeverity(string $text): string
    {
        if (stripos($text, '"severity":') !== false && preg_match('/"severity"\s*:\s*"([^"]+)"/', $text, $m)) {
            $s = strtolower($m[1]);
            return ($s === 'system') ? 'system' : 'user-fixable';
        }
        if (stripos($text, 'system') !== false && stripos($text, 'severity') !== false) {
            return 'system';
        }
        return 'user-fixable';
    }
}

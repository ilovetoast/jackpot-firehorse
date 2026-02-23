<?php

namespace App\Jobs;

use App\Services\SentryAI\SentryAIAnalyzer;
use App\Services\SentryAI\SentryAIConfigService;
use App\Services\SentryAI\SentryPullService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job: pull unresolved Sentry issues, upsert, then run AI analysis for issues without ai_summary.
 *
 * Respects SENTRY_PULL_ENABLED and SENTRY_EMERGENCY_DISABLE. No auto-heal.
 */
class PullSentryIssuesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        SentryPullService $pullService,
        SentryAIConfigService $config,
        SentryAIAnalyzer $analyzer
    ): void {
        if (! $config->pullEnabled()) {
            Log::info('[PullSentryIssuesJob] Pull disabled (SENTRY_PULL_ENABLED or SENTRY_EMERGENCY_DISABLE)');

            return;
        }

        $result = $pullService->pull();

        Cache::put('sentry_ai.last_sync_at', now()->toIso8601String(), now()->addDays(7));

        Log::info('[PullSentryIssuesJob] Sentry pull completed', [
            'total_pulled' => $result['pulled'],
            'total_new' => $result['new'],
            'total_updated' => $result['updated'],
        ]);

        $issues = $result['issues'] ?? [];
        $analyzed = 0;
        foreach ($issues as $issue) {
            if ($issue->ai_summary !== null && trim((string) $issue->ai_summary) !== '') {
                continue;
            }
            if ($analyzer->analyze($issue)) {
                $analyzed++;
            }
        }
        if ($analyzed > 0) {
            Log::info('[PullSentryIssuesJob] AI analysis completed', ['analyzed' => $analyzed]);
        }
    }
}

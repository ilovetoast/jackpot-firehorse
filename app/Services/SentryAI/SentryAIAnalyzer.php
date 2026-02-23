<?php

namespace App\Services\SentryAI;

use App\Enums\AITaskType;
use App\Models\SentryIssue;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * AI summarization for Sentry issues: summary, root cause, minimal fix suggestion.
 *
 * Respects emergency disable and Sentry monthly AI cost limit.
 * Uses existing AIService (agent_id = sentry_error_analyzer) and logs to ai_agent_runs.
 * Model is configurable via SentryAIConfigService::model().
 * Monthly spend uses ai_analyzed_at for correct accounting (not updated_at).
 * Estimated cost for limit check is configurable (SENTRY_AI_ESTIMATED_COST_PER_ANALYSIS); future: token-based or rolling average.
 */
class SentryAIAnalyzer
{

    public function __construct(
        protected SentryAIConfigService $config,
        protected AIService $aiService
    ) {
    }

    /**
     * Analyze a Sentry issue with AI and save summary, root cause, fix suggestion, and cost.
     *
     * @return bool True if analysis ran and was saved; false if skipped (disabled, limit, or no stack trace).
     */
    public function analyze(SentryIssue $issue): bool
    {
        if ($this->config->isEmergencyDisabled()) {
            return false;
        }

        $stackTrace = $issue->stack_trace ?? '';
        $stackTrace = trim($stackTrace);
        if ($stackTrace === '') {
            return false;
        }

        $currentMonthSpend = $this->getCurrentMonthSentryAiSpend();
        $limit = $this->config->monthlyLimit();
        if ($currentMonthSpend + $this->estimatedCostPerAnalysis() > $limit) {
            Log::warning('[SentryAIAnalyzer] Monthly AI cost limit exceeded, skipping analysis', [
                'sentry_issue_id' => $issue->sentry_issue_id,
                'current_month_spend' => $currentMonthSpend,
                'limit' => $limit,
            ]);

            return false;
        }

        $model = $this->config->model();
        $prompt = $this->buildPrompt($issue, $stackTrace);

        try {
            $result = $this->aiService->executeAgent(
                'sentry_error_analyzer',
                AITaskType::SENTRY_ERROR_ANALYSIS,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'model' => $model,
                    'sentry_issue_id' => $issue->id,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[SentryAIAnalyzer] AI analysis failed', [
                'sentry_issue_id' => $issue->sentry_issue_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $parsed = $this->parseResponse($result['text'] ?? '');
        $cost = (float) ($result['cost'] ?? 0);
        $tokensIn = (int) ($result['tokens_in'] ?? 0);
        $tokensOut = (int) ($result['tokens_out'] ?? 0);

        $issue->update([
            'ai_summary' => $parsed['summary'],
            'ai_root_cause' => $parsed['root_cause'],
            'ai_fix_suggestion' => $parsed['fix_suggestion'],
            'ai_token_input' => $tokensIn,
            'ai_token_output' => $tokensOut,
            'ai_cost' => $cost,
            'ai_analyzed_at' => now(),
        ]);

        return true;
    }

    /**
     * Current month total AI cost for Sentry analyses.
     * Uses ai_analyzed_at (not updated_at) so reanalyze/other updates don't shift spend to another month.
     */
    protected function getCurrentMonthSentryAiSpend(): float
    {
        $now = now();

        return (float) SentryIssue::query()
            ->whereNotNull('ai_cost')
            ->whereNotNull('ai_analyzed_at')
            ->whereYear('ai_analyzed_at', $now->year)
            ->whereMonth('ai_analyzed_at', $now->month)
            ->sum('ai_cost');
    }

    /**
     * Estimated cost per analysis (USD) for monthly limit pre-check.
     * Configurable via SENTRY_AI_ESTIMATED_COST_PER_ANALYSIS. Future: derive from token estimate or rolling average.
     */
    protected function estimatedCostPerAnalysis(): float
    {
        return (float) config('sentry_ai.estimated_cost_per_analysis', 0.005);
    }

    protected function buildPrompt(SentryIssue $issue, string $stackTrace): string
    {
        $title = $issue->title ?? 'Unknown';
        $level = $issue->level ?? 'error';

        return <<<PROMPT
Analyze this error report and respond with exactly three sections. Use the section headers below.

Error title: {$title}
Level: {$level}

Stack trace or error details:
---
{$stackTrace}
---

Respond in this format (use these exact headers):

## Summary
(One or two sentences describing what went wrong.)

## Root cause
(Brief explanation of the underlying cause.)

## Fix suggestion
(Minimal, actionable fix suggestion—code or config change if applicable.)
PROMPT;
    }

    /**
     * Parse markdown-style response. Future improvement: ask model for strict JSON
     * {"summary":"","root_cause":"","fix_suggestion":""} and json_decode for stability when models change.
     *
     * @return array{summary: string|null, root_cause: string|null, fix_suggestion: string|null}
     */
    protected function parseResponse(string $text): array
    {
        $result = [
            'summary' => null,
            'root_cause' => null,
            'fix_suggestion' => null,
        ];
        $text = trim($text);
        if ($text === '') {
            return $result;
        }

        if (preg_match('/##\s*Summary\s*\n(.*?)(?=##\s*Root cause|$)/si', $text, $m)) {
            $result['summary'] = trim($m[1]);
        }
        if (preg_match('/##\s*Root cause\s*\n(.*?)(?=##\s*Fix suggestion|$)/si', $text, $m)) {
            $result['root_cause'] = trim($m[1]);
        }
        if (preg_match('/##\s*Fix suggestion\s*\n(.*)/si', $text, $m)) {
            $result['fix_suggestion'] = trim($m[1]);
        }

        return $result;
    }
}

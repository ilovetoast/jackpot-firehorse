<?php

namespace App\Services\ContextualNavigation;

use App\Enums\AITaskType;
use App\Exceptions\PlanLimitExceededException;
use App\Models\ContextualNavigationRecommendation;
use App\Models\Tenant;
use App\Services\AIService;
use App\Services\AiUsageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 — optional AI rationale enrichment for borderline recommendations.
 *
 * Hard contract:
 *   - Goes through AIService::executeAgent (the standard agent runner) so
 *     ai_agent_runs gets a row, AIBudgetService is consulted, and overrides
 *     in DB are honored. NO direct provider calls.
 *   - Pre-checks AiUsageService::checkUsage($tenant, 'contextual_navigation')
 *     for each call so the tenant credit pool is respected.
 *   - Records usage via trackUsageWithCost (cost from the agent run) AFTER
 *     a successful invocation. Failed runs do NOT debit credits.
 *   - Catches PlanLimitExceededException and returns gracefully — the
 *     statistical recommendations stay in place; only the rationale is
 *     missing.
 *
 * The agent ONLY produces:
 *   - a refined `reason_summary` string
 *   - an optional `confidence` (0.0–1.0)
 * Nothing structural. The recommender already decided WHICH type fires
 * and at WHAT score; the reasoner only writes prose for admins.
 */
class ContextualNavigationAiReasoner
{
    public function __construct(
        protected AIService $aiService,
        protected AiUsageService $usageService,
    ) {}

    /**
     * Enrich each recommendation in-place. Returns the count actually
     * touched (may be lower than $rows->count() if quota exhausted mid-run
     * or the model returns malformed output).
     *
     * @param  Collection<int, ContextualNavigationRecommendation>  $rows
     */
    public function enrich(Tenant $tenant, Collection $rows): int
    {
        $enabled = (bool) config('contextual_navigation_insights.use_ai_reasoning', false);
        if (! $enabled || $rows->isEmpty()) {
            return 0;
        }

        $agentKey = (string) config('contextual_navigation_insights.agent_key', 'contextual_navigation_intelligence');
        $usageFeature = (string) config('contextual_navigation_insights.usage_feature', 'contextual_navigation');

        $touched = 0;
        foreach ($rows as $rec) {
            // Per-call quota pre-check. checkUsage throws on either
            // master AI off or monthly cap exhausted; both are fatal for
            // the AI step but NOT for the statistical recommendations.
            try {
                $this->usageService->checkUsage($tenant, $usageFeature, 1);
            } catch (PlanLimitExceededException $e) {
                Log::info('[ContextualNavigationAiReasoner] tenant credit pool exhausted; skipping AI enrichment.', [
                    'tenant_id' => $tenant->id,
                    'remaining' => $rows->count() - $touched,
                ]);
                break;
            }

            try {
                $result = $this->aiService->executeAgent(
                    agentId: $agentKey,
                    taskType: AITaskType::CONTEXTUAL_NAVIGATION_REASONING,
                    prompt: $this->buildPrompt($rec),
                    options: [
                        'tenant' => $tenant,
                        'tenant_id' => $tenant->id,
                        'triggering_context' => 'system',
                        // AIService::extractEntityFromOptions reads these
                        // so the run row is auditable per recommendation.
                        'entity_type' => 'contextual_navigation_recommendation',
                        'entity_id' => $rec->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('[ContextualNavigationAiReasoner] agent invocation failed; keeping statistical reason.', [
                    'tenant_id' => $tenant->id,
                    'recommendation_id' => $rec->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $parsed = $this->parseAgentOutput($result['text'] ?? '');
            if ($parsed === null) {
                // Don't debit credits; mark the run with a soft failure
                // indicator in metrics so analysts can find it.
                continue;
            }

            $metrics = is_array($rec->metrics) ? $rec->metrics : [];
            $metrics['rationale'] = $parsed['rationale'];
            $metrics['ai_agent_run_id'] = $result['agent_run_id'] ?? null;
            $metrics['ai_model'] = $result['model'] ?? null;

            $rec->reason_summary = mb_substr($parsed['rationale'], 0, 480);
            $rec->source = ContextualNavigationRecommendation::SOURCE_HYBRID;
            if (isset($parsed['confidence'])) {
                $rec->confidence = $parsed['confidence'];
            }
            $rec->metrics = $metrics;
            $rec->save();

            // Debit credits ONLY on success. Cost from the run row.
            try {
                $this->usageService->trackUsageWithCost(
                    $tenant,
                    $usageFeature,
                    1,
                    (float) ($result['cost'] ?? 0.0),
                    (int) ($result['tokens_in'] ?? 0),
                    (int) ($result['tokens_out'] ?? 0),
                    (string) ($result['model'] ?? ''),
                );
            } catch (\Throwable $e) {
                Log::warning('[ContextualNavigationAiReasoner] usage tracking failed (recommendation already enriched).', [
                    'tenant_id' => $tenant->id,
                    'recommendation_id' => $rec->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $touched++;
        }

        return $touched;
    }

    /**
     * Compact prompt — we feed pre-computed numbers, not raw asset data,
     * so the model never sees customer content. Output schema is locked
     * to two fields so parsing stays trivial.
     */
    private function buildPrompt(ContextualNavigationRecommendation $rec): string
    {
        $type = $rec->recommendation_type;
        $score = number_format((float) $rec->score, 3);
        $metrics = is_array($rec->metrics) ? $rec->metrics : [];
        $coverage = isset($metrics['coverage']) ? round(((float) $metrics['coverage']) * 100) : null;
        $narrowing = isset($metrics['narrowing_power']) ? round(((float) $metrics['narrowing_power']) * 100) : null;
        $cardinality = isset($metrics['cardinality_penalty']) ? round(((float) $metrics['cardinality_penalty']) * 100) : null;
        $usage = isset($metrics['usage']) ? round(((float) $metrics['usage']) * 100) : null;
        $distinct = $metrics['counters']['distinct_values'] ?? null;
        $folderAssets = $metrics['counters']['folder_asset_count'] ?? null;

        return <<<PROMPT
You are an enterprise DAM admin assistant. Given the recommendation below, produce a one-sentence rationale (≤ 220 chars) an admin can read at a glance, plus a confidence in [0.0, 1.0].

Recommendation type: {$type}
Statistical score: {$score}
Coverage: {$coverage}%
Narrowing power: {$narrowing}%
Cardinality score: {$cardinality}%
Usage signal: {$usage}%
Distinct values: {$distinct}
Folder asset count: {$folderAssets}

Reply with JSON ONLY, no prose:
{"rationale": "...", "confidence": 0.0}
PROMPT;
    }

    /**
     * Strict JSON parse with a permissive fallback (some models wrap
     * output in code fences). Returns null if no usable rationale found.
     *
     * @return array{rationale: string, confidence: ?float}|null
     */
    private function parseAgentOutput(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        // Strip Markdown code fences if present.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Try to extract the first {...} payload.
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }
        if (! is_array($decoded)) return null;
        $rationale = trim((string) ($decoded['rationale'] ?? ''));
        if ($rationale === '') return null;

        $confidence = null;
        if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
            $c = (float) $decoded['confidence'];
            if ($c < 0) $c = 0.0;
            if ($c > 1) $c = 1.0;
            $confidence = $c;
        }

        return ['rationale' => $rationale, 'confidence' => $confidence];
    }
}

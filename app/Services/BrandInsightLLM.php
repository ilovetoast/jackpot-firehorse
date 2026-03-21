<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Brand Insight LLM — LLM-generated human-readable insights.
 *
 * Uses internal AIService (model registry, token tracking, tenant/brand association).
 * Cached 30 min. Falls back to rule-based BrandInsightAI on failure.
 * Type-based href mapping for deterministic navigation (no hallucinated routes).
 */
class BrandInsightLLM
{
    public const CACHE_TTL_MINUTES = 30;

    public const CACHE_KEY_PREFIX = 'brand:';

    public const CACHE_KEY_SUFFIX = ':ai-insights-v2';

    public const VALID_TYPES = ['suggestions', 'metadata', 'activity', 'sharing', 'rights', 'ai_tags', 'ai_categories'];

    public function __construct(
        protected AIService $aiService,
        protected BrandInsightAI $brandInsightAI
    ) {}

    /**
     * Get cached insights for a brand.
     * Falls back to rule-based insights if LLM fails.
     *
     * @param  array  $signals  Full signal objects from BrandInsightEngine (type, label, priority, href, context)
     * @return array<array{text: string, priority: string, type?: string, href?: string}>
     */
    public function getInsightsForBrand(Brand $brand, array $signals = [], ?User $user = null): array
    {
        // Per-user cache: signals are role/permission-specific (see BrandInsightEngine::getSignals).
        $bust = (int) Cache::get('brand:'.$brand->id.':insights-bust', 0);
        $userKey = $user ? 'user:'.$user->id : 'anon';
        $cacheKey = self::CACHE_KEY_PREFIX.$brand->id.self::CACHE_KEY_SUFFIX.':b'.$bust.':'.$userKey;

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($brand, $signals) {
            $metrics = $this->brandInsightAI->getMetricsForBrand($brand);
            try {
                $insights = $this->generateInsights($brand, $metrics, $signals);
                if (! empty($insights)) {
                    $insights = $this->postProcessInsights($insights, $signals);

                    return $this->applyConfidenceFiltering($insights, $metrics);
                }
            } catch (\Throwable $e) {
                Log::warning('[BrandInsightLLM] AI call failed, falling back to rule-based', [
                    'brand_id' => $brand->id,
                    'tenant_id' => $brand->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->fallbackToRuleBased($brand, $metrics);
        });
    }

    /**
     * Generate 1–2 LLM insights from metrics.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<string>  $signals
     * @return array<array{text: string, priority: string, type?: string}>
     */
    public function generateInsights(Brand $brand, array $metrics, array $signals = []): array
    {
        $payload = $this->buildInsightPayload($metrics);
        $prompt = $this->buildPrompt($payload, $signals);

        $tenant = $brand->tenant;
        if (! $tenant) {
            return $this->fallbackToRuleBased($brand, $metrics);
        }

        $result = $this->aiService->executeAgent(
            'brand_insights',
            AITaskType::BRAND_INSIGHTS,
            $prompt,
            [
                'tenant_id' => $tenant->id,
                'brand_id' => $brand->id,
                'triggering_context' => 'tenant',
            ]
        );

        return $this->parseResponse($result['text'] ?? '', $signals);
    }

    /**
     * Map insight type to deterministic href (no hallucinated routes).
     * Uses asset grid for suggestions/metadata; analytics for rights.
     */
    protected function mapInsightTypeToHref(string $type): string
    {
        return match ($type) {
            'suggestions', 'ai_tags' => '/app/insights/review?tab=tags',
            'ai_categories' => '/app/insights/review?tab=categories',
            'metadata' => '/app/assets?missing_metadata=1',
            'activity' => '/app/assets',
            'sharing' => '/app/downloads',
            'rights' => '/app/insights/overview?tab=rights',
            default => '/app/insights/overview',
        };
    }

    /**
     * Resolve href for an insight: prefer matching signal's href (has asset_id when available).
     */
    protected function resolveInsightHref(array $insight, array $signals): ?string
    {
        $type = $insight['type'] ?? null;
        if (! $type) {
            return null;
        }
        $categoryToType = [
            'ai_suggestions' => 'suggestions',
            'ai_tags' => 'ai_tags',
            'ai_categories' => 'ai_categories',
            'metadata' => 'metadata',
            'activity' => 'activity',
            'rights' => 'rights',
        ];
        foreach ($signals as $s) {
            $cat = $s['context']['category'] ?? null;
            if ($cat && ($categoryToType[$cat] ?? $cat) === $type) {
                return $s['href'] ?? $this->mapInsightTypeToHref($type);
            }
        }

        return $this->mapInsightTypeToHref($type);
    }

    /**
     * Build normalized payload for the prompt.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    protected function buildInsightPayload(array $metrics): array
    {
        return [
            'total_assets' => (int) ($metrics['total_assets'] ?? 0),
            'uploads_last_7_days' => (int) ($metrics['uploads_last_7_days'] ?? 0),
            'uploads_last_30_days' => (int) ($metrics['uploads_last_30_days'] ?? 0),
            'shares_last_7_days' => (int) ($metrics['shares_last_7_days'] ?? 0),
            'shares_trend' => $metrics['shares_trend'] ?? null,
            'metadata_completeness' => (float) ($metrics['metadata_completeness'] ?? 1),
            'ai_suggestions_pending' => (int) ($metrics['ai_suggestions_pending'] ?? 0),
            'ai_tags_pending' => (int) ($metrics['ai_tags_pending'] ?? 0),
            'ai_categories_pending' => (int) ($metrics['ai_categories_pending'] ?? 0),
            'ai_completion_rate' => (float) ($metrics['ai_completion_rate'] ?? 1),
        ];
    }

    /**
     * Build the prompt for the LLM.
     *
     * @param  array<string, mixed>  $data
     * @param  array  $signals  Full signal objects
     */
    protected function buildPrompt(array $data, array $signals = []): string
    {
        $metricsJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $signalsForPrompt = array_map(fn ($s) => [
            'type' => $this->signalCategoryToType($s),
            'label' => $s['label'] ?? '',
            'priority' => $s['priority'] ?? 'medium',
        ], $signals);
        $signalsJson = json_encode($signalsForPrompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are an analytics assistant for a digital asset management platform.

You are given:

1. Signals (system-detected issues that require attention)
2. Metrics (numerical performance data)

Your job:
- Generate 1–2 insights that EXPLAIN why the signals matter
- Provide actionable reasoning
- DO NOT repeat or restate signals
- DO NOT list counts already shown
- Focus on impact and next step

Rules:
- Max 1 sentence per insight
- Be specific and actionable
- No fluff
- No generic statements
- Never refer to the viewer as "the user" or "users" in third person—the person reading this IS the user. Use "you" or "your team" instead, or phrase without mentioning users at all (e.g. "consider re-engaging your team" not "re-engage users")

Signals:
{$signalsJson}

Metrics:
{$metricsJson}

Return JSON only (no markdown, no code block):
[
  { "text": "...", "priority": "high|medium|low", "type": "suggestions|ai_tags|ai_categories|metadata|activity|sharing|rights" }
]

type must be one of: suggestions, ai_tags, ai_categories, metadata, activity, sharing, rights. We map types to routes server-side.
PROMPT;
    }

    protected function signalCategoryToType(array $signal): string
    {
        $cat = $signal['context']['category'] ?? null;

        return match ($cat) {
            'ai_suggestions' => 'suggestions',
            'ai_tags', 'ai_categories', 'metadata', 'activity', 'rights' => $cat,
            default => 'activity',
        };
    }

    /**
     * Parse and validate LLM response. Map type → href server-side (prefer signal href when matching).
     *
     * @param  array  $signals  Full signal objects for href resolution
     * @return array<array{text: string, priority: string, type?: string, href?: string}>
     */
    protected function parseResponse(string $raw, array $signals = []): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $raw, $m)) {
            $raw = trim($m[1]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $insights = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['text'])) {
                continue;
            }
            $priority = $item['priority'] ?? 'medium';
            if (! in_array($priority, ['high', 'medium', 'low'], true)) {
                $priority = 'medium';
            }
            $type = $item['type'] ?? null;
            $insight = [
                'text' => (string) $item['text'],
                'priority' => $priority,
            ];
            if ($type && in_array($type, self::VALID_TYPES, true)) {
                $insight['type'] = $type;
                $insight['href'] = $this->resolveInsightHref($insight, $signals);
            }
            $insights[] = $insight;
        }

        return $insights;
    }

    /**
     * Post-process: filter duplicates, boost priority from signals, limit to 2.
     */
    protected function postProcessInsights(array $insights, array $signals): array
    {
        $hasHighPrioritySignal = ! empty(array_filter($signals, fn ($s) => ($s['priority'] ?? '') === 'high'));

        $filtered = [];
        foreach ($insights as $insight) {
            $text = $insight['text'] ?? '';
            if (preg_match('/\d+\s*(AI\s+)?suggestions?\s+(and\s+tagging\s+)?to\s+review/i', $text)) {
                continue;
            }
            if (preg_match('/\d+\s*assets?\s+missing\s+metadata/i', $text)) {
                continue;
            }
            if (preg_match('/you\s+have\s+suggestions?\s+to\s+review/i', $text)) {
                continue;
            }
            $filtered[] = $insight;
        }

        foreach ($filtered as &$insight) {
            $t = $insight['type'] ?? '';
            if (in_array($t, ['suggestions', 'ai_tags', 'ai_categories'], true) && $hasHighPrioritySignal) {
                $insight['priority'] = 'high';
            }
        }

        return array_slice($filtered, 0, 2);
    }

    /**
     * Reorder insights by confidence (boost based on metrics). Keep top 2.
     *
     * @param  array<array{text: string, priority: string, type?: string, href?: string}>  $insights
     * @param  array<string, mixed>  $metrics
     * @return array<array{text: string, priority: string, href?: string}>
     */
    protected function applyConfidenceFiltering(array $insights, array $metrics): array
    {
        $boosts = [];
        if (($metrics['ai_suggestions_pending'] ?? 0) > 0) {
            $boosts['suggestions'] = 10;
            $boosts['ai_tags'] = 10;
            $boosts['ai_categories'] = 10;
        }
        if (($metrics['total_assets'] ?? 0) > 0 && ($metrics['uploads_last_7_days'] ?? 0) === 0) {
            $boosts['activity'] = 8;
        }
        if (($metrics['metadata_completeness'] ?? 1) < 0.5) {
            $boosts['metadata'] = 6;
        }
        if (($metrics['shares_last_7_days'] ?? 0) > 10) {
            $boosts['sharing'] = 4;
        }

        usort($insights, function ($a, $b) use ($boosts) {
            $typeA = $a['type'] ?? null;
            $typeB = $b['type'] ?? null;
            $scoreA = ($boosts[$typeA] ?? 0) + (['high' => 3, 'medium' => 2, 'low' => 1][$a['priority']] ?? 0);
            $scoreB = ($boosts[$typeB] ?? 0) + (['high' => 3, 'medium' => 2, 'low' => 1][$b['priority']] ?? 0);

            return $scoreB <=> $scoreA;
        });

        $top = array_slice($insights, 0, 2);

        return array_values($top);
    }

    /**
     * Fallback to rule-based insights (plain strings).
     *
     * @param  array<string, mixed>  $metrics
     * @return array<array{text: string, priority: string}>
     */
    protected function fallbackToRuleBased(Brand $brand, array $metrics): array
    {
        $strings = $this->brandInsightAI->generateInsights($metrics);
        $result = [];
        foreach ($strings as $text) {
            $result[] = [
                'text' => $text,
                'priority' => 'medium',
            ];
        }

        return $result;
    }

    /**
     * Bust cache for a brand (call on asset upload, metadata update, AI suggestion resolved, share created).
     */
    public function bustCache(Brand $brand): void
    {
        // Invalidates all per-user insight caches (BrandInsightEngine + LLM) without enumerating users.
        Cache::put('brand:'.$brand->id.':insights-bust', time(), now()->addYears(10));

        Cache::forget(self::CACHE_KEY_PREFIX.$brand->id.self::CACHE_KEY_SUFFIX);
        // Heuristic signals (What Needs Attention) — must clear when uploads/metrics change
        Cache::forget('brand:'.$brand->id.':insights');
        // Legacy rule-based cache (defensive; same metrics as LLM fallback)
        Cache::forget('brand:'.$brand->id.':ai-insights');
    }
}

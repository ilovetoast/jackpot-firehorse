<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\Brand;
use App\Models\TenantAgency;
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

    public const VALID_TYPES = [
        'suggestions', 'metadata', 'activity', 'sharing', 'rights', 'ai_tags', 'ai_categories',
        'guidelines', // Brand Guidelines / DNA builder
        'agency_clients', // Agency: client companies & agency dashboard
    ];

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
            $ta = (int) ($metrics['total_assets'] ?? 0);
            $u7 = (int) ($metrics['uploads_last_7_days'] ?? 0);
            $metricsContext = array_merge($metrics, [
                'is_agency' => (bool) $brand->tenant?->is_agency,
                'early_stage_library' => $ta === 0 || ($ta < 25 && $u7 === 0),
            ]);
            try {
                $insights = $this->generateInsights($brand, $metrics, $signals);
                if (! empty($insights)) {
                    $insights = $this->postProcessInsights($insights, $signals);
                    $insights = $this->enrichInsightHrefs($insights, $brand);

                    return $this->applyConfidenceFiltering($insights, $metricsContext);
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
        $payload = $this->buildInsightPayload($brand, $metrics);
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
            'agency_clients' => '/app/agency/dashboard',
            default => '/app/insights/overview',
        };
    }

    /**
     * Deterministic hrefs for insight types that need brand id or fixed agency routes.
     *
     * @param  array<array{text: string, priority: string, type?: string, href?: string}>  $insights
     * @return array<array{text: string, priority: string, type?: string, href?: string}>
     */
    protected function enrichInsightHrefs(array $insights, Brand $brand): array
    {
        foreach ($insights as &$insight) {
            $type = $insight['type'] ?? null;
            if ($type === 'guidelines') {
                $insight['href'] = '/app/brands/'.$brand->id.'/brand-guidelines/builder';
            } elseif ($type === 'agency_clients') {
                $insight['href'] = '/app/agency/dashboard';
            } elseif (empty($insight['href']) && $type && in_array($type, self::VALID_TYPES, true)) {
                $insight['href'] = $this->mapInsightTypeToHref($type);
            }
        }

        return $insights;
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
            'guidelines' => 'guidelines',
            'agency_clients' => 'agency_clients',
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
    protected function buildInsightPayload(Brand $brand, array $metrics): array
    {
        $tenant = $brand->tenant;
        $linkedClientCompanies = 0;
        if ($tenant && $tenant->is_agency) {
            $linkedClientCompanies = TenantAgency::where('agency_tenant_id', $tenant->id)->count();
        }

        $totalAssets = (int) ($metrics['total_assets'] ?? 0);
        $uploads7 = (int) ($metrics['uploads_last_7_days'] ?? 0);

        return [
            'total_assets' => $totalAssets,
            'uploads_last_7_days' => $uploads7,
            'uploads_last_30_days' => (int) ($metrics['uploads_last_30_days'] ?? 0),
            'shares_last_7_days' => (int) ($metrics['shares_last_7_days'] ?? 0),
            'shares_trend' => $metrics['shares_trend'] ?? null,
            'metadata_completeness' => (float) ($metrics['metadata_completeness'] ?? 1),
            'ai_suggestions_pending' => (int) ($metrics['ai_suggestions_pending'] ?? 0),
            'ai_tags_pending' => (int) ($metrics['ai_tags_pending'] ?? 0),
            'ai_categories_pending' => (int) ($metrics['ai_categories_pending'] ?? 0),
            'ai_completion_rate' => (float) ($metrics['ai_completion_rate'] ?? 1),
            'is_agency' => (bool) ($tenant?->is_agency),
            'linked_client_company_count' => $linkedClientCompanies,
            // Small or quiet library — prefer encouraging “first steps” copy over “absence / disengagement”
            'early_stage_library' => $totalAssets === 0 || ($totalAssets < 25 && $uploads7 === 0),
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

Tone (critical):
- If "early_stage_library" is true OR total_assets is 0: do NOT frame anything as absence, lack, disengagement, risk of falling behind, or "no recent uploads" as a problem. Instead PROMOTE a positive next step: define or refine Brand Guidelines (Brand DNA builder), and/or invite uploading a first small batch of hero assets so the library tells the brand story. Use type "guidelines" or "activity".
- If "is_agency" is true: reserve at least ONE insight for the agency workflow when helpful — e.g. opening the Agency dashboard to review, onboard, or manage linked client companies (especially if linked_client_company_count is 0 or still growing). Use type "agency_clients". If linked_client_company_count is already substantial, you may focus the second insight on brand work instead.
- If metadata_completeness and ai_completion_rate are both high (e.g. ≥ 0.9): celebrate the strong foundation and suggest the NEXT creative step (new campaign assets, seasonal refresh, guidelines polish) — do NOT scold the team to "maintain" or imply disengagement.

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
  { "text": "...", "priority": "high|medium|low", "type": "suggestions|ai_tags|ai_categories|metadata|activity|sharing|rights|guidelines|agency_clients" }
]

type must be one of: suggestions, ai_tags, ai_categories, metadata, activity, sharing, rights, guidelines, agency_clients. We map types to routes server-side.
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
            // Drop fear-based / absence framing the product no longer wants in overview insights
            if (preg_match('/\b(absence|disengagement|lack of recent|no recent uploads?|hinder(s)? content growth)\b/i', $text)) {
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
        $early = (bool) ($metrics['early_stage_library'] ?? false);
        if (($metrics['ai_suggestions_pending'] ?? 0) > 0) {
            $boosts['suggestions'] = 10;
            $boosts['ai_tags'] = 10;
            $boosts['ai_categories'] = 10;
        }
        // Prefer encouraging "guidelines / first assets" over generic activity nudges on small or quiet libraries
        if ($early) {
            $boosts['guidelines'] = 11;
        }
        if (($metrics['is_agency'] ?? false)) {
            $boosts['agency_clients'] = 10;
        }
        if (($metrics['total_assets'] ?? 0) > 0 && ($metrics['uploads_last_7_days'] ?? 0) === 0 && ! $early) {
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
        $items = $this->brandInsightAI->generateInsights($metrics, $brand);
        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $result[] = [
                    'text' => $item,
                    'priority' => 'medium',
                ];

                continue;
            }
            if (! is_array($item) || empty($item['text'])) {
                continue;
            }
            $row = [
                'text' => (string) $item['text'],
                'priority' => $item['priority'] ?? 'medium',
            ];
            if (! empty($item['type']) && in_array($item['type'], self::VALID_TYPES, true)) {
                $row['type'] = $item['type'];
            }
            $result[] = $row;
        }

        return $this->enrichInsightHrefs($result, $brand);
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

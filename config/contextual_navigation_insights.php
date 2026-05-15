<?php

/*
|--------------------------------------------------------------------------
| Phase 6 — Contextual Navigation Intelligence
|--------------------------------------------------------------------------
|
| Tunes RunContextualNavigationInsightsJob + ContextualNavigationScoringService
| + ContextualNavigationRecommender. Everything here is intentionally
| conservative: this system NEVER mutates quick-filter configuration on its
| own; it only writes reviewable recommendation rows.
|
| Tenant gates (NOT controlled here, listed for orientation):
|   - tenant.settings['ai_enabled']        — master AI kill switch
|   - tenants.ai_insights_enabled          — insights track gate
|   - AiUsageService monthly credit pool   — feature key 'contextual_navigation'
|
*/

return [
    /*
    | Master switch for the whole Phase 6 surface. Flip to false to stop
    | scheduled runs and hide the Insights tab without removing data.
    */
    'enabled' => env('CONTEXTUAL_NAV_INSIGHTS_ENABLED', true),

    /*
    | Scheduled-run cadence for the daily/weekly dispatcher in
    | routes/console.php. When false, only manual runs from the Insights
    | "Analyze contextual navigation" button or programmatic dispatch fire.
    */
    'scheduled_enabled' => env('CONTEXTUAL_NAV_INSIGHTS_SCHEDULED', true),

    /*
    | Eligibility thresholds. The job exits cleanly (no recommendations,
    | no AI debit) when these are not met. Goal: avoid noise on tenants
    | who do not have enough metadata to analyse meaningfully.
    */
    'min_assets_per_tenant' => 50,
    'min_assets_per_folder' => 10,
    'min_distinct_values_per_field' => 2,

    /*
    | Cooldown — the job refuses to write a fresh batch of recommendations
    | for a tenant inside this window. Prevents schedulers + manual runs
    | from racing into a thrash. Manual runs can override (param `force=1`)
    | when a permitted admin requests it.
    */
    'run_cooldown_hours' => 24 * 7, // weekly

    /*
    | TTL for individual recommendation rows. After this many days, the
    | stale resolver downgrades them to status='stale' so the review queue
    | does not accumulate ancient dust. Approved/rejected rows are NOT
    | affected — only pending/deferred.
    */
    'recommendation_ttl_days' => 30,

    /*
    | Cap per run. Prevents pathological tenants (10k folders × 50 fields)
    | from producing review queues nobody will ever read. The recommender
    | sorts by score DESC and trims at this cap.
    */
    'max_recommendations_per_run' => 200,

    /*
    | Score thresholds for emitting each recommendation type. Tuned
    | conservatively so we err on the side of NOT recommending. Scores
    | are normalized 0.0–1.0 by ContextualNavigationScoringService.
    */
    'score_thresholds' => [
        'suggest_quick_filter' => 0.70,
        'suggest_pin_quick_filter' => 0.80,
        'suggest_unpin_quick_filter' => 0.30,
        'suggest_disable_quick_filter' => 0.20,
        'suggest_move_to_overflow' => 0.40,
        // warnings have lower bars — informational, not actions
        'warn_high_cardinality' => 0.50,
        'warn_low_navigation_value' => 0.30,
        'warn_metadata_fragmentation' => 0.40,
        'warn_low_coverage' => 0.40,
    ],

    /*
    | Whether to invoke the contextual_navigation_intelligence agent for
    | optional rationale enrichment on borderline cases. When false, every
    | recommendation has a statistical reason summary and credits are NEVER
    | debited regardless of provider availability.
    */
    'use_ai_reasoning' => env('CONTEXTUAL_NAV_USE_AI', false),

    /*
    | Borderline band — only recommendations whose score is within this
    | distance of their threshold get AI rationale enrichment. Keeps AI
    | cost predictable.
    */
    'ai_reasoning_borderline_band' => 0.10,

    /*
    | Hard ceiling on AI-enriched recommendations per run. Even if the
    | borderline band is large, we never invoke the agent more than this
    | many times per run. ALSO clamped by AiUsageService::checkUsage which
    | will throw if the tenant's monthly pool is exhausted.
    */
    'max_ai_calls_per_run' => 5,

    /*
    | Queue + agent identifiers. Aligned with config/ai.php agents key and
    | config/ai_credits.php weights so usage attribution is consistent.
    */
    'queue' => env('CONTEXTUAL_NAV_QUEUE', 'default'),
    'agent_key' => 'contextual_navigation_intelligence',
    'usage_feature' => 'contextual_navigation',
];

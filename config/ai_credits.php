<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Credit Weights
    |--------------------------------------------------------------------------
    |
    | Each AI operation consumes credits from a shared monthly pool.
    | Weights are the number of credits consumed per operation call.
    |
    | AiUsageService still writes per-feature rows to ai_usage (for analytics)
    | but enforces a single weighted credit budget.
    |
    | v1 weights — instrument actual blended cost per operation for 30-60 days,
    | then rebalance. Do not treat these as sacred.
    |
    */

    'weights' => [
        'tagging' => 1,
        'suggestions' => 1,
        /** OpenAI vision (gpt-4o-mini) — photography focal point; same weight as a single tagging vision call */
        'photography_focal_point' => 1,
        'insights' => 1,
        'brand_research' => 25,
        'pdf_extraction' => 5,
        // Drawer / thumbnail-mode “presentation” previews: OpenAI/Gemini/FLUX image edit (see GeneratePresentationPreviewJob).
        'presentation_preview' => 10,
        'generative_editor_edits' => 15,
        'generative_editor_images' => 20,
        'generative_editor_layout' => 8,
        /** Studio composition animation (Kling i2v, etc.): per-call units = credits from {@see AiUsageService::getStudioAnimationCreditCost()}; weight is 1. */
        'studio_animation' => 1,
        /** Studio “Extract layers” — charged once per extraction request (not per candidate). Local floodfill is free unless STUDIO_LAYER_EXTRACTION_BILL_FLOODFILL=true. */
        'studio_layer_extraction' => 8,
        /** Filled background layer (inpainting) — once per confirm when “Create filled background” is used. */
        'studio_layer_background_fill' => 6,
        /*
         * Intentionally no weight for `thumbnail_enhancement` (Studio View enhanced mode):
         * GenerateEnhancedPreviewJob composites locally via TemplateRenderer + GD/ImageMagick — no LLM/provider API.
         * Operational counts still appear in Insights (AIAgentRun THUMBNAIL_ENHANCEMENT) for latency/quality only.
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Video AI Credit Pricing (per-minute tiered)
    |--------------------------------------------------------------------------
    |
    | Formula: credits = base_credits + max(0, ceil(duration_minutes) - 1) * per_additional_minute
    |
    | The first minute (or fraction) always costs base_credits.
    | Each additional ceil'd minute costs per_additional_minute.
    |
    | Examples:
    |   30s clip  (0.5 min)  -> ceil(0.5)=1  -> 5 + (1-1)*3 = 5 credits
    |   61s clip  (1.02 min) -> ceil(1.02)=2  -> 5 + (2-1)*3 = 8 credits
    |   3m video  (3.0 min)  -> ceil(3.0)=3   -> 5 + (3-1)*3 = 11 credits
    |   10m video (10.0 min) -> ceil(10.0)=10  -> 5 + (10-1)*3 = 32 credits
    |
    */

    'video_insights' => [
        'base_credits' => 5,
        'per_additional_minute' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio AI Credit Pricing (per-minute tiered)
    |--------------------------------------------------------------------------
    |
    | Mirrors `video_insights` for transcription / mood / summary jobs
    | (Whisper today; other providers later). Whisper bills ~$0.006/min,
    | so audio is intentionally cheaper than the vision pipeline above.
    |
    | Formula: credits = base_credits + max(0, ceil(duration_minutes) - 1) * per_additional_minute
    |
    | Examples:
    |   30s voice memo (0.5 min) -> ceil(0.5)=1  -> 1 + (1-1)*1 = 1 credit
    |   3m meeting clip          -> ceil(3.0)=3  -> 1 + (3-1)*1 = 3 credits
    |   60m podcast              -> ceil(60)=60  -> 1 + (60-1)*1 = 60 credits
    |
    | The Whisper provider keeps a separate per-asset *dollar* budget cap
    | (config('assets.audio_ai.whisper.budget_cents_per_asset')) as a
    | defense-in-depth backstop against runaway calls; the credit pool
    | is the primary tenant-facing enforcement.
    |
    */

    'audio_insights' => [
        'base_credits' => 1,
        'per_additional_minute' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Credit Add-on Packs
    |--------------------------------------------------------------------------
    |
    | Recurring monthly Stripe subscription items that add credits on top of
    | the plan base. Only one credit add-on active at a time per tenant.
    | available_plans controls which plan tiers can purchase each pack.
    | Stripe Price IDs: config/billing_stripe.php (addons.ai_credits_*).
    |
    */

    'addons' => [
        [
            'id' => 'credits_500',
            'credits' => 500,
            'stripe_price_id' => config('billing_stripe.stripe_prices.addons.ai_credits_500'),
            'monthly_price' => 29,
            'available_plans' => ['starter', 'pro', 'business'],
        ],
        [
            'id' => 'credits_2000',
            'credits' => 2000,
            'stripe_price_id' => config('billing_stripe.stripe_prices.addons.ai_credits_2000'),
            'monthly_price' => 89,
            'available_plans' => ['pro', 'business'],
        ],
        [
            'id' => 'credits_10000',
            'credits' => 10000,
            'stripe_price_id' => config('billing_stripe.stripe_prices.addons.ai_credits_10000'),
            'monthly_price' => 349,
            'available_plans' => ['business'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warning Thresholds (percentage of credits used)
    |--------------------------------------------------------------------------
    |
    | 80 — in-app warning banner
    | 90 — stronger warning + email to tenant admins
    | 100 — premium AI actions paused, one-click top-up CTA
    |
    */

    'warning_thresholds' => [80, 90, 100],
];

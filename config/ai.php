<?php

/**
 * AI Configuration
 *
 * Centralized configuration for AI models, agents, and settings.
 * This file defines the AI infrastructure layer that enables provider-agnostic
 * AI operations with comprehensive tracking and cost attribution.
 *
 * Why centralized?
 * - Single source of truth for AI capabilities
 * - Provider abstraction (switch providers without code changes)
 * - Cost tracking and attribution
 * - Permission and safety enforcement
 * - Future extensibility (tenant-level AI, database overrides)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The default AI provider to use when no provider is specified.
    | Must match a provider implementation in app/Services/AI/Providers/
    |
    */
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | In-app help (Phase 2 — grounded AI answers)
    |--------------------------------------------------------------------------
    |
    | Uses AIService + registered agent `in_app_help_assistant` (gpt-4o-mini).
    | Answers must use only retrieved help_actions JSON; see HelpAiAskService.
    | Agent + model are defined below (`agents.in_app_help_assistant`, `models.gpt-4o-mini`).
    | Requires a working text provider (e.g. OpenAI API key when default_provider is openai).
    |
    */
    'help_ask' => [
        'enabled' => (bool) env('AI_HELP_ASK_ENABLED', true),
        /** Minimum best retrieval score (HelpActionService::scoreAction) to call the model. */
        'strong_match_min_score' => (int) env('AI_HELP_ASK_STRONG_MIN_SCORE', 12),
        'max_actions_for_prompt' => (int) env('AI_HELP_ASK_MAX_ACTIONS', 3),
        'agent_id' => 'in_app_help_assistant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Photography focal point (vision, gpt-4o-mini)
    |--------------------------------------------------------------------------
    |
    | Kill-switch and optional coupling to preferred (second-pass) thumbnails. Standard
    | grid thumbnails are enough for vision; set require_preferred_thumbnails when ops
    | want the admin UI to reflect preferred-thumbnail availability only.
    |
    */
    'photography_focal_point' => [
        'enabled' => (bool) env('AI_PHOTOGRAPHY_FOCAL_POINT_ENABLED', true),
        'require_preferred_thumbnails' => (bool) env('FOCAL_POINT_AI_REQUIRE_PREFERRED_THUMBS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset metadata & AI tag inference (Vision)
    |--------------------------------------------------------------------------
    |
    | Used by AiMetadataGenerationService for structured field candidates and
    | asset_tag_candidates. Lower values return more tags; higher values are stricter.
    | Tags are normalized (lowercase, etc.) after filtering.
    |
    */
    'metadata_tagging' => [
        'min_confidence' => (float) env('AI_METADATA_TAGGING_MIN_CONFIDENCE', 0.90),
        /**
         * Custom category only (settings.ai_use_library_references): rank peer assets in-DB
         * (quality_rating, download count) and add tag text + optional reference thumbnails. No ML — local SQL only.
         */
        'library_reference_context' => [
            'peer_pool' => 48,
            'max_text_peer_assets' => 8,
            'max_tags_per_peer' => 8,
            'max_reference_images' => 2,
        ],
        /**
         * Normalized tags (whole string, after {@see AiMetadataGenerationService::normalizeTag}) that Vision
         * often misassigns on packaging, sell sheets, and product hero art — dropped before candidates are stored.
         * Comma-separated env override replaces the default list (use empty string to allow all).
         */
        'vision_tag_blocklist' => array_values(array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) env(
                'AI_METADATA_VISION_TAG_BLOCKLIST',
                'model,fashion'
            )))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video insights (multi-frame vision + optional Whisper transcript)
    |--------------------------------------------------------------------------
    |
    | One composited grid image is sent to the default vision model via
    | AIProviderInterface::analyzeImage (same stack as asset metadata vision).
    |
    */
    'video_insights' => [
        'model' => env('AI_VIDEO_INSIGHTS_MODEL', 'gpt-4o-mini'),
        'max_tokens' => (int) env('AI_VIDEO_INSIGHTS_MAX_TOKENS', 1400),
        /** Whisper model for /v1/audio/transcriptions */
        'whisper_model' => env('AI_VIDEO_INSIGHTS_WHISPER_MODEL', 'whisper-1'),
        /**
         * USD per second of audio (approximate; aligns with OpenAI Whisper list pricing).
         * Used when the API does not return usage; tune via env if pricing changes.
         */
        'whisper_cost_per_second_usd' => (float) env('AI_VIDEO_INSIGHTS_WHISPER_COST_PER_SEC', 0.0001),
        'prompt' => <<<'PROMPT'
You are analyzing a video for a digital asset management system.

You are given:
- A single composite image containing sequential frames sampled from the video (read left-to-right, top-to-bottom).
- An optional transcript of spoken audio (may be empty).

Return JSON only with this exact shape:
{
  "tags": ["tag1", "tag2"],
  "summary": "2-4 sentences for marketers",
  "suggested_category": "short label or empty string if unclear",
  "metadata": {
    "scene": "",
    "activity": "",
    "setting": ""
  },
  "moments": [
    { "frame_index": 1, "label": "short visual description for that frame" }
  ]
}

Rules:
- tags: lowercase short phrases, marketing-relevant and specific; no generic filler.
- summary: focus on what a brand team can use (subject, mood, use-case).
- suggested_category: only if clearly implied; else "".
- metadata fields: concise factual phrases from visual + transcript context.
- moments: 0–8 entries; frame_index must refer to FRAME_TIMELINE below (1 = first tile). Labels describe what is visible at that moment (for "jump to clip" UX).

JSON only. No markdown.
PROMPT,
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Provider
    |--------------------------------------------------------------------------
    |
    | Credentials for OpenAI API. Use config() not env() in application code
    | so values work correctly when config is cached (e.g. staging/production).
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        /*
         * Transient network/TLS failures (e.g. cURL 56, connection reset by peer) are retried
         * before surfacing. Tune for staging/production NAT or flaky paths to OpenAI.
         */
        'chat_completions_max_retries' => max(1, (int) env('OPENAI_CHAT_COMPLETIONS_MAX_RETRIES', 5)),
        'chat_completions_retry_base_ms' => max(50, (int) env('OPENAI_CHAT_COMPLETIONS_RETRY_BASE_MS', 400)),
        'chat_completions_retry_max_sleep_ms' => max(200, (int) env('OPENAI_CHAT_COMPLETIONS_RETRY_MAX_SLEEP_MS', 8000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic Provider
    |--------------------------------------------------------------------------
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Gemini (Generative Language API)
    |--------------------------------------------------------------------------
    |
    | Used for native image generation (Nano Banana: gemini-2.5-flash-image,
    | gemini-3.1-flash-image-preview, gemini-3-pro-image-preview). Set GEMINI_API_KEY
    | from Google AI Studio: https://aistudio.google.com/apikey
    |
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Black Forest Labs FLUX (api.bfl.ai)
    |--------------------------------------------------------------------------
    |
    | Used for editor image modification (FLUX.2). Get a key from https://bfl.ai
    |
    */
    'flux' => [
        'api_key' => env('FLUX_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Models Registry
    |--------------------------------------------------------------------------
    |
    | Registry of available AI models across all providers.
    | Models are defined here first, with optional database overrides later.
    |
    | Each model must include:
    | - provider: Provider identifier (openai, future providers)
    | - model_name: Actual model name used by provider API
    | - capabilities: Array of capabilities (text, reasoning, image, multimodal)
    | - recommended_use: Array of recommended use cases (tagging, audit, reporting, creative)
    | - default_cost_per_token: Array with 'input' and 'output' costs in USD per token
    | - active: Whether the model is currently active/available
    |
    | Cost attribution:
    | - Costs are calculated per agent run
    | - System context: No tenant attribution (system-level cost)
    | - Tenant context: Cost attributed to tenant_id
    | - User context: Cost attributed to both tenant_id and user_id
    |
    */
    'models' => [
        'gpt-4-turbo' => [
            'provider' => 'openai',
            // Legacy key retained for compatibility; preview model was retired.
            'model_name' => 'gpt-4o',
            'capabilities' => ['text', 'reasoning'],
            'recommended_use' => ['tagging', 'audit', 'reporting', 'creative'],
            'default_cost_per_token' => [
                'input' => 0.00001,  // $0.01 per 1K tokens (gpt-4-turbo input)
                'output' => 0.00003, // $0.03 per 1K tokens (gpt-4-turbo output)
            ],
            'active' => true,
        ],
        'gpt-4' => [
            'provider' => 'openai',
            'model_name' => 'gpt-4',
            'capabilities' => ['text', 'reasoning'],
            'recommended_use' => ['tagging', 'audit', 'reporting', 'creative'],
            'default_cost_per_token' => [
                'input' => 0.00003,  // $0.03 per 1K tokens (gpt-4 input)
                'output' => 0.00006, // $0.06 per 1K tokens (gpt-4 output)
            ],
            'active' => true,
        ],
        'gpt-3.5-turbo' => [
            'provider' => 'openai',
            'model_name' => 'gpt-3.5-turbo',
            'capabilities' => ['text'],
            'recommended_use' => ['tagging', 'reporting'],
            'default_cost_per_token' => [
                'input' => 0.0000005,  // $0.0005 per 1K tokens (gpt-3.5-turbo input)
                'output' => 0.0000015, // $0.0015 per 1K tokens (gpt-3.5-turbo output)
            ],
            'active' => true,
        ],
        'gpt-4o' => [
            'provider' => 'openai',
            'model_name' => 'gpt-4o',
            'capabilities' => ['text', 'reasoning', 'image', 'multimodal'],
            'recommended_use' => ['tagging', 'metadata_generation'],
            'default_cost_per_token' => [
                'input' => 0.00001,  // $0.01 per 1K tokens (gpt-4o input)
                'output' => 0.00003, // $0.03 per 1K tokens (gpt-4o output)
            ],
            'active' => true,
        ],
        'gpt-4o-mini' => [
            'provider' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => ['text', 'reasoning', 'image', 'multimodal'],
            'recommended_use' => ['tagging', 'metadata_generation'],
            'default_cost_per_token' => [
                'input' => 0.00000015,  // $0.15 per 1M tokens (much cheaper)
                'output' => 0.0000006,  // $0.60 per 1M tokens
            ],
            'active' => true,
            'notes' => 'Cost-effective alternative for high-volume operations',
        ],
        'gpt-image-1' => [
            'provider' => 'openai',
            'model_name' => 'gpt-image-1',
            'capabilities' => ['image_generation'],
            'recommended_use' => ['creative', 'image_generation'],
            'display_name' => 'GPT Image 1',
            'default_cost_per_token' => [
                'input' => 0.00001,
                'output' => 0.00004,
            ],
            'active' => true,
            'notes' => 'OpenAI Images API — token usage when returned by API; otherwise estimated',
        ],
        'claude-sonnet-4-20250514' => [
            'provider' => 'anthropic',
            'model_name' => 'claude-sonnet-4-20250514',
            'capabilities' => ['text', 'reasoning', 'image', 'multimodal', 'pdf'],
            'recommended_use' => ['brand_pdf_extraction'],
            'default_cost_per_token' => [
                'input' => 0.000003,   // $3 per 1M tokens
                'output' => 0.000015,  // $15 per 1M tokens
            ],
            'active' => true,
            'notes' => 'Native PDF support for single-pass brand guidelines extraction',
        ],
        /*
         * Gemini native image generation (Nano Banana). Costs are approximate token rates;
         * verify against https://ai.google.dev/pricing — image output is billed as tokens.
         */
        'gemini-3-pro-image-preview' => [
            'provider' => 'gemini',
            'model_name' => 'gemini-3-pro-image-preview',
            'capabilities' => ['text', 'image', 'multimodal', 'image_generation'],
            'recommended_use' => ['creative', 'image_generation'],
            'display_name' => 'Gemini 3 Pro Image (preview)',
            'default_cost_per_token' => [
                'input' => 0.000002,   // placeholder; align with current Gemini pricing
                'output' => 0.000012,
            ],
            'active' => true,
            'notes' => 'Nano Banana Pro — highest fidelity / complex prompts (gemini-3-pro-image-preview)',
        ],
        'gemini-3.1-flash-image-preview' => [
            'provider' => 'gemini',
            'model_name' => 'gemini-3.1-flash-image-preview',
            'capabilities' => ['text', 'image', 'multimodal', 'image_generation'],
            'recommended_use' => ['creative', 'image_generation'],
            'display_name' => 'Gemini 3.1 Flash Image (preview)',
            'default_cost_per_token' => [
                'input' => 0.00000035,
                'output' => 0.0000014,
            ],
            'active' => true,
            'notes' => 'Nano Banana 2 — fast, high-volume image generation',
        ],
        'gemini-2.5-flash-image' => [
            'provider' => 'gemini',
            'model_name' => 'gemini-2.5-flash-image',
            'capabilities' => ['text', 'image', 'multimodal', 'image_generation'],
            'recommended_use' => ['creative', 'image_generation'],
            'display_name' => 'Gemini 2.5 Flash Image',
            'default_cost_per_token' => [
                'input' => 0.0000003,
                'output' => 0.0000012,
            ],
            'active' => true,
            'notes' => 'Nano Banana — speed/efficiency (gemini-2.5-flash-image)',
        ],
        'flux-2-flex' => [
            'provider' => 'flux',
            'model_name' => 'flux-2-flex',
            'capabilities' => ['image_generation'],
            'recommended_use' => ['creative', 'image_generation'],
            'display_name' => 'FLUX.2 [flex] (BFL)',
            'default_cost_per_token' => [
                'input' => 0.00001,
                'output' => 0.00004,
            ],
            'active' => true,
            'notes' => 'BFL async image edit — FLUX.2 flex (editor modify only)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generative asset editor (images)
    |--------------------------------------------------------------------------
    |
    | Registry keys from ai.models that may be selected when using an optional
    | advanced model override. Empty allowed_model_keys = allow any active model
    | with image_generation capability (still merged with AIModelOverride).
    |
    | edit_allowed_model_keys: POST /app/api/edit-image (Modify image) only.
    | Empty = fall back to allowed_model_keys. Use a subset to disable Gemini 3.x for edits
    | while keeping them for Generate.
    |
    */
    'generative_editor' => [
        'allowed_model_keys' => [
            'gpt-image-1',
            'gemini-3-pro-image-preview',
            'gemini-3.1-flash-image-preview',
            'gemini-2.5-flash-image',
        ],
        'edit_allowed_model_keys' => [
            'gpt-image-1',
            'gemini-2.5-flash-image',
            'gemini-3-pro-image-preview',
            // 'flux-2-flex', // temporarily disabled for edit
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Agents Registry
    |--------------------------------------------------------------------------
    |
    | Definitions of AI agents as logical actors that perform tasks.
    | Agents are defined in config first, with optional database overrides later.
    |
    | Each agent must include:
    | - name: Human-readable name
    | - description: What the agent does
    | - scope: 'system' (system-wide) or 'tenant' (tenant-scoped, future)
    | - default_model: Key from models registry
    | - allowed_actions: Array of actions agent can perform
    |   Options: read, write, create_ticket, upload_asset, generate_image
    | - permissions: Array of Spatie permission names checked at runtime
    |
    | Permissions enforcement:
    | - Permissions are checked by AIService before execution
    | - Uses existing Spatie permission system
    | - System-scoped agents require system permissions
    | - Tenant-scoped agents require tenant-specific permissions (future)
    |
    | All actions are auditable via agent runs stored in ai_agent_runs table.
    |
    | AI Agent Result Contract (Phase D-1):
    | All AI agent results MUST include:
    | - severity: AIAgentSeverity enum value (info, warning, system, data)
    | - confidence: float 0–1
    | - summary: string
    | - recommendation: string (optional)
    |
    */
    'agents' => [
        'ticket_analyzer' => [
            'name' => 'Ticket Analyzer',
            'description' => 'Analyzes support tickets and generates summaries',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read', 'create_ticket'],
            'permissions' => [
                'tickets.view_staff',
                'tickets.create_engineering',
            ],
        ],
        'audit_reporter' => [
            'name' => 'Audit Reporter',
            'description' => 'Generates audit reports from activity logs',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_audit_log',
            ],
        ],
        'performance_analyst' => [
            'name' => 'Performance Analyst',
            'description' => 'Analyzes system performance and generates insights',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-level permissions (may need new permission)
            ],
        ],
        'ticket_summarizer' => [
            'name' => 'Ticket Summarizer',
            'description' => 'Summarizes ticket conversations and extracts key facts',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'ticket_classifier' => [
            'name' => 'Ticket Classifier',
            'description' => 'Suggests category, severity, and component for tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'sla_risk_analyzer' => [
            'name' => 'SLA Risk Analyzer',
            'description' => 'Analyzes tickets for SLA breach risk',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_sla',
            ],
        ],
        'error_pattern_analyzer' => [
            'name' => 'Error Pattern Analyzer',
            'description' => 'Detects error patterns and suggests internal tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_engineering',
            ],
        ],
        'duplicate_detector' => [
            'name' => 'Duplicate Ticket Detector',
            'description' => 'Detects potential duplicate tickets',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                'tickets.view_staff',
            ],
        ],
        'system_reliability_agent' => [
            'name' => 'System Reliability Agent',
            'description' => 'Analyzes system health and generates reliability insights',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-level agent - no specific permissions required
            ],
        ],
        'metadata_generator' => [
            'name' => 'Metadata Generator',
            'description' => 'Generates AI metadata candidates for assets using vision analysis',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped agent - runs automatically during asset processing
                // No specific permissions required (system-triggered)
            ],
        ],
        'sentry_error_analyzer' => [
            'name' => 'Sentry Error Analyzer',
            'description' => 'Analyzes Sentry error stack traces for summary, root cause, and fix suggestion',
            'scope' => 'system',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-level agent - runs from PullSentryIssuesJob when pull enabled
            ],
        ],
        'approval_summarizer' => [
            'name' => 'Approval Summarizer',
            'description' => 'Generates neutral summaries of approval feedback history',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Phase AF-6: Tenant-scoped agent - runs automatically during approval actions
                // No specific permissions required (system-triggered, read-only)
            ],
        ],
        'download_zip_failure_analyzer' => [
            'name' => 'Download ZIP Failure Analyzer',
            'description' => 'Analyzes download ZIP build failures and recommends escalation',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // System-triggered on timeout or repeated failures
            ],
            'prompt' => <<<'PROMPT'
You MUST:
- Classify root cause of the ZIP build failure
- Assign severity using AIAgentSeverity: info (benign), warning (recoverable, retry recommended), system (infrastructure-level, escalation-worthy), data (asset-level: permissions, missing files)
- Output a JSON object with: severity, confidence (0–1), summary, recommendation (optional)

You MUST NOT:
- Decide enforcement (downstream systems decide)
- Trigger tickets directly (downstream systems decide)
PROMPT
        ],
        'upload_failure_analyzer' => [
            'name' => 'Upload Failure Analyzer',
            'description' => 'Analyzes upload failures and recommends escalation',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Phase U-1: System-triggered on repeated or critical failures
            ],
            'prompt' => <<<'PROMPT'
You MUST:
- Classify root cause of the upload failure
- Assign severity using AIAgentSeverity: info (benign), warning (recoverable), system (infrastructure-level, escalation-worthy), data (permissions, storage)
- Output a JSON object with: severity, confidence (0–1), summary, recommendation (optional)

You MUST NOT:
- Decide enforcement (downstream systems decide)
- Trigger tickets directly (downstream systems decide)
PROMPT
        ],
        'asset_derivative_failure_analyzer' => [
            'name' => 'Asset Derivative Failure Analyzer',
            'description' => 'Analyzes derivative generation failures (thumbnails, previews, posters)',
            'scope' => 'system',
            'default_model' => 'gpt-4-turbo',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Phase T-1: System-triggered on repeated or critical failures
            ],
            'prompt' => <<<'PROMPT'
You MUST:
- Classify root cause of the derivative generation failure
- Assign severity using AIAgentSeverity: info, warning, system (infra), data (corrupt file, codec)
- Output a JSON object with: severity, confidence (0–1), summary, recommendation (optional)

You MUST NOT:
- Decide enforcement (downstream systems decide)
- Trigger tickets directly (downstream systems decide)
PROMPT
        ],
        'brand_bootstrap_inference' => [
            'name' => 'Brand Bootstrap Inference',
            'description' => 'Infers structured Brand DNA from scraped website data',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped, system-triggered after scrape completes
            ],
        ],
        'brand_bootstrap_signal_extraction' => [
            'name' => 'Brand Bootstrap Signal Extraction',
            'description' => 'Extracts strategic brand signals from normalized website data',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped, system-triggered in pipeline
            ],
        ],
        'pdf_structure' => [
            'name' => 'PDF Document Structure',
            'description' => 'Classifies PDF text and extracts structure (document_type, summary) for guidelines/specs',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped, runs from StructPdfTextWithAiJob after extraction completes
            ],
        ],
        'brand_pdf_extractor' => [
            'name' => 'Brand PDF Extractor',
            'description' => 'Single-pass Claude extraction of brand DNA fields from guidelines PDFs',
            'scope' => 'tenant',
            'default_model' => 'claude-sonnet-4-20250514',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped, system-triggered during brand pipeline
            ],
        ],
        'brand_insights' => [
            'name' => 'Brand Insights',
            'description' => 'Generates 1–2 actionable insights from brand analytics metrics',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // Tenant-scoped, system-triggered on dashboard load
            ],
        ],
        'editor_copy_assistant' => [
            'name' => 'Editor Copy Assistant',
            'description' => 'Generates and refines marketing copy in the generative asset editor (text layers)',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [
                // User-triggered from editor; tenant + user attribution via AIService
            ],
        ],
        'editor_generative_image' => [
            'name' => 'Editor Generative Image',
            'description' => 'Generates images in the generative asset editor (image layers)',
            'scope' => 'tenant',
            'default_model' => 'gpt-image-1',
            /** Registry keys (ai.models) valid for default_model overrides — keep in sync with generative_editor.allowed_model_keys. */
            'allowed_models' => [
                'gpt-image-1',
                'gemini-3-pro-image-preview',
                'gemini-3.1-flash-image-preview',
                'gemini-2.5-flash-image',
            ],
            'allowed_actions' => ['read', 'generate_image'],
            'permissions' => [],
        ],
        'editor_edit_image' => [
            'name' => 'Editor Image Edit',
            'description' => 'Edits existing images in the asset editor via AI instructions',
            'scope' => 'tenant',
            'default_model' => 'gpt-image-1',
            /** Registry keys (ai.models) valid for default_model overrides — keep in sync with generative_editor.edit_allowed_model_keys. */
            'allowed_models' => [
                'gpt-image-1',
                'gemini-2.5-flash-image',
                'gemini-3-pro-image-preview',
                // 'flux-2-flex', // temporarily disabled for edit
            ],
            'allowed_actions' => ['read', 'generate_image'],
            'permissions' => [],
        ],
        'editor_layout_generator' => [
            'name' => 'Editor Layout Generator',
            'description' => 'AI creative director: selects optimal template format, layout style, and asset placements from scored brand assets, brand DNA, and user intent. Returns structured layer assignments with color palette and post-generation suggestions.',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [],
        ],
        'presentation_preview' => [
            'name' => 'Presentation preview',
            'description' => 'AI context-aware presentation still from pipeline thumbnail (drawer / deliverables)',
            'scope' => 'tenant',
            'default_model' => 'gpt-image-1',
            'allowed_models' => [
                'gpt-image-1',
                'gemini-2.5-flash-image',
                'gemini-3-pro-image-preview',
            ],
            'allowed_actions' => ['read', 'generate_image'],
            'permissions' => [],
        ],
        'studio_animate_composition' => [
            'name' => 'Studio Animate Composition',
            'description' => 'Provider-backed image-to-video from a deterministic Studio composition snapshot (V1: full composition; future: layers/groups/start-end).',
            'scope' => 'tenant',
            'default_model' => 'kling_v3_standard_image_to_video',
            'allowed_models' => [
                'kling_v3_standard_image_to_video',
            ],
            'allowed_actions' => ['read', 'generate_image'],
            'permissions' => [],
        ],
        'in_app_help_assistant' => [
            'name' => 'In-app Help Assistant',
            'description' => 'Answers user questions using only retrieved help_actions payloads (no open-ended product knowledge).',
            'scope' => 'tenant',
            'default_model' => 'gpt-4o-mini',
            'allowed_actions' => ['read'],
            'permissions' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Credit Weights
    |--------------------------------------------------------------------------
    |
    | Cost-proportional weights for different AI features.
    | Used for unified credit tracking and plan limit documentation.
    | Weight 1 = baseline (cheapest operation, e.g. image tagging ~$0.005).
    | Brand research weight reflects its ~30x higher API cost (~$0.16/call).
    |
    */
    'credit_weights' => [
        'tagging' => 1,
        'suggestions' => 1,
        'brand_research' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Controls optional logging of prompts and responses, plus retention policy.
    |
    | store_prompts:
    | - If true, stores prompt and response in agent run metadata JSON field
    | - Can be disabled for cost/privacy reasons
    | - Structured format for easy querying
    |
    | retention_days:
    | - Configurable retention window for agent run logs
    | - Default: 30 days
    | - PruneAILogs command handles deletion (outlined but not implemented yet)
    | - Do NOT store logs indefinitely
    |
    */
    'logging' => [
        'store_prompts' => env('AI_STORE_PROMPTS', false),
        /** When true, full prompt text is stored in ai_agent_runs.metadata.generative_audit for editor generative/edit only. */
        'log_generative_prompts' => env('AI_LOG_GENERATIVE_PROMPTS', false),
        'retention_days' => env('AI_LOG_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Budgets Configuration
    |--------------------------------------------------------------------------
    |
    | Defines default budgets for AI operations.
    | Budgets can be system-wide, per-agent, or per-task type.
    | Database overrides can modify these values without code changes.
    |
    | **Period: calendar month (only mode implemented).** Usage is the sum of
    | `estimated_cost` on `ai_agent_runs` with `started_at` between
    | `now()->startOfMonth()` and `endOfMonth()` in the app timezone. The cap
    | (`amount`) is USD for that same window — not rolling 7/30 days.
    |
    | **Source of truth for the system-wide cap is the `ai_budgets` row** (Admin → AI → Budgets),
    | not `.env`. The `amount` below is only a config default for merges and for the migration
    | that first-creates the system row when missing.
    |
    | Budget types:
    | - system: Global monthly cap (all tenants, all runs) — not a “non-tenant only” pool
    | - agents: Per-agent budgets (optional)
    | - tasks: Per-task type budgets (optional)
    |
    | Per-tenant monthly caps are not implemented here yet; use plan limits / AiUsageService for tenant quotas.
    |
    | Budget characteristics:
    | - Resets on the first day of each calendar month
    | - Monthly cap blocks new runs once projected spend would exceed the cap
    | - Hard limit in DB still affects log wording; cap is always enforced
    | - Warning thresholds (default 80%)
    |
    */
    'budgets' => [
        'system' => [
            'monthly' => [
                /** Default USD ceiling in config only; live value = {@see AIBudget} system row + overrides. */
                'amount' => 1000.00,
                'warning_threshold_percent' => 80,
                'hard_limit_enabled' => false,
            ],
        ],
        'agents' => [
            // Per-agent budgets (optional)
            // Example:
            // 'ticket_analyzer' => [
            //     'monthly' => [
            //         'amount' => 500.00,
            //         'warning_threshold_percent' => 80,
            //         'hard_limit_enabled' => false,
            //     ],
            // ],
        ],
        'tasks' => [
            /*
             * Studio / external API “contract” caps (USD per calendar month).
             * Used = sum of estimated_cost on ai_agent_runs for this task_type in the month.
             * Rows are created by migration from these defaults; edit caps in Admin → AI → Budgets.
             * hard_limit_enabled: when false, overage is allowed but still visible (monitoring).
             */
            'studio_layer_extraction' => [
                'display_name' => 'Studio: AI layer segmentation (SAM / Fal)',
                'description' => 'Remote mask extraction billed once per successful session.',
                'monthly' => [
                    'amount' => (float) env('AI_BUDGET_STUDIO_LAYER_EXTRACTION_USD', 50000),
                    'warning_threshold_percent' => 80,
                    'hard_limit_enabled' => (bool) env('AI_BUDGET_STUDIO_LAYER_EXTRACTION_HARD', false),
                ],
            ],
            'studio_layer_background_fill' => [
                'display_name' => 'Studio: background fill (inpaint)',
                'description' => 'Inpainting / cleanup provider after cutout; billed once per session when enabled.',
                'monthly' => [
                    'amount' => (float) env('AI_BUDGET_STUDIO_LAYER_BG_FILL_USD', 50000),
                    'warning_threshold_percent' => 80,
                    'hard_limit_enabled' => (bool) env('AI_BUDGET_STUDIO_LAYER_BG_FILL_HARD', false),
                ],
            ],
            'studio_composition_animation' => [
                'display_name' => 'Studio: still → AI video (composition animation)',
                'description' => 'Image-to-video vendor COGS estimate per completed job (config STUDIO_ANIMATION_ESTIMATED_USD_*).',
                'monthly' => [
                    'amount' => (float) env('AI_BUDGET_STUDIO_COMPOSITION_ANIMATION_USD', 50000),
                    'warning_threshold_percent' => 80,
                    'hard_limit_enabled' => (bool) env('AI_BUDGET_STUDIO_COMPOSITION_ANIMATION_HARD', false),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream provider quota (OpenAI / Anthropic / Gemini org account)
    |--------------------------------------------------------------------------
    |
    | Distinct from tenant plan limits. When a provider returns insufficient_quota, billing errors,
    | or hard 429/429-style limits, the app throws AIQuotaExceededException, emails
    | config('mail.admin_recipients') (throttled by notify_cooldown_seconds), and excludes the
    | exception from Sentry (see config/sentry.php).
    |
    */
    'quota_exceeded' => [
        'notify_cooldown_seconds' => 3600,
    ],
];

<?php

/**
 * Sentry AI Error Monitoring Configuration
 *
 * Feature-flagged configuration for AI-powered Sentry error monitoring.
 * All behavior is gated by these flags; no business logic or tenant scoping is changed.
 *
 * - pull_enabled: Fetch/ingest from Sentry
 * - auto_heal_enabled: AI-driven auto-heal actions
 * - require_manual_confirmation: Require confirmation before applying AI suggestions
 * - emergency_disable: Master kill switch; when true, pull is effectively disabled
 */

return [
    'api_url' => rtrim(env('SENTRY_API_URL', 'https://sentry.io/api/0'), '/'),
    'organization_slug' => env('SENTRY_ORG_SLUG', ''),
    'auth_token' => env('SENTRY_AUTH_TOKEN', ''),
    'pull_enabled' => filter_var(env('SENTRY_PULL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'auto_heal_enabled' => filter_var(env('SENTRY_AUTO_HEAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'require_manual_confirmation' => filter_var(env('SENTRY_REQUIRE_CONFIRMATION', true), FILTER_VALIDATE_BOOLEAN),
    'ai_model' => env('SENTRY_AI_MODEL', 'gpt-4o-mini'),
    'monthly_ai_limit' => (float) env('SENTRY_AI_MONTHLY_LIMIT', 25),
    'environment' => env('SENTRY_ENVIRONMENT', 'staging'),
    'emergency_disable' => filter_var(env('SENTRY_EMERGENCY_DISABLE', false), FILTER_VALIDATE_BOOLEAN),
    'pull_max' => (int) env('SENTRY_PULL_MAX', 50),
    'estimated_cost_per_analysis' => (float) env('SENTRY_AI_ESTIMATED_COST_PER_ANALYSIS', 0.005),
];

<?php

namespace App\Services\AI\Insights;

/**
 * Metadata insights batch job: usage feature + stable agent label for logs.
 *
 * AiUsageService maps {@see self::USAGE_FEATURE} to credit weight via config/ai_credits.php.
 */
final class InsightsAgentConstants
{
    /**
     * Stored in ai_usage.feature — must match PlanService / config plans max_ai_* key suffix.
     */
    public const USAGE_FEATURE = 'insights';

    /**
     * Human/agent label for logging (matches product name for cost attribution).
     */
    public const AI_AGENT_INSIGHTS = 'AI_AGENT_INSIGHTS';
}

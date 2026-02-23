<?php

namespace App\Services\SentryAI;

/**
 * Sentry AI configuration accessor.
 *
 * Reads only from config('sentry_ai.*'). Emergency disable overrides pullEnabled() to false.
 */
class SentryAIConfigService
{
    public function pullEnabled(): bool
    {
        if ($this->isEmergencyDisabled()) {
            return false;
        }

        return (bool) config('sentry_ai.pull_enabled', false);
    }

    public function autoHealEnabled(): bool
    {
        return (bool) config('sentry_ai.auto_heal_enabled', false);
    }

    public function requireConfirmation(): bool
    {
        return (bool) config('sentry_ai.require_manual_confirmation', true);
    }

    public function isEmergencyDisabled(): bool
    {
        return (bool) config('sentry_ai.emergency_disable', false);
    }

    public function model(): string
    {
        return (string) config('sentry_ai.ai_model', 'gpt-4o-mini');
    }

    public function monthlyLimit(): float
    {
        return (float) config('sentry_ai.monthly_ai_limit', 25);
    }

    public function environment(): string
    {
        return (string) config('sentry_ai.environment', 'staging');
    }
}

<?php

namespace Tests\Unit\Services\SentryAI;

use App\Services\SentryAI\SentryAIConfigService;
use Tests\TestCase;

/**
 * Sentry AI Config Service Test
 *
 * Verifies config values load correctly and emergency_disable overrides pullEnabled().
 */
class SentryAIConfigServiceTest extends TestCase
{
    protected SentryAIConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SentryAIConfigService();
    }

    /**
     * Config values load correctly from config (not env directly).
     */
    public function test_config_values_load_correctly(): void
    {
        config([
            'sentry_ai.pull_enabled' => true,
            'sentry_ai.auto_heal_enabled' => true,
            'sentry_ai.require_manual_confirmation' => false,
            'sentry_ai.emergency_disable' => false,
            'sentry_ai.ai_model' => 'gpt-4o',
            'sentry_ai.monthly_ai_limit' => 50.0,
            'sentry_ai.environment' => 'production',
        ]);

        $this->assertTrue($this->service->pullEnabled());
        $this->assertTrue($this->service->autoHealEnabled());
        $this->assertFalse($this->service->requireConfirmation());
        $this->assertFalse($this->service->isEmergencyDisabled());
        $this->assertSame('gpt-4o', $this->service->model());
        $this->assertSame(50.0, $this->service->monthlyLimit());
        $this->assertSame('production', $this->service->environment());
    }

    /**
     * Emergency disable overrides pullEnabled() to false even when pull_enabled is true.
     */
    public function test_emergency_disable_overrides_pull_enabled_to_false(): void
    {
        config([
            'sentry_ai.pull_enabled' => true,
            'sentry_ai.emergency_disable' => true,
        ]);

        $this->assertFalse($this->service->pullEnabled());
        $this->assertTrue($this->service->isEmergencyDisabled());
    }
}

<?php

namespace Tests\Unit\Services;

use App\Services\EmailGate;
use Tests\TestCase;

class EmailGateTest extends TestCase
{
    public function test_user_type_is_always_allowed(): void
    {
        config(['mail.automations_enabled' => false]);

        $this->assertTrue(app(EmailGate::class)->canSend(EmailGate::TYPE_USER));
    }

    public function test_system_type_requires_automations_enabled(): void
    {
        config(['mail.automations_enabled' => false]);
        $this->assertFalse(app(EmailGate::class)->canSend(EmailGate::TYPE_SYSTEM));

        config(['mail.automations_enabled' => true]);
        $this->assertTrue(app(EmailGate::class)->canSend(EmailGate::TYPE_SYSTEM));
    }

    public function test_operations_type_is_allowed_even_when_automations_disabled(): void
    {
        config(['mail.automations_enabled' => false]);

        $this->assertTrue(app(EmailGate::class)->canSend(EmailGate::TYPE_OPERATIONS));
    }

    public function test_unknown_type_is_denied(): void
    {
        config(['mail.automations_enabled' => true]);

        $this->assertFalse(app(EmailGate::class)->canSend('marketing'));
    }
}

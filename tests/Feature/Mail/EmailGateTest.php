<?php

namespace Tests\Feature\Mail;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Mail\ProbeSystemEmailMailable;
use Tests\Fixtures\Mail\ProbeUserEmailMailable;
use Tests\TestCase;

class EmailGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
        $this->flushArrayMailTransport();
    }

    protected function tearDown(): void
    {
        $this->flushArrayMailTransport();

        parent::tearDown();
    }

    private function flushArrayMailTransport(): void
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        if ($transport instanceof ArrayTransport) {
            $transport->flush();
        }
    }

    private function arrayMessageCount(): int
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->count();
    }

    public function test_system_email_is_not_sent_when_automations_disabled(): void
    {
        config(['mail.automations_enabled' => false]);

        Mail::to('test@example.com')->send(new ProbeSystemEmailMailable);

        $this->assertSame(0, $this->arrayMessageCount());
    }

    public function test_user_email_is_sent_when_automations_disabled(): void
    {
        config(['mail.automations_enabled' => false]);

        Mail::to('test@example.com')->send(new ProbeUserEmailMailable);

        $this->assertSame(1, $this->arrayMessageCount());
    }

    public function test_system_email_is_sent_when_automations_enabled(): void
    {
        config(['mail.automations_enabled' => true]);

        Mail::to('test@example.com')->send(new ProbeSystemEmailMailable);

        $this->assertSame(1, $this->arrayMessageCount());
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Emergency alert: a run was blocked because system monthly AI spend would exceed the platform cap.
 * {@see EmailGate::TYPE_OPERATIONS} — not gated by {@see config('mail.automations_enabled')}.
 */
class SystemAiBudgetCapReachedMail extends BaseMailable
{
    use Queueable;
    use SerializesModels;

    protected string $emailType = 'operations';

    public function __construct(
        public string $appEnv,
        public string $budgetEnvironment,
        public float $capUsd,
        public float $currentUsageUsd,
        public float $projectedUsageUsd,
        public float $estimatedCostUsd,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->appEnv !== '' ? $this->appEnv : 'app';

        return new Envelope(
            subject: "[{$label}] URGENT: System AI monthly cap hit — requests are being blocked"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.system-ai-budget-cap-reached',
        );
    }
}

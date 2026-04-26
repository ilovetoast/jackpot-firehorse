<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Preemptive alert: system-wide monthly AI spend is near the platform cap.
 * {@see EmailGate::TYPE_OPERATIONS} — not gated by {@see config('mail.automations_enabled')}.
 */
class SystemAiBudgetWarningMail extends BaseMailable
{
    use Queueable;
    use SerializesModels;

    protected string $emailType = 'operations';

    public int $percentOfCap;

    public function __construct(
        public string $appEnv,
        public string $budgetEnvironment,
        public float $capUsd,
        public float $currentUsageUsd,
        public int $warningThresholdPercent,
    ) {
        $this->percentOfCap = $capUsd > 0
            ? (int) round(min(100, max(0, ($currentUsageUsd / $capUsd) * 100)))
            : 0;
    }

    public function envelope(): Envelope
    {
        $label = $this->appEnv !== '' ? $this->appEnv : 'app';

        return new Envelope(
            subject: "[{$label}] System AI spend approaching monthly cap ({$this->warningThresholdPercent}% threshold)"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.system-ai-budget-warning',
        );
    }
}

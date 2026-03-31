<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * System notification when AI monthly usage cap is hit (e.g. tagging) during an AI agent run.
 * Gated by {@see EmailGate} TYPE_SYSTEM and {@see AiUsageCapNotifier} (incubation skip, monthly dedupe).
 */
class AiMonthlyUsageCapReachedMail extends BaseMailable
{
    use Queueable;
    use SerializesModels;

    protected string $emailType = 'system';

    public function __construct(
        public Tenant $tenant,
        public string $detailMessage,
        public ?int $aiAgentRunId = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'AI monthly limit reached — '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ai-monthly-usage-cap-reached',
        );
    }
}

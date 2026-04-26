<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Site-operator alert: upstream AI provider (OpenAI, etc.) org quota or billing block.
 * {@see EmailGate::TYPE_OPERATIONS} — delivers even when {@see config('mail.automations_enabled')} is false;
 * use {@see config('mail.admin_recipients')} as recipients in {@see \App\Services\AI\AIQuotaExceededNotifier}.
 */
class AIProviderQuotaExceededMail extends BaseMailable
{
    use Queueable;
    use SerializesModels;

    protected string $emailType = 'operations';

    public function __construct(
        public string $detailMessage,
        public ?string $provider = null,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->provider !== null && $this->provider !== '' ? $this->provider : 'AI provider';

        return new Envelope(
            subject: "[{$label}] Quota or billing limit — action required"
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ai-provider-quota-exceeded',
        );
    }
}

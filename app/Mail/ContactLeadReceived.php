<?php

namespace App\Mail;

use App\Models\ContactLead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal notification sent to the sales inbox when a public visitor submits
 * the contact form or signs up for the newsletter. Classified as `system`
 * because it's triggered by a job/automation path (visitor action, not an
 * authenticated app user) and is therefore gated by MAIL_AUTOMATIONS_ENABLED.
 */
class ContactLeadReceived extends BaseMailable
{
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    public function __construct(public ContactLead $lead) {}

    public function envelope(): Envelope
    {
        $who = $this->lead->company ?: ($this->lead->name ?: $this->lead->email);

        return new Envelope(
            subject: "[Jackpot] {$this->kindLabel()} — {$who}",
            replyTo: [$this->lead->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-lead-received',
            with: [
                'lead' => $this->lead,
                'kindLabel' => $this->kindLabel(),
            ],
        );
    }

    private function kindLabel(): string
    {
        return match ($this->lead->kind) {
            ContactLead::KIND_NEWSLETTER => 'Newsletter signup',
            ContactLead::KIND_SALES_INQUIRY => 'Sales inquiry / Demo request',
            default => 'Contact form',
        };
    }
}

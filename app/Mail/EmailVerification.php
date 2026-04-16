<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerification extends BaseMailable
{
    use Queueable, SerializesModels;

    protected string $emailType = 'user';

    public function __construct(
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your email — Jackpot',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-verify',
            with: [
                'url' => $this->url,
            ],
        );
    }
}

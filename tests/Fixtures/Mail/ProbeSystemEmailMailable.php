<?php

namespace Tests\Fixtures\Mail;

use App\Mail\BaseMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Test fixture: system / automated (blocked when MAIL_AUTOMATIONS_ENABLED=false). */
class ProbeSystemEmailMailable extends BaseMailable
{
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Probe system');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>system</p>');
    }
}

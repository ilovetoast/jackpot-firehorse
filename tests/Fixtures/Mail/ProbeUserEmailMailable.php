<?php

namespace Tests\Fixtures\Mail;

use App\Mail\BaseMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Test fixture: user-initiated (always allowed when gate is off). */
class ProbeUserEmailMailable extends BaseMailable
{
    use Queueable, SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Probe user');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>user</p>');
    }
}

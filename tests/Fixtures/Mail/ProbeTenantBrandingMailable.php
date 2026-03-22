<?php

namespace Tests\Fixtures\Mail;

use App\Mail\BaseMailable;
use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal mailable for asserting tenant mail branding (tests only).
 */
class ProbeTenantBrandingMailable extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        return new Envelope(subject: 'Probe');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>probe</p>');
    }
}

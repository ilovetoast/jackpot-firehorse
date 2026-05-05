<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RefundProcessedTenant extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    public function __construct(
        public Tenant $tenant,
        public User $owner,
        public ?string $stripeInvoiceId,
        public int $amountRefundedCents,
        public string $currency,
    ) {}

    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        return new Envelope(
            subject: 'Refund processed — '.$this->tenant->name,
        );
    }

    public function content(): Content
    {
        $vars = [
            'tenant_name' => $this->tenant->name,
            'owner_name' => $this->owner->name,
            'invoice_reference' => $this->stripeInvoiceId,
            'refund_amount' => $this->formatMajorUnits($this->amountRefundedCents, $this->currency),
            'billing_url' => config('app.url').'/app/billing',
            'app_name' => config('app.name'),
        ];

        return new Content(
            view: 'emails.refund-processed',
            with: $vars,
        );
    }

    public function attachments(): array
    {
        return [];
    }

    protected function formatMajorUnits(int $amountCents, string $currency): string
    {
        $major = $amountCents / 100;

        return match ($currency) {
            'USD', 'AUD', 'CAD', 'SGD', 'HKD', 'NZD' => '$'.number_format($major, 2),
            'EUR' => '€'.number_format($major, 2),
            'GBP' => '£'.number_format($major, 2),
            default => number_format($major, 2).' '.$currency,
        };
    }
}

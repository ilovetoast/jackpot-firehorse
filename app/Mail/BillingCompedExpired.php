<?php

namespace App\Mail;

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BillingCompedExpired extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $owner;
    public $expirationDate;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(Tenant $tenant, User $owner, $expirationDate = null)
    {
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->expirationDate = $expirationDate;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('billing_comped_expired');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
            ])['subject']
            : "Your complimentary plan has expired - {$this->tenant->name}";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        if ($this->template) {
            $rendered = $this->template->render([
                'tenant_name' => $this->tenant->name,
                'owner_name' => $this->owner->name,
                'owner_email' => $this->owner->email,
                'expiration_date' => $this->expirationDate ? $this->expirationDate->format('F d, Y') : '',
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'billing_url' => config('app.url') . '/app/billing',
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.billing-comped-expired',
            with: [
                'tenant' => $this->tenant,
                'owner' => $this->owner,
                'expirationDate' => $this->expirationDate,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}

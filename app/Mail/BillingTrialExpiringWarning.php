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
use Carbon\Carbon;

class BillingTrialExpiringWarning extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $owner;
    public $expirationDate;
    public $daysUntilExpiration;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(Tenant $tenant, User $owner, Carbon $expirationDate, int $daysUntilExpiration)
    {
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->expirationDate = $expirationDate;
        $this->daysUntilExpiration = $daysUntilExpiration;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('billing_trial_expiring_warning');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'expiration_date' => $this->expirationDate->format('F d, Y'),
                'days_until_expiration' => $this->daysUntilExpiration,
            ])['subject']
            : "Your trial expires in {$this->daysUntilExpiration} days - {$this->tenant->name}";

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
                'expiration_date' => $this->expirationDate->format('F d, Y'),
                'days_until_expiration' => $this->daysUntilExpiration,
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
            view: 'emails.billing-trial-expiring-warning',
            with: [
                'tenant' => $this->tenant,
                'owner' => $this->owner,
                'expirationDate' => $this->expirationDate,
                'daysUntilExpiration' => $this->daysUntilExpiration,
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

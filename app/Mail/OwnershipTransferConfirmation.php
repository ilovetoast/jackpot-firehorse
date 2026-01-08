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

class OwnershipTransferConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $currentOwner;
    public $newOwner;
    public $confirmationUrl;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(Tenant $tenant, User $currentOwner, User $newOwner, string $confirmationUrl)
    {
        $this->tenant = $tenant;
        $this->currentOwner = $currentOwner;
        $this->newOwner = $newOwner;
        $this->confirmationUrl = $confirmationUrl;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('tenant.owner_transfer_confirm');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'new_owner_name' => $this->newOwner->name,
            ])['subject']
            : "Confirm Ownership Transfer - {$this->tenant->name}";

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
                'current_owner_name' => $this->currentOwner->name,
                'new_owner_name' => $this->newOwner->name,
                'new_owner_email' => $this->newOwner->email,
                'confirmation_url' => $this->confirmationUrl,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.ownership-transfer-confirmation',
            with: [
                'tenant' => $this->tenant,
                'currentOwner' => $this->currentOwner,
                'newOwner' => $this->newOwner,
                'confirmationUrl' => $this->confirmationUrl,
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

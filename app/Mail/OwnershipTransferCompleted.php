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

class OwnershipTransferCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $previousOwner;
    public $newOwner;
    public $recipient;
    public $template;

    /**
     * Create a new message instance.
     * 
     * @param bool $isNewOwner Whether this email is for the new owner (true) or previous owner (false)
     */
    public function __construct(Tenant $tenant, User $previousOwner, User $newOwner, bool $isNewOwner)
    {
        $this->tenant = $tenant;
        $this->previousOwner = $previousOwner;
        $this->newOwner = $newOwner;
        $this->recipient = $isNewOwner ? $newOwner : $previousOwner;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('tenant.owner_transfer_completed');
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
            : "Ownership Transfer Completed - {$this->tenant->name}";

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
                'recipient_name' => $this->recipient->name,
                'previous_owner_name' => $this->previousOwner->name,
                'previous_owner_email' => $this->previousOwner->email,
                'new_owner_name' => $this->newOwner->name,
                'new_owner_email' => $this->newOwner->email,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.ownership-transfer-completed',
            with: [
                'tenant' => $this->tenant,
                'previousOwner' => $this->previousOwner,
                'newOwner' => $this->newOwner,
                'recipient' => $this->recipient,
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

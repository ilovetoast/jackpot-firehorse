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

class InviteMember extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $inviter;
    public $inviteUrl;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(Tenant $tenant, User $inviter, string $inviteUrl)
    {
        $this->tenant = $tenant;
        $this->inviter = $inviter;
        $this->inviteUrl = $inviteUrl;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('invite_member');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'inviter_name' => $this->inviter->name,
            ])['subject']
            : "You've been invited to join {$this->tenant->name}";

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
                'inviter_name' => $this->inviter->name,
                'invite_url' => $this->inviteUrl,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
            ]);

            // If template uses Blade component syntax, we need to render it
            // For now, return as HTML string - in production you'd want to compile Blade
            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.invite-member',
            with: [
                'tenant' => $this->tenant,
                'inviter' => $this->inviter,
                'inviteUrl' => $this->inviteUrl,
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

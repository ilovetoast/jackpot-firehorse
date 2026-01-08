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

class AccountSuspended extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $user;
    public $admin;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(?Tenant $tenant, User $user, User $admin)
    {
        $this->tenant = $tenant;
        $this->user = $user;
        $this->admin = $admin;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('account_suspended');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name ?? config('app.name'),
                'user_name' => $this->user->name,
                'admin_name' => $this->admin->name,
            ])['subject']
            : "Your account has been suspended" . ($this->tenant ? " - {$this->tenant->name}" : "");

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
                'tenant_name' => $this->tenant->name ?? config('app.name'),
                'user_name' => $this->user->name,
                'user_email' => $this->user->email,
                'admin_name' => $this->admin->name,
                'admin_email' => $this->admin->email,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_url' => config('app.url') . '/support',
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.account-suspended',
            with: [
                'tenant' => $this->tenant,
                'user' => $this->user,
                'admin' => $this->admin,
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

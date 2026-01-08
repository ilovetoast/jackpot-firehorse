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

class AccountDeleted extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $userEmail;
    public $userName;
    public $admin;
    public $template;

    /**
     * Create a new message instance.
     * Note: User may already be deleted, so we store email and name separately
     */
    public function __construct(Tenant $tenant, string $userEmail, string $userName, User $admin)
    {
        $this->tenant = $tenant;
        $this->userEmail = $userEmail;
        $this->userName = $userName;
        $this->admin = $admin;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('account_deleted');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'user_name' => $this->userName,
                'admin_name' => $this->admin->name,
            ])['subject']
            : "Your account has been deleted - {$this->tenant->name}";

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
                'user_name' => $this->userName,
                'user_email' => $this->userEmail,
                'admin_name' => $this->admin->name,
                'admin_email' => $this->admin->email,
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.account-deleted',
            with: [
                'tenant' => $this->tenant,
                'userEmail' => $this->userEmail,
                'userName' => $this->userName,
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

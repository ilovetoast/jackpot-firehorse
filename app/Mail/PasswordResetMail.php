<?php

namespace App\Mail;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $token
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $template = NotificationTemplate::getByKey('password_reset');
        
        if ($template) {
            $resetUrl = url(route('password.reset', [
                'token' => $this->token,
                'email' => $this->user->email,
            ], false));

            $variables = [
                'user_name' => $this->user->name,
                'user_email' => $this->user->email,
                'reset_url' => $resetUrl,
                'app_name' => config('app.name', 'Jackpot'),
                'app_url' => config('app.url', url('/')),
            ];

            $rendered = $template->render($variables);

            return new Envelope(
                subject: $rendered['subject'],
            );
        }

        return new Envelope(
            subject: 'Reset Password Notification',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $template = NotificationTemplate::getByKey('password_reset');
        
        if ($template) {
            $resetUrl = url(route('password.reset', [
                'token' => $this->token,
                'email' => $this->user->email,
            ], false));

            $variables = [
                'user_name' => $this->user->name,
                'user_email' => $this->user->email,
                'reset_url' => $resetUrl,
                'app_name' => config('app.name', 'Jackpot'),
                'app_url' => config('app.url', url('/')),
            ];

            $rendered = $template->render($variables);

            // Ensure we have valid HTML content - must be non-empty string
            $htmlContent = !empty($rendered['body_html']) ? trim((string) $rendered['body_html']) : null;
            $textContent = !empty($rendered['body_text']) ? trim((string) $rendered['body_text']) : null;

            // Validate that htmlContent is actually HTML (contains HTML tags)
            $isHtml = $htmlContent && preg_match('/<[^>]+>/', $htmlContent);

            // If HTML is empty, null, or not valid HTML, fall back to view-based approach
            if (empty($htmlContent) || !$isHtml) {
                $url = url(route('password.reset', [
                    'token' => $this->token,
                    'email' => $this->user->email,
                ], false));

                return new Content(
                    view: 'emails.password-reset',
                    with: ['url' => $url],
                );
            }

            // Use htmlString only when we have valid HTML content
            // Only pass htmlString - don't pass text parameter to avoid conflicts
            return new Content(
                htmlString: $htmlContent,
            );
        }

        // Fallback to view-based email
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $this->user->email,
        ], false));

        return new Content(
            view: 'emails.password-reset',
            with: ['url' => $url],
        );
    }
}

<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\Brand;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Support\TransactionalEmailHtml;
use App\Models\User;
use Illuminate\Bus\Queueable;
use App\Mail\BaseMailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InviteMember extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    /** Team invite already records {@see \App\Enums\EventType::USER_INVITED}. */
    protected bool $recordSendActivity = false;

    public $tenant;

    public $inviter;

    public $inviteUrl;

    public $template;

    /**
     * When set (e.g. brand workspace invite), logo + CTA/bar colors use this brand instead of the tenant default.
     */
    public ?Brand $brandingBrand = null;

    /**
     * Create a new message instance.
     *
     * @param  Brand|null  $brandingBrand  Must belong to {@see $tenant}; used for transactional colors + header wordmark/logo.
     */
    public function __construct(Tenant $tenant, User $inviter, string $inviteUrl, ?Brand $brandingBrand = null)
    {
        $this->tenant = $tenant;
        $this->inviter = $inviter;
        $this->inviteUrl = $inviteUrl;
        $this->brandingBrand = $brandingBrand;

        // Load template from database
        $this->template = NotificationTemplate::getByKey('invite_member');
    }

    /**
     * Brand to use for DB template placeholders (logo block, button/link/bar hex).
     */
    protected function brandForMailVisuals(): ?Brand
    {
        if ($this->brandingBrand && (int) $this->brandingBrand->tenant_id === (int) $this->tenant->id) {
            return $this->brandingBrand;
        }

        return Brand::where('tenant_id', $this->tenant->id)
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        $subject = $this->template
            ? $this->template->render(array_merge([
                'tenant_name' => $this->tenant->name,
                'inviter_name' => $this->inviter->name,
            ], TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand(
                $this->brandForMailVisuals()
            )))['subject']
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
            $visualBrand = $this->brandForMailVisuals();

            $rendered = $this->template->render(array_merge([
                'tenant_name' => $this->tenant->name,
                'inviter_name' => $this->inviter->name,
                'invite_url' => $this->inviteUrl,
                'app_name' => config('app.name'),
                'app_url' => rtrim((string) config('app.url'), '/'),
                'tenant_logo_block' => TransactionalEmailHtml::tenantLogoBlockFromBrand($visualBrand),
            ], TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand($visualBrand)));

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
                'brandingBrand' => $this->brandForMailVisuals(),
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

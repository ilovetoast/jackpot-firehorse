<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\Collection;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Support\TransactionalEmailHtml;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Collection-only access invite (external email). Uses notification template `invite_collection_access`
 * when present (brand logo in shell), same pattern as {@see InviteMember}.
 */
class InviteCollectionAccess extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    public ?NotificationTemplate $template = null;

    public function __construct(
        public Collection $collection,
        public User $inviter,
        public string $inviteUrl
    ) {
        $this->collection->loadMissing(['brand', 'tenant']);
        $this->template = NotificationTemplate::getByKey('invite_collection_access');
    }

    public function envelope(): Envelope
    {
        $tenant = $this->collection->tenant;
        $this->applyTenantMailBranding($tenant);

        $brandName = $this->collection->brand?->name ?? $tenant?->name ?? '';
        $subject = $this->template
            ? $this->template->render(array_merge([
                'tenant_name' => $tenant?->name ?? '',
                'brand_name' => $brandName,
                'collection_name' => $this->collection->name,
                'inviter_name' => $this->inviter->name,
            ], TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand($this->collection->brand)))['subject']
            : 'You\'re invited to view a collection: '.$this->collection->name;

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $tenant = $this->collection->tenant;
        $brand = $this->collection->brand;

        if ($this->template) {
            $rendered = $this->template->render(array_merge([
                'tenant_name' => $tenant?->name ?? '',
                'brand_name' => $brand?->name ?? ($tenant?->name ?? ''),
                'collection_name' => $this->collection->name,
                'inviter_name' => $this->inviter->name,
                'invite_url' => $this->inviteUrl,
                'app_name' => config('app.name'),
                'app_url' => rtrim((string) config('app.url'), '/'),
                'tenant_logo_block' => TransactionalEmailHtml::tenantLogoBlockFromBrand($brand),
            ], TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand($brand)));

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        return new Content(
            view: 'emails.invite-collection-access',
            with: [
                'collection' => $this->collection,
                'brand' => $brand,
                'tenant' => $tenant,
                'inviter' => $this->inviter,
                'inviteUrl' => $this->inviteUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

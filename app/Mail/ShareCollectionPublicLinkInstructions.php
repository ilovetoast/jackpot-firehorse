<?php

namespace App\Mail;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Branded email with the password-protected public collection link.
 * Password is only included when the sender verified it server-side.
 */
class ShareCollectionPublicLinkInstructions extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $collection,
        public User $sender,
        public string $shareUrl,
        public ?string $verifiedPasswordPlain = null,
        public ?string $personalMessage = null,
    ) {
        $this->collection->loadMissing(['brand', 'tenant']);
    }

    public function envelope(): Envelope
    {
        $brandName = $this->collection->brand?->name ?? '';
        $subject = $brandName !== ''
            ? "Shared collection: {$this->collection->name} ({$brandName})"
            : "Shared collection: {$this->collection->name}";

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.share-collection-public-link',
            with: [
                'collection' => $this->collection,
                'brand' => $this->collection->brand,
                'tenant' => $this->collection->tenant,
                'sender' => $this->sender,
                'shareUrl' => $this->shareUrl,
                'verifiedPasswordPlain' => $this->verifiedPasswordPlain,
                'personalMessage' => $this->personalMessage,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

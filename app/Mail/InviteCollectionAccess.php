<?php

namespace App\Mail;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase C12.0: Invite to collection-only access (no brand membership).
 */
class InviteCollectionAccess extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $collection,
        public User $inviter,
        public string $inviteUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to view a collection: ' . $this->collection->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invite-collection-access',
            with: [
                'collection' => $this->collection,
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

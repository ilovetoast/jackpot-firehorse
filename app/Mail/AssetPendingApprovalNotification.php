<?php

namespace App\Mail;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asset Pending Approval Notification Email
 *
 * Phase L.6.3 — Approval Notifications
 *
 * Email notification sent to approvers when an asset requires approval.
 */
class AssetPendingApprovalNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param Asset $asset The asset pending approval
     * @param \App\Models\User|null $uploader The user who uploaded the asset
     * @param string|null $categoryName The category name
     */
    public function __construct(
        public Asset $asset,
        public ?\App\Models\User $uploader = null,
        public ?string $categoryName = null
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Asset Pending Approval',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Get asset name
        $assetName = $this->asset->title 
            ?? $this->asset->original_filename 
            ?? 'Untitled Asset';

        // Get uploader name
        $uploaderName = $this->uploader 
            ? ($this->uploader->name ?: $this->uploader->email)
            : 'Unknown';

        // Get category name
        $categoryName = $this->categoryName ?? 'Uncategorized';

        // Get upload timestamp
        $uploadTimestamp = $this->asset->created_at 
            ? $this->asset->created_at->format('F j, Y \a\t g:i A')
            : 'Unknown';

        // Build approval URL
        $approvalUrl = url('/app/assets?lifecycle=pending_approval');

        return new Content(
            view: 'emails.asset-pending-approval',
            with: [
                'assetName' => $assetName,
                'uploaderName' => $uploaderName,
                'categoryName' => $categoryName,
                'uploadTimestamp' => $uploadTimestamp,
                'approvalUrl' => $approvalUrl,
            ],
        );
    }
}

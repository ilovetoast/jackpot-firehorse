<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily digest: pending team and creator upload approvals with counts and oldest wait time.
 */
class PendingApprovalsDigestMail extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    /**
     * @param  array{count: int, max_pending_days: int|null, oldest_summary: string|null}  $teamStats
     * @param  array{count: int, max_pending_days: int|null, oldest_summary: string|null}  $creatorStats
     */
    public function __construct(
        public Tenant $tenant,
        public Brand $brand,
        public array $teamStats,
        public array $creatorStats,
        public string $teamReviewUrl,
        public string $creatorReviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        $total = (int) ($this->teamStats['count'] ?? 0) + (int) ($this->creatorStats['count'] ?? 0);

        return new Envelope(
            subject: $total === 1
                ? '1 asset pending your approval — '.$this->brand->name
                : "{$total} assets pending your approval — ".$this->brand->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pending-approvals-digest',
            with: [
                'brandName' => $this->brand->name,
                'teamStats' => $this->teamStats,
                'creatorStats' => $this->creatorStats,
                'teamReviewUrl' => $this->teamReviewUrl,
                'creatorReviewUrl' => $this->creatorReviewUrl,
            ],
        );
    }
}

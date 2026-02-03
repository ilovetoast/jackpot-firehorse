<?php

namespace App\Mail;

use App\Models\Download;
use App\Models\Tenant;
use App\Services\DownloadPublicPageBrandingResolver;
use App\Services\PlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * D-SHARE: Email sent when user shares a download link via the share page.
 * Template selection mirrors branding logic: FREE plan uses Jackpot promo, PAID uses brand or neutral.
 */
class DownloadShareEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Download $download,
        public Tenant $tenant,
        public string $shareUrl,
        public string $personalMessage = ''
    ) {}

    public function envelope(): Envelope
    {
        $planService = app(PlanService::class);
        $plan = $planService->getCurrentPlan($this->tenant);
        $isFree = $plan === 'free';

        $subject = $isFree
            ? 'Files shared with you'
            : $this->getBrandNameForSubject() . ' shared files with you';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $planService = app(PlanService::class);
        $plan = $planService->getCurrentPlan($this->tenant);
        $isFree = $plan === 'free';

        $resolver = app(DownloadPublicPageBrandingResolver::class);
        $branding = $resolver->resolve($this->download, '');

        $view = $isFree ? 'emails.download-share-free' : 'emails.download-share-paid';

        return new Content(
            view: $view,
            with: [
                'download' => $this->download,
                'shareUrl' => $this->shareUrl,
                'personalMessage' => $this->personalMessage,
                'branding' => $branding,
                'brandName' => $this->getBrandNameForSubject(),
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }

    protected function getBrandNameForSubject(): string
    {
        $brand = $this->download->brand;

        return $brand?->name ?? $this->tenant->name ?? config('app.name', 'Jackpot');
    }
}

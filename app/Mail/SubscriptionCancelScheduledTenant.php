<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Mail\BaseMailable;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelScheduledTenant extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    protected string $emailType = 'system';

    public Tenant $tenant;

    public User $owner;

    public string $planKey;

    public ?Carbon $accessEndsAt;

    public ?NotificationTemplate $template;

    public function __construct(Tenant $tenant, User $owner, string $planKey, ?Carbon $accessEndsAt)
    {
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->planKey = $planKey;
        $this->accessEndsAt = $accessEndsAt;
        $this->template = NotificationTemplate::getByKey('subscription_cancel_scheduled_tenant');
    }

    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        $subject = $this->template
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'plan_name' => config("plans.{$this->planKey}.name", ucfirst($this->planKey)),
            ])['subject']
            : "We’ve canceled your subscription — {$this->tenant->name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $planName = config("plans.{$this->planKey}.name", ucfirst($this->planKey));
        $endLabel = $this->accessEndsAt
            ? $this->accessEndsAt->timezone(config('app.timezone'))->format('F j, Y \a\t g:i A T')
            : 'the end of your current billing period';

        $vars = [
            'tenant_name' => $this->tenant->name,
            'owner_name' => $this->owner->name,
            'owner_email' => $this->owner->email,
            'plan_name' => $planName,
            'access_ends_at' => $endLabel,
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'billing_url' => config('app.url').'/app/billing',
        ];

        if ($this->template) {
            $rendered = $this->template->render($vars);

            return new Content(htmlString: $rendered['body_html']);
        }

        return new Content(
            view: 'emails.subscription-cancel-scheduled',
            with: array_merge($vars, [
                'tenant' => $this->tenant,
                'owner' => $this->owner,
            ]),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

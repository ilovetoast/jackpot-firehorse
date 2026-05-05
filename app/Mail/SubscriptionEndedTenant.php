<?php

namespace App\Mail;

use App\Mail\Concerns\AppliesTenantMailBranding;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Mail\BaseMailable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionEndedTenant extends BaseMailable
{
    use AppliesTenantMailBranding;
    use Queueable, SerializesModels;

    protected string $emailType = 'user';

    public Tenant $tenant;

    public User $owner;

    public string $previousPlanKey;

    public ?NotificationTemplate $template;

    public function __construct(Tenant $tenant, User $owner, string $previousPlanKey)
    {
        $this->tenant = $tenant;
        $this->owner = $owner;
        $this->previousPlanKey = $previousPlanKey;
        $this->template = NotificationTemplate::getByKey('subscription_ended_tenant');
    }

    public function envelope(): Envelope
    {
        $this->applyTenantMailBranding($this->tenant);

        $subject = $this->template
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'previous_plan' => config("plans.{$this->previousPlanKey}.name", ucfirst($this->previousPlanKey)),
            ])['subject']
            : "Your paid plan has ended — {$this->tenant->name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $previousName = config("plans.{$this->previousPlanKey}.name", ucfirst($this->previousPlanKey));

        $vars = [
            'tenant_name' => $this->tenant->name,
            'owner_name' => $this->owner->name,
            'owner_email' => $this->owner->email,
            'previous_plan' => $previousName,
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'billing_url' => config('app.url').'/app/billing',
        ];

        if ($this->template) {
            $rendered = $this->template->render($vars);

            return new Content(htmlString: $rendered['body_html']);
        }

        return new Content(
            view: 'emails.subscription-ended',
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

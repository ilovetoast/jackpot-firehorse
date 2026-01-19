<?php

namespace App\Mail;

use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class PlanChangedAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public $tenant;
    public $oldPlan;
    public $newPlan;
    public $billingStatus;
    public $expirationDate;
    public $adminName;
    public $template;

    /**
     * Create a new message instance.
     */
    public function __construct(
        Tenant $tenant,
        string $oldPlan,
        string $newPlan,
        ?string $billingStatus = null,
        ?Carbon $expirationDate = null,
        ?string $adminName = null
    ) {
        $this->tenant = $tenant;
        $this->oldPlan = $oldPlan;
        $this->newPlan = $newPlan;
        $this->billingStatus = $billingStatus;
        $this->expirationDate = $expirationDate;
        $this->adminName = $adminName;
        
        // Load template from database
        $this->template = NotificationTemplate::getByKey('plan_changed_admin');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->template 
            ? $this->template->render([
                'tenant_name' => $this->tenant->name,
                'old_plan' => ucfirst($this->oldPlan),
                'new_plan' => ucfirst($this->newPlan),
            ])['subject']
            : "Plan changed for {$this->tenant->name}";

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
            $rendered = $this->template->render([
                'tenant_name' => $this->tenant->name,
                'old_plan' => ucfirst($this->oldPlan),
                'new_plan' => ucfirst($this->newPlan),
                'billing_status' => $this->billingStatus ? ucfirst($this->billingStatus) : 'Paid',
                'expiration_date' => $this->expirationDate ? $this->expirationDate->format('F d, Y') : 'No expiration',
                'admin_name' => $this->adminName ?? 'System Administrator',
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'admin_url' => config('app.url') . '/app/admin/companies/' . $this->tenant->id,
            ]);

            return new Content(
                htmlString: $rendered['body_html'],
            );
        }

        // Fallback to default template
        return new Content(
            view: 'emails.plan-changed-admin',
            with: [
                'tenant' => $this->tenant,
                'oldPlan' => $this->oldPlan,
                'newPlan' => $this->newPlan,
                'billingStatus' => $this->billingStatus,
                'expirationDate' => $this->expirationDate,
                'adminName' => $this->adminName,
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

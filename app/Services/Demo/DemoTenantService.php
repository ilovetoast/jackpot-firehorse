<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Central guardrails for disposable demo workspaces (Phase 1: data model + selective HTTP blocks).
 */
class DemoTenantService
{
    public const string DISABLED_MESSAGE = 'This action is disabled in demo workspaces.';

    /** Stripe checkout, plan changes, portal, payment method, add-ons, etc. */
    public const string ACTION_BILLING_CHANGE = 'billing_change';

    /** Team / company invites that send email (see TeamController::invite). */
    public const string ACTION_INVITE_USERS = 'invite_users';

    /** Brand-level user invitations (see BrandController::inviteUser). */
    public const string ACTION_INVITE_BRAND_USER = 'invite_brand_user';

    /** Reserved for outbound demo invite mail — same user-facing message when wired. */
    public const string ACTION_SEND_EMAIL_INVITATION = 'send_email_invitation';

    /** Reserved for customer-facing integration OAuth/connect flows. */
    public const string ACTION_EXTERNAL_INTEGRATION = 'external_integration';

    /** Reserved for future tenant API tokens / personal access tokens. */
    public const string ACTION_GENERATE_API_KEY = 'generate_api_key';

    public const string ACTION_OWNERSHIP_TRANSFER = 'ownership_transfer';

    /** Permanent delete of the tenant from company settings. */
    public const string ACTION_COMPANY_DELETE = 'company_delete';

    /**
     * @var list<string>
     */
    private const array RESTRICTED_ACTIONS = [
        self::ACTION_BILLING_CHANGE,
        self::ACTION_INVITE_USERS,
        self::ACTION_INVITE_BRAND_USER,
        self::ACTION_SEND_EMAIL_INVITATION,
        self::ACTION_EXTERNAL_INTEGRATION,
        self::ACTION_GENERATE_API_KEY,
        self::ACTION_OWNERSHIP_TRANSFER,
        self::ACTION_COMPANY_DELETE,
    ];

    public function isDemoTenant(?Tenant $tenant): bool
    {
        if ($tenant === null) {
            return false;
        }

        return (bool) $tenant->is_demo || (bool) $tenant->is_demo_template;
    }

    /**
     * Human-readable restriction message for demo/template workspaces, or null when the action is allowed.
     */
    public function demoRestrictionMessage(string $action, ?Tenant $tenant): ?string
    {
        if (! $this->isDemoTenant($tenant)) {
            return null;
        }

        if (! in_array($action, self::RESTRICTED_ACTIONS, true)) {
            return null;
        }

        return self::DISABLED_MESSAGE;
    }

    /**
     * @return Builder<Tenant>
     */
    public function demoTemplatesQuery(): Builder
    {
        return Tenant::query()
            ->where('is_demo_template', true)
            ->orderBy('name');
    }

    /**
     * @return Builder<Tenant>
     */
    public function demoInstancesQuery(): Builder
    {
        return Tenant::query()
            ->where('is_demo', true)
            ->with(['demoTemplate:id,name,slug', 'demoCreatedByUser:id,first_name,last_name,email'])
            ->orderByDesc('demo_expires_at')
            ->orderBy('name');
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function listDemoTemplates(): Collection
    {
        return $this->demoTemplatesQuery()->get();
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function listDemoInstances(): Collection
    {
        return $this->demoInstancesQuery()->get();
    }
}

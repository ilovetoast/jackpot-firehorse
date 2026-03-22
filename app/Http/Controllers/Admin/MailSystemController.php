<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Support\TenantMailBranding;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MailSystemController extends Controller
{
    public function index(): Response
    {
        $this->authorizeSiteAdmin();

        $env = app()->environment();
        $viewMode = match ($env) {
            'local' => 'local',
            'staging' => 'staging',
            default => 'default',
        };

        $defaultMailer = config('mail.default');
        $mailerConfig = config("mail.mailers.{$defaultMailer}", []);
        $transport = $mailerConfig['transport'] ?? $defaultMailer;

        $transportTarget = [
            'mailer' => $defaultMailer,
            'transport' => $transport,
        ];
        if ($transport === 'smtp') {
            $transportTarget['host'] = $mailerConfig['host'] ?? null;
            $transportTarget['port'] = $mailerConfig['port'] ?? null;
        }

        $payload = [
            'view_mode' => $viewMode,
            'app_env' => $env,
            'mailpit_url' => null,
            'mail_summary' => [
                'automations_enabled' => (bool) config('mail.automations_enabled'),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
                'transport' => $transportTarget,
            ],
            'staging' => null,
        ];

        if ($viewMode === 'local') {
            $payload['mailpit_url'] = $this->resolveMailpitDashboardUrl();
        }

        if ($viewMode === 'staging') {
            $payload['staging'] = cache()->remember('admin_mail_system_staging_snapshot', 120, function () use ($transportTarget) {
                return $this->buildStagingSnapshot($transportTarget);
            });
        }

        return Inertia::render('Admin/MailSystem', $payload);
    }

    private function authorizeSiteAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only site owners and site admins can access this page.');
        }
    }

    /**
     * Mailpit dashboard URL for local dev (Sail: FORWARD_MAILPIT_DASHBOARD_PORT, default 8025).
     */
    private function resolveMailpitDashboardUrl(): string
    {
        $port = env('FORWARD_MAILPIT_DASHBOARD_PORT', '8025');

        return 'http://localhost:'.ltrim((string) $port, ':');
    }

    /**
     * @param  array<string, mixed>  $transportTarget
     * @return array<string, mixed>
     */
    private function buildStagingSnapshot(array $transportTarget): array
    {
        $featureGate = app(FeatureGate::class);
        $planService = app(PlanService::class);

        $tenantsTotal = 0;
        $tenantsWithPlanNotifications = 0;
        $byPlan = [];

        foreach (Tenant::query()->select(['id', 'manual_plan_override'])->cursor() as $tenant) {
            $tenantsTotal++;
            $plan = $planService->getCurrentPlan($tenant);
            if ($featureGate->notificationsEnabled($tenant)) {
                $tenantsWithPlanNotifications++;
                $byPlan[$plan] = ($byPlan[$plan] ?? 0) + 1;
            }
        }

        ksort($byPlan);

        $override = config('mail.tenant_branding.enabled');

        return [
            'mail_automations_enabled' => (bool) config('mail.automations_enabled'),
            'transport' => $transportTarget,
            'tenant_mail_branding' => [
                'effective' => TenantMailBranding::enabled(),
                'env_override' => $override === null ? null : (bool) $override,
                'staging_from_address' => config('mail.tenant_branding.from_address'),
            ],
            'plan_email_notifications' => [
                'tenants_total' => $tenantsTotal,
                'tenants_with_feature' => $tenantsWithPlanNotifications,
                'by_plan' => $byPlan,
            ],
            'in_app_notification_rows' => [
                'total' => (int) DB::table('notifications')->count(),
                'unread' => (int) DB::table('notifications')->whereNull('read_at')->count(),
            ],
        ];
    }
}

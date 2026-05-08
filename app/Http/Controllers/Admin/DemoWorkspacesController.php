<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateDemoWorkspaceCloneJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoClonePlanService;
use App\Services\Demo\DemoTemplateAuditService;
use App\Services\Demo\DemoTenantService;
use App\Services\Demo\DemoWorkspaceAdminService;
use App\Services\Demo\DemoWorkspaceCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Admin demo workspace hub: templates, instances, filters, lifecycle actions, and detail view.
 */
class DemoWorkspacesController extends Controller
{
    public function __construct(
        protected DemoTenantService $demoTenantService,
        protected DemoTemplateAuditService $demoTemplateAuditService,
        protected DemoClonePlanService $demoClonePlanService,
        protected DemoWorkspaceAdminService $demoWorkspaceAdminService,
    ) {}

    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles, true) || in_array('site_owner', $siteRoles, true);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $scope = (string) $request->query('instance_scope', DemoWorkspaceAdminService::SCOPE_ALL);
        if (! in_array($scope, DemoWorkspaceAdminService::instanceFilterScopes(), true)) {
            $scope = DemoWorkspaceAdminService::SCOPE_ALL;
        }

        $planKeyRaw = $request->query('plan_key');
        $planKey = is_string($planKeyRaw) && $planKeyRaw !== '' ? $planKeyRaw : null;

        $createdByRaw = $request->query('created_by_user_id');
        $createdById = is_numeric($createdByRaw) ? (int) $createdByRaw : null;

        $focus = (string) $request->query('focus', 'both');
        if (! in_array($focus, ['both', 'templates', 'instances'], true)) {
            $focus = 'both';
        }

        $templates = $focus === 'instances'
            ? collect()
            : $this->demoTenantService->listDemoTemplates();

        if ($focus === 'templates') {
            $instances = collect();
        } else {
            $instancesQuery = $this->demoTenantService->demoInstancesQuery();
            $this->demoWorkspaceAdminService->applyInstanceFilters($instancesQuery, $scope, $planKey, $createdById);
            $instances = $instancesQuery->get();
        }

        $plans = config('plans', []);
        $planOptions = [];
        if (is_array($plans)) {
            foreach (array_keys($plans) as $key) {
                $meta = $plans[$key];
                $planOptions[] = [
                    'value' => $key,
                    'label' => is_array($meta) ? (string) ($meta['name'] ?? $key) : $key,
                ];
            }
        }

        $allTemplatesForQuickCreate = $this->demoTenantService->demoTemplatesQuery()
            ->get(['id', 'name', 'slug', 'demo_label']);
        $allowedExpirationDays = config('demo.allowed_expiration_days', [7, 14]);
        if (! is_array($allowedExpirationDays)) {
            $allowedExpirationDays = [7, 14];
        }
        $allowedExpirationDays = array_values(array_map(static fn ($d) => (int) $d, $allowedExpirationDays));

        return Inertia::render('Admin/DemoWorkspaces/Index', [
            'demo_templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'demo_label' => $t->demo_label,
                'demo_plan_key' => $t->demo_plan_key,
                'demo_status' => $t->demo_status,
                'demo_expires_at' => $t->demo_expires_at?->toIso8601String(),
                'display_badge' => 'template',
            ])->values()->all(),
            'demo_instances' => $instances->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'demo_label' => $t->demo_label,
                'demo_plan_key' => $t->demo_plan_key,
                'demo_status' => $t->demo_status,
                'demo_clone_failure_message' => $t->demo_clone_failure_message,
                'demo_expires_at' => $t->demo_expires_at?->toIso8601String(),
                'display_badge' => $this->demoWorkspaceAdminService->resolveInstanceDisplayBadge($t),
                'demo_access_url' => $this->demoWorkspaceAdminService->demoAccessUrl($t),
                'demo_template' => $t->demoTemplate ? [
                    'id' => $t->demoTemplate->id,
                    'name' => $t->demoTemplate->name,
                    'slug' => $t->demoTemplate->slug,
                ] : null,
                'created_by' => $t->demoCreatedByUser ? [
                    'id' => $t->demoCreatedByUser->id,
                    'name' => trim(($t->demoCreatedByUser->first_name.' '.$t->demoCreatedByUser->last_name)) ?: $t->demoCreatedByUser->email,
                    'email' => $t->demoCreatedByUser->email,
                ] : null,
            ])->values()->all(),
            'filters' => [
                'focus' => $focus,
                'instance_scope' => $scope,
                'plan_key' => $planKey,
                'created_by_user_id' => $createdById,
            ],
            'plan_options' => $planOptions,
            'creator_options' => $this->demoWorkspaceAdminService->listDemoCreatorOptions(),
            'instance_scope_options' => [
                ['value' => DemoWorkspaceAdminService::SCOPE_ALL, 'label' => 'All instances'],
                ['value' => DemoWorkspaceAdminService::SCOPE_IN_PROGRESS, 'label' => 'Pending / cloning'],
                ['value' => DemoWorkspaceAdminService::SCOPE_ACTIVE, 'label' => 'Active'],
                ['value' => DemoWorkspaceAdminService::SCOPE_EXPIRED, 'label' => 'Expired'],
                ['value' => DemoWorkspaceAdminService::SCOPE_FAILED, 'label' => 'Failed'],
                ['value' => DemoWorkspaceAdminService::SCOPE_ARCHIVED, 'label' => 'Archived'],
            ],
            'focus_options' => [
                ['value' => 'both', 'label' => 'Templates + instances'],
                ['value' => 'templates', 'label' => 'Templates only'],
                ['value' => 'instances', 'label' => 'Instances only'],
            ],
            'quick_create' => [
                'cloning_enabled' => (bool) config('demo.cloning_enabled', false),
                'templates' => $allTemplatesForQuickCreate->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ])->values()->all(),
                'default_template_id' => $allTemplatesForQuickCreate->first()?->id,
                'allowed_expiration_days' => $allowedExpirationDays,
                'default_plan_key' => (string) config('demo.default_plan_key', 'pro'),
                'default_expiration_days' => (int) config('demo.default_expiration_days', 7),
            ],
        ]);
    }

    /**
     * Simplified JSON API: validates via clone plan (dry-run), then creates the tenant and queues {@see CreateDemoWorkspaceCloneJob}.
     */
    public function quickCreateDemo(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        if (! config('demo.cloning_enabled')) {
            return response()->json([
                'message' => 'Demo cloning is disabled. Set DEMO_CLONING_ENABLED=true in the environment.',
            ], 403);
        }

        $allowedDays = config('demo.allowed_expiration_days', [7, 14]);
        if (! is_array($allowedDays)) {
            $allowedDays = [7, 14];
        }
        $inRule = implode(',', array_map('strval', $allowedDays));

        $validated = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('tenants', 'id')->where(fn ($q) => $q->where('is_demo_template', true))],
            'plan_key' => ['required', 'string', 'max:64'],
            'expiration_days' => ['required', 'integer', 'in:'.$inRule],
            'invited_emails' => ['nullable', 'array', 'max:50'],
            'invited_emails.*' => ['email', 'max:255'],
            'target_demo_label' => ['nullable', 'string', 'max:120'],
        ]);

        if (! is_array(config('plans')) || ! array_key_exists($validated['plan_key'], config('plans', []))) {
            throw ValidationException::withMessages([
                'plan_key' => 'Unknown plan key.',
            ]);
        }

        $template = Tenant::query()->findOrFail((int) $validated['template_id']);
        if (! $template->is_demo_template) {
            return response()->json(['message' => 'Selected tenant is not a demo template.'], 422);
        }

        $emails = array_values(array_unique(array_filter(array_map('trim', $validated['invited_emails'] ?? []))));
        $user = $request->user();
        if ($emails === [] && $user) {
            $emails = [(string) $user->email];
        }
        if ($emails === []) {
            throw ValidationException::withMessages([
                'invited_emails' => ['Provide at least one email address or stay signed in so we can use your account email.'],
            ]);
        }

        $label = trim((string) ($validated['target_demo_label'] ?? ''));
        if ($label === '') {
            $label = (string) ($template->demo_label ?: $template->name);
            if (trim($label) === '') {
                $label = 'Demo workspace';
            }
        }

        try {
            $plan = $this->demoClonePlanService->plan(
                $template,
                $label,
                $validated['plan_key'],
                (int) $validated['expiration_days'],
                $emails,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $blockers = $plan['blockers'] ?? [];
        if ($blockers !== []) {
            return response()->json([
                'message' => 'This template is not ready to clone yet.',
                'errors' => ['blockers' => $blockers],
            ], 422);
        }

        $demo = $this->persistDemoWorkspaceClone(
            $template,
            $user,
            $label,
            $validated['plan_key'],
            (int) $validated['expiration_days'],
            $emails,
        );

        $gatewayUrl = URL::to('/gateway?tenant='.rawurlencode((string) $demo->slug));

        return response()->json([
            'message' => 'Demo workspace queued.',
            'tenant' => [
                'id' => $demo->id,
                'slug' => $demo->slug,
                'name' => $demo->name,
                'demo_status' => $demo->demo_status,
            ],
            'gateway_url' => $gatewayUrl,
            'view_details_url' => route('admin.demo-workspaces.show', $demo),
            'note' => 'Provisioning runs in the background. If the gateway does not load yet, wait for the clone job to finish, then use Open demo or copy the link again.',
        ]);
    }

    /**
     * @param  list<string>  $emails
     */
    private function persistDemoWorkspaceClone(
        Tenant $sourceTemplate,
        ?User $user,
        string $targetDemoLabel,
        string $planKey,
        int $expirationDays,
        array $emails,
    ): Tenant {
        $base = Str::slug($targetDemoLabel);
        if ($base === '') {
            $base = 'demo';
        }
        $slug = $base.'-'.Str::lower(Str::random(5));
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        $demo = Tenant::query()->create([
            'name' => $targetDemoLabel,
            'slug' => $slug,
            'settings' => $sourceTemplate->settings,
            'is_demo' => true,
            'is_demo_template' => false,
            'demo_template_id' => $sourceTemplate->id,
            'demo_expires_at' => now()->addDays($expirationDays)->startOfDay(),
            'demo_status' => 'pending',
            'demo_plan_key' => $planKey,
            'demo_label' => $targetDemoLabel,
            'manual_plan_override' => $planKey,
            'billing_status' => 'comped',
            'demo_created_by_user_id' => $user?->id,
        ]);

        CreateDemoWorkspaceCloneJob::dispatch($demo->id, $emails);

        return $demo;
    }

    public function show(Tenant $tenant): Response
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo || $tenant->is_demo_template) {
            abort(404);
        }

        return Inertia::render(
            'Admin/DemoWorkspaces/Show',
            $this->demoWorkspaceAdminService->buildDetailPayload($tenant),
        );
    }

    public function expireDemo(Tenant $tenant): RedirectResponse
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo || $tenant->is_demo_template) {
            abort(404);
        }

        if (in_array($tenant->demo_status, ['archived', 'failed'], true)) {
            return redirect()
                ->route('admin.demo-workspaces.show', $tenant)
                ->with('warning', 'This demo cannot be manually expired from its current state.');
        }

        $tenant->forceFill([
            'demo_status' => 'expired',
            'demo_expires_at' => now()->subDay()->startOfDay(),
        ])->save();

        return redirect()
            ->route('admin.demo-workspaces.show', $tenant)
            ->with('success', 'Demo workspace marked as expired.');
    }

    public function extendDemo(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo || $tenant->is_demo_template) {
            abort(404);
        }

        $allowed = config('demo.allowed_expiration_days', [7, 14]);
        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:'.implode(',', array_map('strval', $allowed))],
        ]);

        if (in_array($tenant->demo_status, ['archived', 'failed', 'pending', 'cloning'], true)) {
            return redirect()
                ->route('admin.demo-workspaces.show', $tenant)
                ->with('warning', 'Extend is only available after the workspace is active or when recovering an expired demo.');
        }

        $add = (int) $validated['days'];
        $start = now();
        if ($tenant->demo_expires_at !== null && $tenant->demo_expires_at->greaterThan($start)) {
            $start = $tenant->demo_expires_at->copy();
        }
        $newExpires = $start->copy()->addDays($add)->startOfDay();

        $tenant->forceFill([
            'demo_expires_at' => $newExpires,
            'demo_status' => 'active',
        ])->save();

        return redirect()
            ->route('admin.demo-workspaces.show', $tenant)
            ->with('success', "Demo extended by {$add} days (new expiry {$newExpires->toDateString()}).");
    }

    public function archiveFailedDemo(Tenant $tenant): RedirectResponse
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo || $tenant->is_demo_template) {
            abort(404);
        }

        if ($tenant->demo_status !== 'failed') {
            return redirect()
                ->route('admin.demo-workspaces.show', $tenant)
                ->with('warning', 'Only failed demos can be archived with this action.');
        }

        $tenant->forceFill([
            'demo_status' => 'archived',
        ])->save();

        return redirect()
            ->route('admin.demo-workspaces.show', $tenant)
            ->with('success', 'Failed demo marked as archived.');
    }

    public function destroyDemoNow(Request $request, Tenant $tenant, DemoWorkspaceCleanupService $cleanupService): RedirectResponse
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo || $tenant->is_demo_template) {
            abort(404);
        }

        $request->validate([
            'acknowledge' => ['required', 'accepted'],
        ]);

        $result = $cleanupService->cleanupTenant($tenant, dryRun: false, adminBypassGrace: true);

        if (! $result['success']) {
            return redirect()
                ->route('admin.demo-workspaces.show', $tenant)
                ->with('warning', $result['message']);
        }

        return redirect()
            ->route('admin.demo-workspaces.index')
            ->with('success', 'Demo workspace and its tenant storage prefix were deleted.');
    }

    public function auditTemplate(Tenant $tenant): Response
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo_template) {
            abort(404);
        }

        $report = $this->demoTemplateAuditService->audit($tenant);

        return Inertia::render('Admin/DemoWorkspaces/TemplateAudit', [
            'report' => $report,
        ]);
    }

    public function showClonePlan(Tenant $tenant): Response
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo_template) {
            abort(404);
        }

        $plans = config('plans', []);
        $planOptions = [];
        if (is_array($plans)) {
            foreach (array_keys($plans) as $key) {
                $meta = $plans[$key];
                $planOptions[] = [
                    'value' => $key,
                    'label' => is_array($meta) ? (string) ($meta['name'] ?? $key) : $key,
                ];
            }
        }

        $defaultLabel = $tenant->demo_label ?: 'Demo instance';

        return Inertia::render('Admin/DemoWorkspaces/ClonePlan', [
            'template' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'demo_label' => $tenant->demo_label,
            ],
            'plan_options' => $planOptions,
            'expiration_days_options' => config('demo.allowed_expiration_days', [7, 14]),
            'default_plan_key' => config('demo.default_plan_key', 'pro'),
            'default_expiration_days' => (int) config('demo.default_expiration_days', 7),
            'clone_plan' => null,
            'form_defaults' => [
                'target_demo_label' => $defaultLabel,
                'plan_key' => config('demo.default_plan_key', 'pro'),
                'expiration_days' => (int) config('demo.default_expiration_days', 7),
                'invited_emails_text' => '',
            ],
            'cloning_enabled' => (bool) config('demo.cloning_enabled', false),
        ]);
    }

    /**
     * Dry-run only: builds an in-memory plan; does not persist or mutate data.
     */
    public function previewClonePlan(Request $request, Tenant $tenant): Response
    {
        $this->authorizeAdmin();

        if (! $tenant->is_demo_template) {
            abort(404);
        }

        $validated = $request->validate([
            'target_demo_label' => ['required', 'string', 'max:120'],
            'plan_key' => ['required', 'string', 'max:64'],
            'expiration_days' => ['required', 'integer', 'in:'.implode(',', array_map('strval', config('demo.allowed_expiration_days', [7, 14])))],
            'invited_emails_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $rawEmails = (string) ($validated['invited_emails_text'] ?? '');
        $emails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $rawEmails) ?: [])));

        if ($emails !== []) {
            Validator::make(
                ['invited_emails' => $emails],
                ['invited_emails.*' => ['email', 'max:255']],
            )->validate();
        }

        $report = $this->demoClonePlanService->plan(
            $tenant,
            $validated['target_demo_label'],
            $validated['plan_key'],
            (int) $validated['expiration_days'],
            $emails,
        );

        $plans = config('plans', []);
        $planOptions = [];
        if (is_array($plans)) {
            foreach (array_keys($plans) as $key) {
                $meta = $plans[$key];
                $planOptions[] = [
                    'value' => $key,
                    'label' => is_array($meta) ? (string) ($meta['name'] ?? $key) : $key,
                ];
            }
        }

        return Inertia::render('Admin/DemoWorkspaces/ClonePlan', [
            'template' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'demo_label' => $tenant->demo_label,
            ],
            'plan_options' => $planOptions,
            'expiration_days_options' => config('demo.allowed_expiration_days', [7, 14]),
            'default_plan_key' => config('demo.default_plan_key', 'pro'),
            'default_expiration_days' => (int) config('demo.default_expiration_days', 7),
            'clone_plan' => $report,
            'form_defaults' => [
                'target_demo_label' => $validated['target_demo_label'],
                'plan_key' => $validated['plan_key'],
                'expiration_days' => (int) $validated['expiration_days'],
                'invited_emails_text' => $rawEmails,
            ],
            'cloning_enabled' => (bool) config('demo.cloning_enabled', false),
        ]);
    }

    public function enqueueDemoClone(Request $request, Tenant $sourceTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAdmin();

        if (! $sourceTemplate->is_demo_template) {
            abort(404);
        }

        if (! config('demo.cloning_enabled')) {
            abort(403, 'Demo cloning is disabled.');
        }

        $validated = $request->validate([
            'target_demo_label' => ['required', 'string', 'max:120'],
            'plan_key' => ['required', 'string', 'max:64'],
            'expiration_days' => ['required', 'integer', 'in:'.implode(',', array_map('strval', config('demo.allowed_expiration_days', [7, 14])))],
            'invited_emails_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $rawEmails = (string) ($validated['invited_emails_text'] ?? '');
        $emails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $rawEmails) ?: [])));

        if ($emails !== []) {
            Validator::make(
                ['invited_emails' => $emails],
                ['invited_emails.*' => ['email', 'max:255']],
            )->validate();
        }

        $user = $request->user();
        if ($emails === [] && $user) {
            $emails = [(string) $user->email];
        }

        if ($emails === []) {
            return back()->withErrors(['invited_emails_text' => 'Provide at least one invitee email or stay logged in as an admin.']);
        }

        if (! is_array(config('plans')) || ! array_key_exists($validated['plan_key'], config('plans', []))) {
            return back()->withErrors(['plan_key' => 'Unknown plan key.']);
        }

        $demo = $this->persistDemoWorkspaceClone(
            $sourceTemplate,
            $user,
            $validated['target_demo_label'],
            $validated['plan_key'],
            (int) $validated['expiration_days'],
            $emails,
        );

        return redirect()
            ->route('admin.demo-workspaces.index')
            ->with('success', 'Demo workspace clone queued (tenant #'.$demo->id.', status pending → cloning).');
    }
}

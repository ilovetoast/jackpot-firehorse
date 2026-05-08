<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 2B — Dry-run clone plan (no writes, no tenant creation, no S3 copy).
 */
class DemoClonePlanService
{
    public function __construct(
        protected DemoTemplateAuditService $auditService,
    ) {}

    /**
     * @param  list<string>  $invitedEmails
     * @return array<string, mixed>
     */
    public function plan(
        Tenant $template,
        string $targetDemoLabel,
        string $planKey,
        int $expirationDays,
        array $invitedEmails,
    ): array {
        if (! $template->is_demo_template) {
            throw new InvalidArgumentException('Tenant must be marked as a demo template (is_demo_template = true).');
        }

        $this->validatePlanInputs($planKey, $expirationDays, $invitedEmails);

        $plans = config('plans', []);
        if (! is_array($plans) || ! array_key_exists($planKey, $plans)) {
            throw ValidationException::withMessages([
                'plan_key' => "Unknown plan key \"{$planKey}\". Use a key from config/plans.php.",
            ]);
        }

        $planName = is_array($plans[$planKey]) ? (string) ($plans[$planKey]['name'] ?? $planKey) : $planKey;

        $audit = $this->auditService->audit($template);
        $cloneReady = $audit['clone_ready'];
        $excluded = $audit['excluded_from_clone'];

        $tenantId = (int) $template->id;

        $contentCounts = Arr::except($cloneReady, [
            'tenant_user_memberships',
            'brand_user_assignments',
        ]);

        $estimatedBytes = $this->estimateCurrentVersionBytes($tenantId);
        $cloningEnabled = (bool) config('demo.cloning_enabled', false);

        $warnings = array_merge(
            $audit['warnings'] ?? [],
            $audit['unsupported_relationships'] ?? [],
            [
                'Dry-run only: no database rows are created or updated.',
                'No S3/object storage objects are copied in this phase.',
            ],
        );

        if (! $cloningEnabled) {
            $warnings[] = 'config(demo.cloning_enabled) is false — real cloning remains disabled; this plan is informational only.';
        }

        if ($invitedEmails === []) {
            $warnings[] = 'No invitee emails provided; provisioning would still create the tenant but without a pre-declared invite list.';
        }

        $blockers = [];
        foreach ($audit['missing_required_data'] ?? [] as $line) {
            $blockers[] = $line;
        }

        if (($cloneReady['assets_active'] ?? 0) > 0 && $estimatedBytes === 0) {
            $warnings[] = 'Active assets exist but current-version byte estimate is zero — verify asset_versions.file_size / assets.size_bytes are populated.';
        }

        $expiresPreview = Carbon::now()->addDays($expirationDays)->startOfDay()->toIso8601String();

        return [
            'meta' => [
                'template_tenant_id' => $tenantId,
                'template_name' => $template->name,
                'template_slug' => $template->slug,
                'template_uuid' => $template->uuid,
                'target_demo_label' => $targetDemoLabel,
                'plan_key' => $planKey,
                'plan_display_name' => $planName,
                'expiration_days' => $expirationDays,
                'demo_expires_at_preview' => $expiresPreview,
                'invited_emails' => array_values(array_unique($invitedEmails)),
                'cloning_enabled_config' => $cloningEnabled,
                'dry_run' => true,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
            'would_clone' => [
                'content_row_counts' => $contentCounts,
                'new_tenant_shell' => [
                    'tenant_row' => 1,
                    'note' => 'A new tenants row would be created with is_demo=true, demo_template_id, demo_label, demo_plan_key, demo_expires_at, and without billing fields copied from the template.',
                ],
                'access_and_memberships' => [
                    'template_tenant_user_rows' => (int) ($cloneReady['tenant_user_memberships'] ?? 0),
                    'template_brand_user_rows' => (int) ($cloneReady['brand_user_assignments'] ?? 0),
                    'note' => 'Template membership pivots are not duplicated. Invitees receive new tenant_user / brand_user rows when accounts are provisioned or accept invites (future phase).',
                ],
            ],
            'would_skip' => [
                'excluded_row_counts' => $excluded,
                'assets_trashed' => (int) ($cloneReady['assets_trashed'] ?? 0),
                'reason_assets_trashed' => 'Soft-deleted assets on the template are not part of the default clone set.',
                'billing_and_integrations' => [
                    'stripe_id',
                    'subscriptions',
                    'subscription_items',
                    'manual_plan_override_billing_snapshot',
                    'note' => 'Billing state starts clean on the new demo tenant.',
                ],
                'operational_ephemeral' => [
                    'upload_sessions',
                    'activity_events',
                    'ai_usage',
                    'ai_agent_runs',
                    'notifications',
                    'invitations',
                    'downloads_share_links',
                    'support_tickets',
                ],
            ],
            'storage_strategy' => [
                'recommended' => 'copy_objects',
                'summary' => 'Copy blobs from the template tenant prefix into the new tenant UUID prefix (same shared bucket layout as production).',
                'rejected_alternative' => [
                    'name' => 'shared_readonly_template_prefix',
                    'reason' => 'Would break tenant isolation, complicate deletes, and mix demo lifecycle with template storage.',
                ],
                'template_storage_prefix_hint' => $template->uuid ? "tenants/{$template->uuid}/…" : null,
                'estimated_clone_bytes' => $estimatedBytes,
                'estimated_clone_human' => $this->formatBytes($estimatedBytes),
                'based_on' => 'Sum of file_size on current, non-deleted asset_versions for non-deleted template assets (derivatives/thumbnails not fully estimated).',
            ],
            'warnings' => array_values(array_unique($warnings)),
            'blockers' => array_values(array_unique($blockers)),
            'audit_snapshot' => [
                'missing_required_data' => $audit['missing_required_data'] ?? [],
                'storage_summary' => $audit['storage'] ?? [],
            ],
        ];
    }

    /**
     * @param  list<string>  $invitedEmails
     */
    public function validatePlanInputs(string $planKey, int $expirationDays, array $invitedEmails): void
    {
        $allowed = config('demo.allowed_expiration_days', [7, 14]);
        if (! is_array($allowed)) {
            $allowed = [7, 14];
        }

        Validator::make(
            [
                'plan_key' => $planKey,
                'expiration_days' => $expirationDays,
                'invited_emails' => $invitedEmails,
            ],
            [
                'plan_key' => ['required', 'string', 'max:64'],
                'expiration_days' => ['required', 'integer', 'in:'.implode(',', array_map('strval', $allowed))],
                'invited_emails' => ['array'],
                'invited_emails.*' => ['email', 'max:255'],
            ],
        )->validate();
    }

    private function estimateCurrentVersionBytes(int $tenantId): int
    {
        if (! Schema::hasTable('asset_versions') || ! Schema::hasTable('assets')) {
            return 0;
        }

        $sumVersions = (int) DB::table('asset_versions')
            ->join('assets', 'asset_versions.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNull('asset_versions.deleted_at')
            ->where('asset_versions.is_current', true)
            ->sum('asset_versions.file_size');

        if ($sumVersions > 0) {
            return $sumVersions;
        }

        if (Schema::hasColumn('assets', 'size_bytes')) {
            return (int) DB::table('assets')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->sum('size_bytes');
        }

        return 0;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $value = $bytes / (1024 ** $i);

        return round($value, 2).' '.$units[$i];
    }
}

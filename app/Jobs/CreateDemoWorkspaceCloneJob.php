<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyStorageProvisioner;
use App\Services\Demo\DemoWorkspaceCloneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CreateDemoWorkspaceCloneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * @param  list<string>  $invitedEmails
     */
    public function __construct(
        public int $demoTenantId,
        public array $invitedEmails,
    ) {}

    public function handle(
        CompanyStorageProvisioner $provisioner,
        DemoWorkspaceCloneService $cloneService,
    ): void {
        if (! config('demo.cloning_enabled')) {
            $this->markFailed('Demo cloning is disabled (demo.cloning_enabled).');

            return;
        }

        $tenant = Tenant::query()->findOrFail($this->demoTenantId);

        if ($tenant->demo_status === 'archived') {
            return;
        }

        if ($tenant->demo_status === 'active') {
            return;
        }

        $template = Tenant::query()->findOrFail((int) $tenant->demo_template_id);
        if (! $template->is_demo_template) {
            $this->markFailed('Source template is no longer marked as a demo template.');

            return;
        }

        $tenant->forceFill([
            'demo_status' => 'cloning',
            'demo_clone_failure_message' => null,
        ])->save();

        try {
            $provisioner->provision($tenant);

            $actingUser = $tenant->demo_created_by_user_id
                ? User::query()->find($tenant->demo_created_by_user_id)
                : null;

            $cloneService->cloneFromTemplate($template, $tenant, $this->invitedEmails, $actingUser);

            if (config('demo.send_invite_emails_on_clone')) {
                // Reserved: outbound demo invite mail — off by default (Phase 2C).
            }

            $tenant->forceFill([
                'demo_status' => 'active',
                'demo_clone_failure_message' => null,
            ])->save();
        } catch (Throwable $e) {
            Log::error('[CreateDemoWorkspaceCloneJob] Demo clone failed', [
                'demo_tenant_id' => $this->demoTenantId,
                'message' => $e->getMessage(),
            ]);

            $tenant->forceFill([
                'demo_status' => 'failed',
                'demo_clone_failure_message' => Str::limit($e->getMessage(), 65000),
            ])->save();

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $tenant = Tenant::query()->find($this->demoTenantId);
            if ($tenant && $tenant->demo_status !== 'active') {
                $tenant->forceFill([
                    'demo_status' => 'failed',
                    'demo_clone_failure_message' => Str::limit(
                        $exception?->getMessage() ?? 'Job failed.',
                        65000,
                    ),
                ])->save();
            }
        } catch (Throwable) {
            // ignore
        }
    }

    private function markFailed(string $message): void
    {
        $tenant = Tenant::query()->find($this->demoTenantId);
        if ($tenant) {
            $tenant->forceFill([
                'demo_status' => 'failed',
                'demo_clone_failure_message' => $message,
            ])->save();
        }
    }
}

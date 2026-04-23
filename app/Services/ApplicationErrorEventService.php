<?php

namespace App\Services;

use App\Models\AIAgentRun;
use App\Models\ApplicationErrorEvent;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Sentry\Severity;

/**
 * Persists user-visible / operational errors that are not Laravel exceptions (so Sentry may not see them).
 */
class ApplicationErrorEventService
{
    public function recordFromAiAgentRun(AIAgentRun $run): ApplicationErrorEvent
    {
        $message = (string) ($run->error_message ?? '');
        if ($message === '') {
            $message = (string) ($run->summary ?? 'AI agent run failed');
        }

        $code = $this->inferCodeFromMessage($message);

        $event = ApplicationErrorEvent::create([
            'source_type' => 'ai_agent_run',
            'source_id' => (string) $run->id,
            'tenant_id' => $run->tenant_id,
            'user_id' => $run->user_id,
            'category' => 'ai_agent',
            'code' => $code,
            'message' => Str::limit($message, 60000),
            'context' => $this->contextFromRun($run),
        ]);

        $this->maybeNotifySentry($run, $message, $code);

        try {
            app(AiUsageCapNotifier::class)->maybeNotifyOwnerFromFailedAgentRun($run, $message);
        } catch (\Throwable $e) {
            \Log::warning('[ApplicationErrorEventService] AiUsageCapNotifier failed', [
                'ai_agent_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $event;
    }

    /**
     * True when a Playwright / Node / Chromium symptom suggests worker misconfiguration (not a normal script exit 3–5).
     *
     * @param  int  $exitCode  Process exit code, or -1 when the Symfony process wrapper threw before a clean exit.
     */
    public static function shouldRecordStudioPlaywrightWorkerInfra(int $exitCode, string $stderr, string $publicMessage): bool
    {
        if ($exitCode === 1) {
            return true;
        }
        $hay = strtolower($stderr."\n".$publicMessage);
        $needles = [
            'cannot find module',
            'err_module_not_found',
            'playwright',
            'chromium',
            'browsertype.launch',
            'failed to launch',
            'executable doesn\'t exist',
            'no such file or directory',
            'enoent',
            'econnrefused',
            'getaddrinfo',
            'net::err_',
            'spawn enoent',
            'is not recognized as an internal or external command',
            'exceeded the timeout',
            'signal 9',
            'sigkill',
        ];
        foreach ($needles as $n) {
            if (str_contains($hay, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Surface likely worker / Node / Playwright dependency problems to the Operations Center (application_error_events)
     * and optional throttled email. Dedupes identical {@code $eventCode} per tenant for a short window to avoid spam.
     *
     * @param  array<string, mixed>  $diagnosticsContext
     */
    public function recordStudioCanvasPlaywrightWorkerIssue(
        StudioCompositionVideoExportJob $row,
        string $eventCode,
        int $exitCode,
        string $stderr,
        string $publicMessage,
        array $diagnosticsContext,
    ): void {
        if (! Schema::hasTable('application_error_events')) {
            return;
        }
        if (! self::shouldRecordStudioPlaywrightWorkerInfra($exitCode, $stderr, $publicMessage)) {
            return;
        }

        $dedupeM = max(5, (int) config('studio_video.worker_infra_event_dedupe_minutes', 30));
        $tenantId = $row->tenant_id;

        $dup = ApplicationErrorEvent::query()
            ->where('category', 'studio_worker_infra')
            ->where('code', $eventCode)
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>', now()->subMinutes($dedupeM))
            ->exists();
        if ($dup) {
            Log::warning('[STUDIO_WORKER_INFRA] canvas playwright failure (deduped skip for application_error_events)', [
                'export_job_id' => $row->id,
                'tenant_id' => $tenantId,
                'exit_code' => $exitCode,
                'event_code' => $eventCode,
            ]);

            return;
        }

        $context = array_merge($diagnosticsContext, [
            'export_job_id' => $row->id,
            'composition_id' => $row->composition_id,
            'exit_code' => $exitCode,
            'stderr_tail' => Str::limit($stderr, 4000),
        ]);

        ApplicationErrorEvent::create([
            'source_type' => 'studio_video_export_job',
            'source_id' => (string) $row->id,
            'tenant_id' => $tenantId,
            'user_id' => $row->user_id,
            'category' => 'studio_worker_infra',
            'code' => $eventCode,
            'message' => Str::limit($publicMessage, 60000),
            'context' => $context,
        ]);

        Log::warning('[STUDIO_WORKER_INFRA] canvas playwright worker/dependency issue recorded to application_error_events', [
            'export_job_id' => $row->id,
            'tenant_id' => $tenantId,
            'exit_code' => $exitCode,
            'code' => $eventCode,
        ]);

        $this->maybeMailStudioWorkerInfraAlert($eventCode, $publicMessage, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function maybeMailStudioWorkerInfraAlert(string $code, string $publicMessage, array $context): void
    {
        $explicit = trim((string) config('studio_video.worker_infra_alert_email', ''));
        $useOwner = (bool) config('studio_video.worker_infra_alert_use_site_owner_email', false);
        $to = $explicit;
        if ($to === '' && $useOwner) {
            $owner = User::query()->find(1);
            $to = $owner !== null && is_string($owner->email) ? trim($owner->email) : '';
        }
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $throttleM = max(15, (int) config('studio_video.worker_infra_alert_mail_minutes', 360));
        $cacheKey = 'studio_worker_infra_mail:'.sha1($code.'|'.$to);
        if (! Cache::add($cacheKey, 1, now()->addMinutes($throttleM))) {
            return;
        }

        $lines = [
            'Jackpot: Studio canvas-runtime export hit a likely worker / Playwright / Node dependency problem.',
            '',
            'Alert code: '.$code,
            '',
            $publicMessage,
            '',
            'Context (truncated):',
            json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            '',
            'See Operations Center → Application errors (category studio_worker_infra).',
        ];

        try {
            Mail::raw(implode("\n", $lines), function ($message) use ($to, $code): void {
                $message->to($to)->subject('[Jackpot] Studio worker infra: '.$code);
            });
        } catch (\Throwable $e) {
            Log::warning('[STUDIO_WORKER_INFRA] alert email failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function contextFromRun(AIAgentRun $run): array
    {
        return array_filter([
            'agent_id' => $run->agent_id,
            'agent_name' => $run->agent_name,
            'task_type' => $run->task_type,
            'entity_type' => $run->entity_type,
            'entity_id' => $run->entity_id,
            'model_used' => $run->model_used,
            'environment' => $run->environment,
            'triggering_context' => $run->triggering_context,
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function inferCodeFromMessage(string $message): ?string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'overload')) {
            return 'provider_overloaded';
        }
        if (str_contains($lower, 'rate limit') || str_contains($lower, '429')) {
            return 'rate_limited';
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($lower, '503') || str_contains($lower, 'service unavailable')) {
            return 'service_unavailable';
        }
        if (str_contains($lower, 'cap exceeded') && str_contains($lower, 'monthly ai')) {
            return 'ai_monthly_cap_exceeded';
        }

        return null;
    }

    /**
     * Optional bridge to Sentry for provider/transient class errors (still not "exceptions").
     */
    protected function maybeNotifySentry(AIAgentRun $run, string $message, ?string $code): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        $notifyCodes = ['provider_overloaded', 'rate_limited', 'service_unavailable', 'timeout'];
        if ($code === null || ! in_array($code, $notifyCodes, true)) {
            return;
        }

        if (! config('application_errors.sentry_capture_messages', true)) {
            return;
        }

        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($run, $message, $code): void {
            $scope->setTag('application_error', 'true');
            $scope->setTag('error_code', $code);
            $scope->setTag('agent_id', (string) $run->agent_id);
            if ($run->tenant_id) {
                $scope->setTag('tenant_id', (string) $run->tenant_id);
            }
            $scope->setContext('ai_agent_run', [
                'id' => $run->id,
                'task_type' => $run->task_type,
                'entity_type' => $run->entity_type,
                'entity_id' => $run->entity_id,
            ]);
            \Sentry\captureMessage('[AI] '.$message, Severity::warning());
        });
    }
}

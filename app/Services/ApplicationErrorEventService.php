<?php

namespace App\Services;

use App\Models\AIAgentRun;
use App\Models\ApplicationErrorEvent;
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

        return $event;
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

        if (! config('sentry.application_error_messages', true)) {
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

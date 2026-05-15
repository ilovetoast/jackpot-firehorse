<?php

namespace App\Services\Filters\Instrumentation;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\QuickFilterInstrumentation;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5.2 — opt-in log-channel instrumentation.
 *
 * Bound in place of {@see NullQuickFilterInstrumentation} in environments
 * where a structured log sink (Datadog, Loki, …) is preferable to a real
 * analytics warehouse. Emits a `[quick-filter] <event>` info-level line per
 * event with the relevant ids — cheap to ingest, easy to grep.
 *
 * Errors thrown by the underlying logger are swallowed: instrumentation
 * must never break a user-facing flow.
 */
class LogQuickFilterInstrumentation implements QuickFilterInstrumentation
{
    public function recordOpen(
        MetadataField $field,
        Category $folder,
        ?Tenant $tenant = null,
    ): void {
        $this->emit('open', [
            'field_id' => $field->id,
            'field_key' => $field->key,
            'folder_id' => $folder->id,
            'tenant_id' => $tenant?->id,
        ]);
    }

    public function recordOverflowOpen(
        Category $folder,
        ?Tenant $tenant = null,
    ): void {
        $this->emit('overflow_open', [
            'folder_id' => $folder->id,
            'tenant_id' => $tenant?->id,
        ]);
    }

    public function recordSelection(
        MetadataField $field,
        Category $folder,
        mixed $value,
        ?Tenant $tenant = null,
    ): void {
        $this->emit('selection', [
            'field_id' => $field->id,
            'field_key' => $field->key,
            'folder_id' => $folder->id,
            'tenant_id' => $tenant?->id,
            'value' => is_scalar($value) || $value === null
                ? $value
                : (is_array($value) ? array_map('strval', $value) : null),
        ]);
    }

    private function emit(string $event, array $payload): void
    {
        try {
            Log::info('[quick-filter] '.$event, $payload);
        } catch (\Throwable $e) {
            // intentional swallow — instrumentation must not break the flow.
        }
    }
}

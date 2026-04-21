<?php

namespace App\Studio\Animation\Support;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Services\StudioAnimationService;
use Illuminate\Support\Facades\Log;

/**
 * Compact structured logs for Studio Animation rollout (no raw provider payloads).
 *
 * Standard context keys: job_id, status, provider, render_engine, renderer_version,
 * drift_level, drift_decision, verified_webhook, retry_kind, finalize_reuse_mode,
 * provider_submission_used_frame.
 */
final class StudioAnimationObservability
{
    public static function enabled(): bool
    {
        return (bool) config('studio_animation.observability.enabled', true);
    }

    public static function emitMetricLineEnabled(): bool
    {
        return (bool) config('studio_animation.observability.emit_metric_line', true);
    }

    /**
     * Flat subset for log aggregators (no raw payloads).
     *
     * @return array<string, bool|int|string|null>
     */
    public static function rolloutDimensions(?StudioAnimationJob $job): array
    {
        $ctx = self::contextFromJob($job);

        return array_intersect_key($ctx, array_flip([
            'job_id',
            'status',
            'provider',
            'render_engine',
            'renderer_version',
            'drift_level',
            'drift_decision',
            'verified_webhook',
            'retry_kind',
            'finalize_reuse_mode',
            'provider_submission_used_frame',
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, bool|float|int|string>
     */
    public static function extrasAllowedOnMetricLine(array $extra): array
    {
        $keys = ['drift_decision', 'exc', 'error_brief', 'finalize_last_outcome'];
        $out = [];
        foreach ($keys as $k) {
            if (! array_key_exists($k, $extra)) {
                continue;
            }
            $v = $extra[$k];
            if ($v === null || $v === '') {
                continue;
            }
            if (is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, bool|int|string|null>  $extra
     */
    public static function log(string $event, ?StudioAnimationJob $job, array $extra = []): void
    {
        if (! self::enabled()) {
            return;
        }

        $ctx = array_merge(self::contextFromJob($job), $extra);
        Log::info('[sa] '.$event, $ctx);

        if (self::emitMetricLineEnabled()) {
            $metric = array_merge(
                ['event' => $event],
                self::rolloutDimensions($job),
                self::extrasAllowedOnMetricLine($extra),
            );
            $metric = array_filter(
                $metric,
                static fn ($v) => $v !== null && $v !== ''
            );
            Log::info('[sa_metric]', $metric);
        }
    }

    /**
     * @param  array<string, mixed>|null  $decision
     */
    public static function compactDriftDecision(?array $decision): ?string
    {
        if ($decision === null || $decision === []) {
            return null;
        }

        $parts = [];
        foreach (['drift_checked', 'drift_warned', 'drift_blocked', 'gate_enabled', 'gate_mode', 'blocked_reason'] as $k) {
            if (! array_key_exists($k, $decision)) {
                continue;
            }
            $v = $decision[$k];
            if (is_bool($v)) {
                $parts[] = $k.'='.($v ? '1' : '0');
            } elseif ($v === null) {
                $parts[] = $k.'=null';
            } else {
                $parts[] = $k.'='.str_replace(',', '_', (string) $v);
            }
        }

        return $parts === [] ? null : implode(',', $parts);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public static function contextFromJob(?StudioAnimationJob $job): array
    {
        if ($job === null) {
            return ['job_id' => null];
        }

        $settings = is_array($job->settings_json) ? $job->settings_json : [];
        $cf = is_array($settings['canonical_frame'] ?? null) ? $settings['canonical_frame'] : [];
        $dd = is_array($settings['drift_decision'] ?? null) ? $settings['drift_decision'] : null;

        return [
            'job_id' => (int) $job->id,
            'status' => (string) $job->status,
            'provider' => (string) $job->provider,
            'render_engine' => isset($cf['render_engine']) ? (string) $cf['render_engine'] : null,
            'renderer_version' => isset($cf['renderer_version']) ? (string) $cf['renderer_version'] : null,
            'drift_level' => isset($cf['drift_level']) ? (string) $cf['drift_level'] : null,
            'drift_decision' => self::compactDriftDecision($dd),
            'verified_webhook' => (bool) ($settings['last_webhook_verified'] ?? false),
            'retry_kind' => app(StudioAnimationService::class)->effectiveRetryKind($job),
            'finalize_reuse_mode' => isset($settings['finalize_reuse_mode']) ? (string) $settings['finalize_reuse_mode'] : null,
            'provider_submission_used_frame' => isset($cf['provider_submit_start_image_origin'])
                ? (string) $cf['provider_submit_start_image_origin']
                : null,
        ];
    }
}

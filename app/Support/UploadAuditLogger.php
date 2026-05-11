<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5: Central log emitter for `upload_blocked` events.
 *
 * Why a wrapper instead of `Log::warning('upload_blocked', [...])` directly:
 *
 *   1. Every gate (preflight, initiate, initiate_batch, finalize_content_sniff,
 *      finalize_response, …) needs the same correlation context — request id,
 *      ip, user agent hash — so we can group log lines for one upload attempt
 *      during incident response. Doing this inline at every call site is
 *      brittle and the kind of thing we *will* forget on the next gate.
 *
 *   2. We pipe every event through {@see UploadAnomalyDetector} so a burst
 *      of blocked uploads from one user / IP can trip a paging signal. That
 *      cross-cutting concern doesn't belong inside each controller.
 *
 *   3. We can change the log channel (e.g. dedicate a `security` channel,
 *      ship to Datadog, attach Sentry breadcrumbs) in one place.
 *
 * Usage:
 *   UploadAuditLogger::warning(['gate' => 'initiate', 'reason' => '...', ...]);
 *   UploadAuditLogger::log('warning', [...]);
 */
class UploadAuditLogger
{
    public const EVENT = 'upload_blocked';

    /**
     * @param  string  $level  `emergency` | `alert` | `critical` | `error` |
     *                          `warning` | `notice` | `info` | `debug`
     */
    public static function log(string $level, array $context): void
    {
        $context = static::enrichContext($context);

        Log::log($level, self::EVENT, $context);

        // Anomaly detection runs on warning/critical events only; info-level
        // events (e.g. allowed-but-noteworthy) do not contribute to bursts.
        if (in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            try {
                app(\App\Services\Security\UploadAnomalyDetector::class)->record($context);
            } catch (\Throwable $e) {
                // Detector failure must never block the log emission or
                // break the request flow; surface it but keep going.
                Log::error('[UploadAuditLogger] anomaly detector failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function warning(array $context): void
    {
        static::log('warning', $context);
    }

    public static function info(array $context): void
    {
        static::log('info', $context);
    }

    /**
     * Decorate the caller's context with correlation fields. We never
     * overwrite values the caller explicitly set — those are authoritative.
     */
    protected static function enrichContext(array $context): array
    {
        $rid = $context['request_id'] ?? AssignRequestId::$current ?? null;
        if ($rid === null) {
            try {
                $req = request();
                if ($req) {
                    $rid = $req->attributes->get('request_id')
                        ?? $req->headers->get('X-Request-Id');
                }
            } catch (\Throwable $e) {
                $rid = null;
            }
        }

        $defaults = [
            'event' => self::EVENT,
            'request_id' => $rid,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $req = request();
            if ($req) {
                $defaults['ip'] = $req->ip();
                $ua = (string) $req->headers->get('User-Agent', '');
                if ($ua !== '') {
                    // Hash the UA so we can group bots without storing the
                    // exact string everywhere (avoids accidental PII leaks).
                    $defaults['user_agent_sha'] = substr(hash('sha256', $ua), 0, 16);
                }
                $defaults['route'] = optional($req->route())->getName() ?? $req->path();
                $defaults['method'] = $req->method();
            }
        } catch (\Throwable $e) {
            // No request bound (e.g. queue worker) — ignore.
        }

        // Caller-supplied keys win over defaults so callers can override
        // route/method/ip when logging from a queued job.
        return array_merge($defaults, $context);
    }
}

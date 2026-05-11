<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5: Cheap-but-useful anomaly detector for `upload_blocked` bursts.
 *
 * The DAM uploader is one of our highest-risk attack surfaces (a successful
 * exploit lets an attacker put arbitrary content into our customers'
 * brand asset libraries and downstream-distributed CDN). We already block
 * dangerous file types at multiple gates, but the *pattern* of failed
 * attempts itself is a signal worth alerting on. Specifically:
 *
 *   - One IP hammering the preflight/initiate endpoints with rejected files
 *     (probing what we'll accept, scripted enumeration).
 *   - One authenticated user generating a sudden flood of `upload_blocked`
 *     events (compromised account, malicious insider, broken integration).
 *
 * Implementation notes:
 *
 *   - Uses Laravel's cache as the rolling counter. With the default file/
 *     redis driver this is fast (sub-millisecond) and keys auto-expire so
 *     we don't accumulate unbounded state.
 *   - Counters are partitioned by minute. We sum the last `window_minutes`
 *     buckets so the window slides without needing a real time series DB.
 *   - We page (Log::critical) at most once per dedupe window per identity
 *     so a sustained attack doesn't generate one alert per failed request.
 *
 * Tunable via config('assets.security.upload_anomaly').
 */
class UploadAnomalyDetector
{
    public function record(array $context): void
    {
        $cfg = (array) config('assets.security.upload_anomaly', []);
        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        $threshold = (int) ($cfg['threshold'] ?? 8);
        $windowMinutes = max(1, (int) ($cfg['window_minutes'] ?? 5));
        $dedupeMinutes = max(1, (int) ($cfg['dedupe_minutes'] ?? 30));

        $identities = $this->identitiesFromContext($context);

        foreach ($identities as $kind => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $count = $this->incrementWindow($kind, (string) $value, $windowMinutes);
            if ($count >= $threshold) {
                $this->maybePage($kind, (string) $value, $count, $context, $dedupeMinutes, $threshold, $windowMinutes);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function identitiesFromContext(array $context): array
    {
        return [
            'ip' => $context['ip'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
        ];
    }

    /**
     * Increment the per-minute counter for (kind, value), then return the
     * sum across the rolling window.
     */
    protected function incrementWindow(string $kind, string $value, int $windowMinutes): int
    {
        $now = (int) floor(time() / 60);
        $key = $this->bucketKey($kind, $value, $now);

        // Each bucket lives 2× window minutes so we can sum a full window
        // even if the cache was just populated.
        $ttlSeconds = $windowMinutes * 60 * 2;

        // Cache::increment returns the new value but only when the key
        // already exists; on cold start we have to seed it.
        if (! Cache::has($key)) {
            Cache::put($key, 1, $ttlSeconds);
            $current = 1;
        } else {
            $current = (int) Cache::increment($key);
        }

        $sum = $current;
        for ($i = 1; $i < $windowMinutes; $i++) {
            $bucket = $this->bucketKey($kind, $value, $now - $i);
            $sum += (int) (Cache::get($bucket, 0));
        }

        return $sum;
    }

    protected function bucketKey(string $kind, string $value, int $minute): string
    {
        return sprintf('upload_anomaly:%s:%s:%d', $kind, sha1($value), $minute);
    }

    protected function maybePage(
        string $kind,
        string $value,
        int $count,
        array $context,
        int $dedupeMinutes,
        int $threshold,
        int $windowMinutes,
    ): void {
        $dedupeKey = sprintf('upload_anomaly_paged:%s:%s', $kind, sha1($value));
        if (Cache::has($dedupeKey)) {
            return;
        }
        Cache::put($dedupeKey, true, $dedupeMinutes * 60);

        // Critical-level so it can be wired to PagerDuty/Slack via the
        // log channel routing without code changes.
        Log::critical('upload_anomaly_detected', [
            'identity_kind' => $kind,
            // Hash the IP so we don't put PII into logs in plaintext; the
            // raw value is recoverable from the upload_blocked records
            // sharing the same request_id.
            'identity_hash' => substr(sha1($value), 0, 16),
            'event_count' => $count,
            'threshold' => $threshold,
            'window_minutes' => $windowMinutes,
            'sample_request_id' => $context['request_id'] ?? null,
            'sample_gate' => $context['gate'] ?? null,
            'sample_reason' => $context['reason'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ]);
    }
}

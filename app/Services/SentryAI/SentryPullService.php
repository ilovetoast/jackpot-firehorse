<?php

namespace App\Services\SentryAI;

use App\Models\SentryIssue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

/**
 * Pulls unresolved issues from Sentry REST API and upserts into sentry_issues.
 *
 * Respects SENTRY_PULL_ENABLED and SENTRY_EMERGENCY_DISABLE via SentryAIConfigService.
 * Only pulls issues for the configured environment. Filters: level in [error, warning], count > 1.
 * Fails safely if Sentry API is unavailable (logs and returns zero counts).
 */
class SentryPullService
{
    public function __construct(
        protected SentryAIConfigService $config
    ) {
    }

    /**
     * Pull unresolved issues from Sentry and upsert. Returns counts for logging.
     *
     * @return array{pulled: int, new: int, updated: int}
     */
    public function pull(): array
    {
        if (! $this->config->pullEnabled()) {
            return ['pulled' => 0, 'new' => 0, 'updated' => 0, 'issues' => []];
        }

        $apiUrl = config('sentry_ai.api_url', '');
        $orgSlug = config('sentry_ai.organization_slug', '');
        $authToken = config('sentry_ai.auth_token', '');
        if ($apiUrl === '' || $orgSlug === '' || $authToken === '') {
            Log::warning('[SentryPullService] Pull skipped: missing api_url, organization_slug, or auth_token');

            return ['pulled' => 0, 'new' => 0, 'updated' => 0, 'issues' => []];
        }

        $environment = $this->config->environment();
        $url = "{$apiUrl}/organizations/{$orgSlug}/issues/";

        try {
            $response = Http::withToken($authToken)
                ->timeout(30)
                ->acceptJson()
                ->get($url, [
                    'environment' => $environment,
                    'query' => 'is:unresolved',
                ]);

            if (! $response->successful()) {
                Log::error('[SentryPullService] Sentry API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['pulled' => 0, 'new' => 0, 'updated' => 0, 'issues' => []];
            }

            $items = $response->json();
            if (! is_array($items)) {
                Log::warning('[SentryPullService] Sentry API returned non-array response');

                return ['pulled' => 0, 'new' => 0, 'updated' => 0, 'issues' => []];
            }

            $maxPerRun = (int) config('sentry_ai.pull_max', 50);
            if ($maxPerRun > 0 && count($items) > $maxPerRun) {
                $items = array_slice($items, 0, $maxPerRun);
                Log::info('[SentryPullService] Capped to max issues per run', ['max' => $maxPerRun]);
            }
        } catch (\Throwable $e) {
            Log::error('[SentryPullService] Sentry API unavailable', [
                'message' => $e->getMessage(),
            ]);

            return ['pulled' => 0, 'new' => 0, 'updated' => 0, 'issues' => []];
        }

        $new = 0;
        $updated = 0;
        $touched = [];
        $allowedLevels = ['error', 'warning'];

        foreach ($items as $item) {
            $level = isset($item['level']) ? strtolower((string) $item['level']) : '';
            if (! in_array($level, $allowedLevels, true)) {
                continue;
            }

            $count = $this->occurrenceCount($item);
            if ($count < 2) {
                continue;
            }

            $sentryId = isset($item['id']) ? (string) $item['id'] : null;
            if ($sentryId === '') {
                continue;
            }

            $stackTrace = $this->fetchStackTraceForIssue($apiUrl, $orgSlug, $authToken, $sentryId);
            $attributes = $this->mapIssueToAttributes($item, $environment, $stackTrace);
            $existing = SentryIssue::where('sentry_issue_id', $sentryId)->first();

            if ($existing) {
                $existing->update($attributes);
                $updated++;
                $touched[] = $existing->fresh();
            } else {
                $created = SentryIssue::create(array_merge($attributes, ['sentry_issue_id' => $sentryId]));
                $new++;
                $touched[] = $created;
            }
        }

        $pulled = $new + $updated;

        return [
            'pulled' => $pulled,
            'new' => $new,
            'updated' => $updated,
            'issues' => $touched,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function occurrenceCount(array $item): int
    {
        if (isset($item['lifetime']['count'])) {
            $v = $item['lifetime']['count'];

            return (int) (is_string($v) ? $v : $v);
        }
        if (isset($item['count'])) {
            $v = $item['count'];

            return (int) (is_string($v) ? $v : $v);
        }

        return 0;
    }

    /**
     * Fetch the latest event for an issue and extract exception stack trace as formatted text.
     * On failure (network, non-2xx, or missing/odd payload) logs a warning and returns null.
     *
     * @return string|null Formatted "file:line function" lines, most recent frame first, or null
     */
    protected function fetchStackTraceForIssue(string $apiUrl, string $orgSlug, string $authToken, string $sentryId): ?string
    {
        $eventsUrl = "{$apiUrl}/organizations/{$orgSlug}/issues/{$sentryId}/events/?per_page=1&full=1";

        try {
            $response = Http::withToken($authToken)
                ->timeout(15)
                ->acceptJson()
                ->get($eventsUrl);

            if (! $response->successful()) {
                Log::warning('[SentryPullService] Event fetch failed for issue', [
                    'sentry_issue_id' => $sentryId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $events = $response->json();
            if (! is_array($events) || count($events) === 0) {
                Log::warning('[SentryPullService] No events returned for issue', ['sentry_issue_id' => $sentryId]);

                return null;
            }

            $event = $events[0];

            return $this->extractStackTrace($event);
        } catch (\Throwable $e) {
            Log::warning('[SentryPullService] Event fetch error for issue', [
                'sentry_issue_id' => $sentryId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract exception stack trace from a single event payload as formatted text.
     * Looks in entries[] for type === "exception", then data.values[0].stacktrace.frames.
     * Frames are reversed (most recent first) and formatted as "file:line function" per line.
     *
     * @param array<string, mixed> $event Single event from events list
     * @return string|null Formatted stack trace or null if not found
     */
    protected function extractStackTrace(array $event): ?string
    {
        $frames = $this->extractExceptionFrames($event);
        if ($frames === null || count($frames) === 0) {
            return null;
        }

        return $this->formatFramesAsStackTrace($frames);
    }

    /**
     * From a full event payload, get exception entry's first value stacktrace frames.
     *
     * @param array<string, mixed> $event Single event from events list (full=1)
     * @return array<int, array<string, mixed>>|null
     */
    protected function extractExceptionFrames(array $event): ?array
    {
        $entries = $event['entries'] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (isset($entry['type']) && (string) $entry['type'] === 'exception'
                && isset($entry['data']['values']) && is_array($entry['data']['values'])
            ) {
                $first = $entry['data']['values'][0] ?? null;
                if (is_array($first) && isset($first['stacktrace']['frames']) && is_array($first['stacktrace']['frames'])) {
                    return $first['stacktrace']['frames'];
                }
                return null;
            }
        }

        return null;
    }

    /**
     * Format frames as "file:line function", most recent (top of stack) first.
     *
     * @param array<int, array<string, mixed>> $frames
     */
    protected function formatFramesAsStackTrace(array $frames): string
    {
        $lines = [];
        foreach (array_reverse($frames) as $frame) {
            $file = $frame['filename'] ?? $frame['absPath'] ?? '?';
            $line = $frame['lineNo'] ?? $frame['line_no'] ?? $frame['lineno'] ?? '?';
            $function = $frame['function'] ?? '';
            $lines[] = trim("{$file}:{$line} {$function}");
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $item
     * @param string|null $stackTrace
     * @return array<string, mixed>
     */
    protected function mapIssueToAttributes(array $item, string $environment, ?string $stackTrace = null): array
    {
        $firstSeen = $item['lifetime']['firstSeen'] ?? $item['firstSeen'] ?? null;
        $lastSeen = $item['lifetime']['lastSeen'] ?? $item['lastSeen'] ?? null;

        return [
            'environment' => $environment,
            'level' => isset($item['level']) ? strtolower((string) $item['level']) : 'error',
            'title' => $item['title'] ?? $item['metadata']['title'] ?? 'Untitled',
            'fingerprint' => $item['fingerprint'] ?? null,
            'occurrence_count' => $this->occurrenceCount($item),
            'first_seen' => $firstSeen ? Carbon::parse($firstSeen)->toDateTimeString() : null,
            'last_seen' => $lastSeen ? Carbon::parse($lastSeen)->toDateTimeString() : null,
            'stack_trace' => $stackTrace,
            'status' => 'open',
        ];
    }
}

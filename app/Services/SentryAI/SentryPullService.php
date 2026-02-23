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

            $attributes = $this->mapIssueToAttributes($item, $environment);
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
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function mapIssueToAttributes(array $item, string $environment): array
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
            'stack_trace' => null,
            'status' => 'open',
        ];
    }
}

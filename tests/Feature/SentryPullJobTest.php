<?php

namespace Tests\Feature;

use App\Jobs\PullSentryIssuesJob;
use App\Models\SentryIssue;
use App\Services\SentryAI\SentryAIConfigService;
use App\Services\SentryAI\SentryPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sentry pull job: mock Sentry API and assert records inserted.
 */
class SentryPullJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sentry_ai.pull_enabled' => true,
            'sentry_ai.emergency_disable' => false,
            'sentry_ai.environment' => 'staging',
            'sentry_ai.api_url' => 'https://sentry.io/api/0',
            'sentry_ai.organization_slug' => 'test-org',
            'sentry_ai.auth_token' => 'test-token',
        ]);
    }

    public function test_pull_inserts_records_when_sentry_api_returns_issues(): void
    {
        $payload = [
            [
                'id' => 'sentry-issue-1',
                'level' => 'error',
                'title' => 'Test exception',
                'count' => '10',
                'firstSeen' => '2026-02-20T10:00:00Z',
                'lastSeen' => '2026-02-23T12:00:00Z',
                'metadata' => ['title' => 'Test exception'],
            ],
            [
                'id' => 'sentry-issue-2',
                'level' => 'warning',
                'title' => 'Deprecation warning',
                'lifetime' => [
                    'count' => '3',
                    'firstSeen' => '2026-02-22T08:00:00Z',
                    'lastSeen' => '2026-02-23T09:00:00Z',
                ],
                'metadata' => ['title' => 'Deprecation warning'],
            ],
        ];

        Http::fake([
            'https://sentry.io/api/0/organizations/test-org/issues/*' => Http::response($payload, 200),
        ]);

        $service = app(SentryPullService::class);
        $result = $service->pull();

        $this->assertSame(2, $result['pulled']);
        $this->assertSame(2, $result['new']);
        $this->assertSame(0, $result['updated']);

        $this->assertDatabaseCount('sentry_issues', 2);
        $this->assertDatabaseHas('sentry_issues', [
            'sentry_issue_id' => 'sentry-issue-1',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test exception',
            'occurrence_count' => 10,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('sentry_issues', [
            'sentry_issue_id' => 'sentry-issue-2',
            'environment' => 'staging',
            'level' => 'warning',
            'title' => 'Deprecation warning',
            'occurrence_count' => 3,
            'status' => 'open',
        ]);
    }

    public function test_pull_skips_when_pull_disabled(): void
    {
        config(['sentry_ai.pull_enabled' => false]);

        $service = app(SentryPullService::class);
        $result = $service->pull();

        $this->assertSame(0, $result['pulled']);
        $this->assertSame(0, $result['new']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseCount('sentry_issues', 0);
        Http::assertNothingSent();
    }

    public function test_pull_skips_issues_with_count_less_than_2(): void
    {
        $payload = [
            [
                'id' => 'sentry-single',
                'level' => 'error',
                'title' => 'One occurrence',
                'count' => '1',
                'firstSeen' => '2026-02-23T10:00:00Z',
                'lastSeen' => '2026-02-23T10:00:00Z',
                'metadata' => ['title' => 'One occurrence'],
            ],
        ];

        Http::fake([
            'https://sentry.io/api/0/organizations/test-org/issues/*' => Http::response($payload, 200),
        ]);

        $service = app(SentryPullService::class);
        $result = $service->pull();

        $this->assertSame(0, $result['pulled']);
        $this->assertDatabaseCount('sentry_issues', 0);
    }

    public function test_pull_upserts_existing_issue(): void
    {
        SentryIssue::create([
            'sentry_issue_id' => 'sentry-existing',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Old title',
            'occurrence_count' => 5,
            'status' => 'open',
        ]);

        $payload = [
            [
                'id' => 'sentry-existing',
                'level' => 'error',
                'title' => 'Updated title',
                'count' => '20',
                'firstSeen' => '2026-02-20T10:00:00Z',
                'lastSeen' => '2026-02-23T14:00:00Z',
                'metadata' => ['title' => 'Updated title'],
            ],
        ];

        Http::fake([
            'https://sentry.io/api/0/organizations/test-org/issues/*' => Http::response($payload, 200),
        ]);

        $service = app(SentryPullService::class);
        $result = $service->pull();

        $this->assertSame(1, $result['pulled']);
        $this->assertSame(0, $result['new']);
        $this->assertSame(1, $result['updated']);

        $issue = SentryIssue::where('sentry_issue_id', 'sentry-existing')->first();
        $this->assertSame('Updated title', $issue->title);
        $this->assertSame(20, $issue->occurrence_count);
    }

    public function test_job_fails_safely_when_api_unavailable(): void
    {
        Http::fake([
            'https://sentry.io/api/0/organizations/test-org/issues/*' => Http::response(null, 503),
        ]);

        $job = new PullSentryIssuesJob();
        $job->handle(app(SentryPullService::class), app(SentryAIConfigService::class));

        $this->assertDatabaseCount('sentry_issues', 0);
    }
}

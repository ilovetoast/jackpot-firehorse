<?php

namespace Tests\Feature;

use App\Models\SentryIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentry issue persistence: create, defaults for status and selected_for_heal.
 */
class SentryIssuePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_can_be_created(): void
    {
        $issue = SentryIssue::create([
            'sentry_issue_id' => 'sentry-12345',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test exception',
        ]);

        $this->assertDatabaseHas('sentry_issues', [
            'sentry_issue_id' => 'sentry-12345',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test exception',
        ]);
        $this->assertNotNull($issue->id);
        $this->assertTrue(strlen($issue->id) === 36);
    }

    public function test_status_defaults_to_open(): void
    {
        SentryIssue::create([
            'sentry_issue_id' => 'sentry-999',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Default status test',
        ]);

        $this->assertDatabaseHas('sentry_issues', [
            'sentry_issue_id' => 'sentry-999',
            'status' => 'open',
        ]);
        $issue = SentryIssue::where('sentry_issue_id', 'sentry-999')->first();
        $this->assertSame('open', $issue->status);
    }

    public function test_selected_for_heal_defaults_to_false(): void
    {
        SentryIssue::create([
            'sentry_issue_id' => 'sentry-888',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Default selected_for_heal test',
        ]);

        $issue = SentryIssue::where('sentry_issue_id', 'sentry-888')->first();
        $this->assertNotNull($issue);
        $this->assertFalse($issue->selected_for_heal);
    }
}

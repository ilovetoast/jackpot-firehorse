<?php

namespace Tests\Feature;

use App\Enums\AITaskType;
use App\Models\SentryIssue;
use App\Services\SentryAI\SentryAIAnalyzer;
use App\Services\SentryAI\SentryAIConfigService;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Sentry AI Analyzer: mock AI client and assert summary, root cause, fix suggestion and cost saved.
 */
class SentryAIAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sentry_ai.pull_enabled' => true,
            'sentry_ai.emergency_disable' => false,
            'sentry_ai.monthly_ai_limit' => 100,
            'sentry_ai.ai_model' => 'gpt-4o-mini',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_analyze_saves_ai_summary_root_cause_fix_suggestion_and_cost(): void
    {
        $issue = SentryIssue::create([
            'sentry_issue_id' => 'sentry-123',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'NullPointerException',
            'stack_trace' => " at com.example.Foo.bar(Foo.java:42)\n at com.example.Baz.run(Baz.java:10)",
        ]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldReceive('executeAgent')
            ->once()
            ->with(
                'sentry_error_analyzer',
                AITaskType::SENTRY_ERROR_ANALYSIS,
                Mockery::type('string'),
                Mockery::on(function (array $opts) {
                    return ($opts['triggering_context'] ?? '') === 'system'
                        && ($opts['model'] ?? '') === 'gpt-4o-mini'
                        && isset($opts['sentry_issue_id']);
                })
            )
            ->andReturn([
                'text' => "## Summary\nA null reference was dereferenced in Foo.bar.\n\n## Root cause\nFoo was not initialized before use.\n\n## Fix suggestion\nAdd a null check or initialize Foo before calling bar().",
                'cost' => 0.0012,
                'tokens_in' => 100,
                'tokens_out' => 80,
            ]);

        $this->app->instance(AIService::class, $mockAi);

        $analyzer = app(SentryAIAnalyzer::class);
        $result = $analyzer->analyze($issue);

        $this->assertTrue($result);

        $issue->refresh();
        $this->assertNotNull($issue->ai_summary);
        $this->assertStringContainsString('null reference', $issue->ai_summary);
        $this->assertNotNull($issue->ai_root_cause);
        $this->assertStringContainsString('not initialized', $issue->ai_root_cause);
        $this->assertNotNull($issue->ai_fix_suggestion);
        $this->assertStringContainsString('null check', $issue->ai_fix_suggestion);
        $this->assertSame(100, $issue->ai_token_input);
        $this->assertSame(80, $issue->ai_token_output);
        $this->assertSame('0.0012', (string) $issue->ai_cost);
    }

    public function test_analyze_skips_when_emergency_disabled(): void
    {
        config(['sentry_ai.emergency_disable' => true]);

        $issue = SentryIssue::create([
            'sentry_issue_id' => 'sentry-456',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test',
            'stack_trace' => 'Some stack trace',
        ]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldNotReceive('executeAgent');

        $this->app->instance(AIService::class, $mockAi);

        $analyzer = app(SentryAIAnalyzer::class);
        $result = $analyzer->analyze($issue);

        $this->assertFalse($result);
        $issue->refresh();
        $this->assertNull($issue->ai_summary);
    }

    public function test_analyze_skips_when_no_stack_trace(): void
    {
        $issue = SentryIssue::create([
            'sentry_issue_id' => 'sentry-789',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test',
            'stack_trace' => null,
        ]);

        $mockAi = Mockery::mock(AIService::class);
        $mockAi->shouldNotReceive('executeAgent');

        $this->app->instance(AIService::class, $mockAi);

        $analyzer = app(SentryAIAnalyzer::class);
        $result = $analyzer->analyze($issue);

        $this->assertFalse($result);
    }
}

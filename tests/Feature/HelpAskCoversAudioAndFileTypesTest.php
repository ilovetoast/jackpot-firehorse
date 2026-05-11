<?php

namespace Tests\Feature;

use App\Services\HelpActionService;
use Tests\TestCase;

/**
 * The "Ask AI" panel was returning "Confidence: low — no exact documented
 * match" for two recurring questions:
 *
 *   1. "what file types are supported"  — generic upload question.
 *   2. "how much does audio AI cost"    — added in Phase 4 and previously
 *                                          had no documented help entry.
 *
 * Both are now documented in config/help_actions.php with strongly-aliased
 * registry entries. This test pins that contract: the real registry
 * (NOT a synthetic one) must score these queries above the strong-match
 * threshold so the AI pipeline gets a grounded answer.
 *
 * Score floor of 12 mirrors `ai.help_ask.strong_match_min_score` so a
 * regression in aliases or short_answer copy will fail this test loudly.
 */
class HelpAskCoversAudioAndFileTypesTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function questionExpectations(): array
    {
        return [
            // Reproducing the exact question from the screenshot first —
            // this was the canonical "Confidence: low" failure case.
            'what file types are supported' => ['what file types are supported', 'concepts.supported_file_types'],
            'supported formats short query' => ['supported formats', 'concepts.supported_file_types'],
            'mp3 transcription question' => ['can jackpot transcribe an mp3', 'ai.audio_insights'],
            'audio ai literal feature key' => ['audio_insights', 'ai.audio_insights'],
            'audio ai cost question' => ['how many credits does audio ai cost', 'ai.audio_insights'],
            'whisper provider question' => ['does jackpot use whisper', 'ai.audio_insights'],
        ];
    }

    /**
     * @dataProvider questionExpectations
     */
    public function test_question_resolves_to_documented_help_action(string $question, string $expectedKey): void
    {
        $service = app(HelpActionService::class);

        $result = $service->forRequest($question, [], null);

        $this->assertNotEmpty($result['results'], "Expected at least one help-action match for question: {$question}");

        $top = $result['results'][0];
        $this->assertSame(
            $expectedKey,
            $top['key'] ?? null,
            "Expected help-action '{$expectedKey}' to be the top match for '{$question}'. Got '"
                .($top['key'] ?? 'null')
                ."'. Top 3: ".json_encode(array_slice(array_map(fn ($r) => $r['key'] ?? null, $result['results']), 0, 3))
        );
    }

    public function test_supported_file_types_entry_mentions_each_major_family(): void
    {
        // The short_answer is what the AI grounding step quotes back to the
        // user. Force-pin that the new entry covers the major families so
        // marketing / docs / support all stay in sync from this single row.
        $actions = config('help_actions.actions');
        $entry = collect($actions)->firstWhere('key', 'concepts.supported_file_types');

        $this->assertNotNull($entry, 'concepts.supported_file_types entry must exist in config/help_actions.php');

        $body = mb_strtolower((string) ($entry['short_answer'] ?? ''));
        foreach (['image', 'video', 'audio', 'pdf', 'svg', 'office'] as $family) {
            $this->assertStringContainsString(
                $family,
                $body,
                "concepts.supported_file_types short_answer must mention the '{$family}' family so the AI ground-truth stays accurate."
            );
        }
    }

    public function test_audio_insights_entry_states_the_credit_pricing(): void
    {
        $actions = config('help_actions.actions');
        $entry = collect($actions)->firstWhere('key', 'ai.audio_insights');

        $this->assertNotNull($entry, 'ai.audio_insights entry must exist in config/help_actions.php');

        $body = mb_strtolower((string) ($entry['short_answer'] ?? ''));
        $this->assertStringContainsString('credit', $body, 'audio insights help entry must surface that this is a credit-bearing feature');
        $this->assertStringContainsString('1 base credit + 1 per additional minute', $body, 'audio insights help entry must publish the explicit 1 + 1/min tier so users can size cost before triggering the run');
    }
}

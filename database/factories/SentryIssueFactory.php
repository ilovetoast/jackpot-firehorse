<?php

namespace Database\Factories;

use App\Models\SentryIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SentryIssue>
 */
class SentryIssueFactory extends Factory
{
    protected $model = SentryIssue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sentry_issue_id' => (string) fake()->unique()->numerify('sentry-########'),
            'environment' => fake()->randomElement(['staging', 'production', 'local']),
            'level' => fake()->randomElement(['error', 'warning', 'fatal']),
            'title' => fake()->sentence(),
            'fingerprint' => fake()->optional(0.7)->sha1(),
            'occurrence_count' => fake()->numberBetween(1, 100),
            'first_seen' => fake()->dateTimeBetween('-30 days', 'now'),
            'last_seen' => fake()->dateTimeBetween('-1 day', 'now'),
            'stack_trace' => fake()->optional(0.5)->paragraphs(3, true),
            'ai_summary' => null,
            'ai_root_cause' => null,
            'ai_fix_suggestion' => null,
            'status' => 'open',
            'selected_for_heal' => false,
            'auto_heal_attempted' => false,
            'ai_token_input' => null,
            'ai_token_output' => null,
            'ai_cost' => null,
        ];
    }
}

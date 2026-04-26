<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\AIBudgetExceededException;
use App\Exceptions\AIQuotaExceededException;
use App\Exceptions\PlanLimitExceededException;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AI limits — three separate mechanisms (for operators and client UX):
 *
 * 1) {@see AIQuotaExceededException} — upstream OpenAI/Anthropic/Gemini org billing, API quota, or 429. Operator email; not tenant or system USD cap.
 * 2) {@see AIBudgetExceededException} — in-app system (or other) monthly USD cap from {@see \App\Models\AIBudget}. Blocks the AI call; optional admin emails.
 * 3) {@see PlanLimitExceededException} with limitType {@see PlanLimitExceededException::LIMIT_AI_CREDITS} — per-tenant monthly AI credit pool; user-facing message + reset date.
 */
class PlanLimitExceededExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function ai_credits_includes_reset_fields_in_api_array(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15 12:00:00', 'UTC'));
        config(['app.timezone' => 'UTC']);

        $e = new PlanLimitExceededException(
            PlanLimitExceededException::LIMIT_AI_CREDITS,
            100,
            100,
        );

        $a = $e->toApiArray();
        $this->assertArrayHasKey('credits_reset_on', $a);
        $this->assertSame('2026-05-01', $a['credits_reset_on']);
        $this->assertArrayHasKey('credits_reset_label', $a);
        $this->assertStringContainsString('100', $a['message']);
    }

    #[Test]
    public function non_ai_credit_limit_falls_back_to_message(): void
    {
        $e = new PlanLimitExceededException('tags', 5, 5, 'Too many tags');
        $this->assertStringContainsString('Too many tags', $e->getUserFacingMessage());
    }
}

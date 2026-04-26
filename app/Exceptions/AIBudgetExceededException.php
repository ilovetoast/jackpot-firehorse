<?php

namespace App\Exceptions;

use App\Models\AIBudget;
use Exception;

/**
 * AI Budget Exceeded Exception
 *
 * Thrown when an AI execution is blocked because projected spend would exceed the monthly cap
 * ({@see \App\Services\AIBudgetService::checkBudget}).
 */
class AIBudgetExceededException extends Exception
{
    public function __construct(
        string $message,
        public readonly AIBudget $budget,
        public readonly float $effectiveAmount,
        public readonly float $currentUsage,
        public readonly float $estimatedCost
    ) {
        parent::__construct($message);
    }

    /**
     * Message safe for end users and APIs (no internal budget / dollar detail).
     */
    public function getPublicMessage(): string
    {
        return 'AI service is temporarily unavailable. Please try again later.';
    }

    public function isSystemBudget(): bool
    {
        return $this->budget->budget_type === 'system';
    }
}

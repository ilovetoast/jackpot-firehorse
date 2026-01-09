<?php

namespace App\Exceptions;

use App\Models\AIBudget;
use Exception;

/**
 * AI Budget Exceeded Exception
 *
 * Thrown when an AI execution is blocked by a hard budget limit.
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
}

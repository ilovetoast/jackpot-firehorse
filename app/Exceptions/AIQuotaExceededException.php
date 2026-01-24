<?php

namespace App\Exceptions;

use Exception;

/**
 * AI Quota Exceeded Exception
 *
 * Thrown when OpenAI API quota is exceeded.
 * This is different from plan limits - it's an API provider quota issue.
 */
class AIQuotaExceededException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        ?string $message = null,
        ?string $provider = null
    ) {
        $message = $message ?? "AI provider quota exceeded. Please check your API billing and quota limits.";
        
        if ($provider) {
            $message = "{$provider} quota exceeded. Please check your API billing and quota limits.";
        }

        parent::__construct($message);
    }
}

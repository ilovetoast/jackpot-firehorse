<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a provider (OpenAI, Anthropic, Gemini) reports org quota, billing, or hard rate limits
 * that are distinct from in-app / tenant plan limits. Handled as a warning + operator email, not a Sentry error.
 */
class AIQuotaExceededException extends Exception
{
    public function __construct(
        string $message = '',
        public readonly ?string $provider = null
    ) {
        if ($message === '') {
            $message = $this->defaultMessageForProvider();
        }
        parent::__construct($message);
    }

    private function defaultMessageForProvider(): string
    {
        if ($this->provider !== null && $this->provider !== '') {
            return "{$this->provider} quota exceeded. Please check your API billing and quota limits.";
        }

        return 'AI provider quota exceeded. Please check your API billing and quota limits.';
    }
}

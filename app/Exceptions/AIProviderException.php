<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AI upstream (OpenAI, Anthropic, etc.) returned an error.
 *
 * - {@see getMessage()} / internal: full provider text for logs, Sentry, and support.
 * - {@see getPublicMessage()}: safe, user-facing copy for HTTP JSON / Inertia modals.
 */
final class AIProviderException extends RuntimeException implements HttpExceptionInterface
{
    private string $publicMessage;

    private int $httpStatusCode;

    public function __construct(string $publicMessage, string $internalMessage, int $statusCode = 503, ?\Throwable $previous = null)
    {
        $this->publicMessage = $publicMessage;
        $this->httpStatusCode = $statusCode;
        parent::__construct($internalMessage, 0, $previous);
    }

    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }

    public function getStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getHeaders(): array
    {
        return [];
    }
}

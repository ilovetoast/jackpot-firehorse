<?php

namespace App\Support;

/**
 * Sanitize AI-related technical errors for user-facing display.
 * Strips S3 URLs, presigned params, API internals.
 */
class AiErrorSanitizer
{
    public static function forUser(string $rawError): string
    {
        // S3 / presigned URL / download errors
        if (str_contains($rawError, 'Error while downloading') || str_contains($rawError, 'X-Amz-') || str_contains($rawError, '.s3.')) {
            return 'Could not process image for AI analysis. Please try again or contact support.';
        }

        // Internal image fetch failure
        if (str_contains($rawError, 'AI image fetch failed')) {
            return 'Could not load image for AI analysis. Please try again.';
        }

        // Generic OpenAI/API errors
        if (str_contains($rawError, 'OpenAI API error') || str_contains($rawError, 'API error')) {
            return 'AI analysis service is temporarily unavailable. Please try again.';
        }

        // Rate limit / timeout
        if (str_contains($rawError, 'timeout') || str_contains($rawError, 'rate limit') || str_contains($rawError, '429')) {
            return 'AI service is busy. Please try again in a few minutes.';
        }

        return 'AI processing could not complete. Please try again.';
    }
}

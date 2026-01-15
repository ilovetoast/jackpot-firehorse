<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 🔒 Phase 2.5 — Observability Layer (LOCKED)
 * This file is part of a locked phase. Do not refactor or change behavior.
 * Future phases may consume emitted signals only.
 * 
 * Phase 2.5 Step 2: Upload Error Response Helper
 * 
 * Centralizes upload error response formatting to ensure consistency across
 * all upload-related endpoints. Produces AI-ready, machine-readable error
 * responses that match the frontend normalization expectations.
 * 
 * This helper ensures:
 * - Consistent error structure across all upload endpoints
 * - AI-ready error codes for pattern detection
 * - Safe user-facing error messages
 * - Proper HTTP status code mapping
 * - Context preservation (upload_session_id, file_type, pipeline_stage)
 * 
 * IMPORTANT: Error codes are stable string enums intended for:
 * - AI agent pattern detection
 * - Support ticket categorization (future)
 * - Error aggregation and analytics
 * 
 * @see Phase 2.5 Step 1: Frontend error normalization expects this format
 */
class UploadErrorResponse
{
    /**
     * Error categories matching frontend normalization
     */
    public const CATEGORY_AUTH = 'AUTH';
    public const CATEGORY_CORS = 'CORS';
    public const CATEGORY_NETWORK = 'NETWORK';
    public const CATEGORY_VALIDATION = 'VALIDATION';
    public const CATEGORY_PIPELINE = 'PIPELINE';
    public const CATEGORY_UNKNOWN = 'UNKNOWN';

    /**
     * Pipeline stages for context
     */
    public const STAGE_UPLOAD = 'upload';
    public const STAGE_FINALIZE = 'finalize';
    public const STAGE_THUMBNAIL = 'thumbnail';

    /**
     * Error codes - stable string enums for AI pattern detection
     * 
     * These codes enable pattern detection like:
     * - "Company X had 5 UPLOAD_AUTH_EXPIRED errors in 1 hour"
     * - "All PDFs are failing with UPLOAD_FINALIZE_VALIDATION_FAILED"
     */
    public const CODE_AUTH_EXPIRED = 'UPLOAD_AUTH_EXPIRED';
    public const CODE_AUTH_REQUIRED = 'UPLOAD_AUTH_REQUIRED';
    public const CODE_PERMISSION_DENIED = 'UPLOAD_PERMISSION_DENIED';
    public const CODE_SESSION_NOT_FOUND = 'UPLOAD_SESSION_NOT_FOUND';
    public const CODE_SESSION_EXPIRED = 'UPLOAD_SESSION_EXPIRED';
    public const CODE_SESSION_INVALID = 'UPLOAD_SESSION_INVALID';
    public const CODE_PIPELINE_CONFLICT = 'UPLOAD_PIPELINE_CONFLICT';
    public const CODE_PIPELINE_TERMINAL = 'UPLOAD_PIPELINE_TERMINAL';
    public const CODE_FILE_TOO_LARGE = 'UPLOAD_FILE_TOO_LARGE';
    public const CODE_VALIDATION_FAILED = 'UPLOAD_VALIDATION_FAILED';
    public const CODE_FINALIZE_VALIDATION_FAILED = 'UPLOAD_FINALIZE_VALIDATION_FAILED';
    public const CODE_FILE_MISSING = 'UPLOAD_FILE_MISSING';
    public const CODE_SERVER_ERROR = 'UPLOAD_SERVER_ERROR';
    public const CODE_UNKNOWN = 'UPLOAD_UNKNOWN_ERROR';

    /**
     * Create a normalized upload error response
     * 
     * @param string $errorCode Stable error code enum (e.g., self::CODE_AUTH_EXPIRED)
     * @param string $message User-friendly error message
     * @param int $httpStatus HTTP status code (401, 403, 409, 422, etc.)
     * @param array $context Additional context:
     *   - upload_session_id: string|null
     *   - asset_id: string|null
     *   - file_type: string|null (file extension, e.g., 'pdf', 'jpg')
     *   - pipeline_stage: string|null (self::STAGE_UPLOAD, self::STAGE_FINALIZE, etc.)
     * @return JsonResponse
     */
    public static function error(
        string $errorCode,
        string $message,
        int $httpStatus,
        array $context = []
    ): JsonResponse {
        // Determine category from error code
        $category = self::getCategoryFromErrorCode($errorCode);

        // Build response payload
        $payload = [
            'error_code' => $errorCode,
            'message' => $message,
            'category' => $category,
            'context' => [
                'upload_session_id' => $context['upload_session_id'] ?? null,
                'asset_id' => $context['asset_id'] ?? null,
                'file_type' => $context['file_type'] ?? null,
                'pipeline_stage' => $context['pipeline_stage'] ?? null,
            ],
        ];

        // Log for observability (structured logging for AI analysis)
        Log::warning('[Upload Error Response]', [
            'error_code' => $errorCode,
            'category' => $category,
            'http_status' => $httpStatus,
            'context' => $payload['context'],
            'message' => $message,
        ]);

        return response()->json($payload, $httpStatus);
    }

    /**
     * Map exception to normalized error response
     * 
     * Analyzes common exception types and error messages to produce
     * appropriate normalized error responses.
     * 
     * @param \Throwable $exception The exception to map
     * @param int|null $defaultHttpStatus Default HTTP status if not determinable
     * @param array $context Additional context (upload_session_id, file_type, etc.)
     * @return JsonResponse
     */
    public static function fromException(
        \Throwable $exception,
        ?int $defaultHttpStatus = 500,
        array $context = []
    ): JsonResponse {
        $message = $exception->getMessage();
        $httpStatus = $defaultHttpStatus;

        // Map common exception types and messages to error codes
        // Auth errors
        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return self::error(
                self::CODE_AUTH_REQUIRED,
                'Authentication required. Please log in and try again.',
                401,
                $context
            );
        }

        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return self::error(
                self::CODE_PERMISSION_DENIED,
                'Upload permission denied. Please check your account permissions.',
                403,
                $context
            );
        }

        // Validation errors
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            // Extract validation message (simplified - don't expose all field errors in main message)
            $userMessage = 'File validation failed. Please check the file type and size.';
            
            return self::error(
                self::CODE_VALIDATION_FAILED,
                $userMessage,
                422,
                array_merge($context, [
                    'validation_fields' => $exception->errors(), // Include in context, not main message
                ])
            );
        }

        // Plan limit exceeded (special case)
        if ($exception instanceof \App\Exceptions\PlanLimitExceededException) {
            return self::error(
                self::CODE_VALIDATION_FAILED, // Treat as validation (plan limits)
                $exception->getMessage(),
                403,
                $context
            );
        }

        // Runtime exceptions - analyze message patterns
        if ($exception instanceof \RuntimeException) {
            // Upload session errors
            if (str_contains($message, 'not found') || str_contains($message, 'does not exist')) {
                return self::error(
                    self::CODE_SESSION_NOT_FOUND,
                    'Upload session not found. The session may have expired.',
                    404,
                    $context
                );
            }

            if (str_contains($message, 'expired') || str_contains($message, 'no longer available')) {
                return self::error(
                    self::CODE_SESSION_EXPIRED,
                    'Upload session expired. Please start a new upload.',
                    410,
                    $context
                );
            }

            if (str_contains($message, 'terminal state') || str_contains($message, 'already completed')) {
                return self::error(
                    self::CODE_PIPELINE_TERMINAL,
                    'Upload session is already in terminal state. This operation cannot be performed.',
                    409,
                    $context
                );
            }

            if (str_contains($message, 'invalid state') || str_contains($message, 'cannot transition')) {
                return self::error(
                    self::CODE_PIPELINE_CONFLICT,
                    'Upload session is in an invalid state for this operation.',
                    409,
                    $context
                );
            }

            // File validation errors
            if (str_contains($message, 'too large') || str_contains($message, 'file size')) {
                return self::error(
                    self::CODE_FILE_TOO_LARGE,
                    'File is too large. Please check the file size limit for your plan.',
                    413,
                    $context
                );
            }

            if (str_contains($message, 'does not exist in S3') || str_contains($message, 'not found in S3')) {
                return self::error(
                    self::CODE_FILE_MISSING,
                    'Upload file not found in storage. The upload may have expired.',
                    404,
                    $context
                );
            }

            // Generic validation/business logic errors (400)
            return self::error(
                self::CODE_VALIDATION_FAILED,
                $message,
                400,
                $context
            );
        }

        // Generic exception - unknown error
        // Never expose internal error details to users
        $userMessage = 'An unexpected error occurred. Please try again.';
        
        // In development, include more details
        if (config('app.debug')) {
            $userMessage = 'Server error: ' . $message;
        }

        return self::error(
            self::CODE_SERVER_ERROR,
            $userMessage,
            $httpStatus,
            $context
        );
    }

    /**
     * Extract file type from filename or upload session
     * 
     * @param string|null $filename
     * @param \App\Models\UploadSession|null $uploadSession
     * @return string|null File extension (e.g., 'pdf', 'jpg') or null
     */
    public static function extractFileType(?string $filename, ?\App\Models\UploadSession $uploadSession = null): ?string
    {
        if ($filename) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext) {
                return strtolower($ext);
            }
        }

        if ($uploadSession && $uploadSession->file_name) {
            $ext = pathinfo($uploadSession->file_name, PATHINFO_EXTENSION);
            if ($ext) {
                return strtolower($ext);
            }
        }

        return null;
    }

    /**
     * Map error code to category
     * 
     * @param string $errorCode
     * @return string Category constant
     */
    public static function getCategoryFromErrorCode(string $errorCode): string
    {
        // Auth-related codes
        if (in_array($errorCode, [
            self::CODE_AUTH_EXPIRED,
            self::CODE_AUTH_REQUIRED,
            self::CODE_PERMISSION_DENIED,
        ])) {
            return self::CATEGORY_AUTH;
        }

        // Validation-related codes
        if (in_array($errorCode, [
            self::CODE_FILE_TOO_LARGE,
            self::CODE_VALIDATION_FAILED,
            self::CODE_FINALIZE_VALIDATION_FAILED,
        ])) {
            return self::CATEGORY_VALIDATION;
        }

        // Pipeline-related codes
        if (in_array($errorCode, [
            self::CODE_PIPELINE_CONFLICT,
            self::CODE_PIPELINE_TERMINAL,
            self::CODE_SESSION_NOT_FOUND,
            self::CODE_SESSION_EXPIRED,
            self::CODE_SESSION_INVALID,
            self::CODE_FILE_MISSING,
        ])) {
            return self::CATEGORY_PIPELINE;
        }

        // Network/server errors
        if ($errorCode === self::CODE_SERVER_ERROR) {
            return self::CATEGORY_NETWORK;
        }

        // Default to UNKNOWN
        return self::CATEGORY_UNKNOWN;
    }
}

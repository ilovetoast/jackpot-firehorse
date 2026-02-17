/**
 * 🔒 Phase 2.5 — Observability Layer (LOCKED)
 * This file is part of a locked phase. Do not refactor or change behavior.
 * Future phases may consume emitted signals only.
 * 
 * Phase 2.5 - Step 1: Upload Error Normalization Utility
 * 
 * Normalizes upload errors into a consistent, AI-ready format.
 * Every failed upload produces a normalized error object that is:
 * - Human-readable for users
 * - Machine-readable for AI agents
 * - Consistent across the upload flow
 * 
 * This utility centralizes error normalization to ensure all upload errors
 * follow the same structure, making them suitable for:
 * - User-facing error messages
 * - AI agent processing
 * - Future error aggregation and analytics
 * - Support ticket generation (future)
 * 
 * IMPORTANT: Error signals preserved for AI agents:
 * - upload_session_id
 * - file_type
 * - category
 * - error_code
 * These fields enable pattern detection like:
 * - "Company X had 5 failed uploads in 1 hour"
 * - "All PDFs are failing thumbnail generation"
 */

import { allowDiagnostics } from './environment' // Phase 2.5 Step 4: Centralized environment detection

/** Format bytes for display (KB, MB, GB) — used when backend sends raw bytes in error messages */
function formatBytesForDisplay(bytes) {
    if (bytes >= 1024 * 1024 * 1024) return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB'
    if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(2) + ' MB'
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB'
    return bytes + ' B'
}

/** Replace raw byte numbers in file-size error messages with human-readable format */
function humanizeBytesInMessage(message) {
    if (!message || typeof message !== 'string') return message
    return message.replace(/(\d+)\s+bytes/gi, (_, n) => formatBytesForDisplay(parseInt(n, 10)))
}

/**
 * Normalized error shape for upload failures
 * 
 * @typedef {Object} NormalizedUploadError
 * @property {string} category - Error category: "AUTH" | "CORS" | "NETWORK" | "VALIDATION" | "PIPELINE" | "UNKNOWN"
 * @property {string} error_code - Machine-readable error code (e.g., "UPLOAD_AUTH_EXPIRED")
 * @property {string} message - Human-readable error message
 * @property {number|undefined} http_status - HTTP status code if applicable
 * @property {string|null} upload_session_id - Upload session ID for tracking
 * @property {string|null} asset_id - Asset ID if asset was created before failure (null otherwise)
 * @property {string} file_name - Original file name
 * @property {string} file_type - File extension/type (e.g., "pdf", "jpg")
 * @property {boolean} retryable - Whether this error is retryable
 * @property {Object} raw - Original error payload (dev only, for debugging)
 */

/**
 * Normalize an upload error into the standard format
 * 
 * Maps common failure modes to categories:
 * - 401 / 419 → AUTH
 * - 403 → AUTH / PERMISSION (context-dependent)
 * - Network failure / timeout → NETWORK
 * - CORS blocked → CORS
 * - 422 → VALIDATION
 * - 409 / 423 → PIPELINE
 * - Everything else → UNKNOWN
 * 
 * @param {Error|Response|any} error - The error to normalize
 * @param {Object} context - Additional context about the error
 * @param {number} [context.httpStatus] - HTTP status code if available
 * @param {string} [context.uploadSessionId] - Upload session ID
 * @param {string} [context.fileName] - File name
 * @param {File} [context.file] - File object (for type detection)
 * @param {string} [context.fileType] - File type/extension (if file object not available)
 * @param {string} [context.stage] - Upload stage: 'upload' | 'validation' | 'finalize'
 * @param {string|null} [context.assetId] - Asset ID if created before failure
 * @returns {NormalizedUploadError} Normalized error object
 */
export function normalizeUploadError(error, context = {}) {
    const {
        httpStatus = null,
        uploadSessionId = null,
        fileName = null,
        file = null,
        fileType = null,
        stage = 'unknown',
        assetId = null,
    } = context;

    // Extract file type from file object or use provided fileType
    let resolvedFileType = fileType;
    if (!resolvedFileType && file) {
        if (file.type) {
            // Extract extension from MIME type (e.g., "application/pdf" -> "pdf")
            const mimeParts = file.type.split('/');
            if (mimeParts.length === 2) {
                resolvedFileType = mimeParts[1];
            }
        }
        if (!resolvedFileType && file.name) {
            // Fallback to extension from filename
            const extMatch = file.name.match(/\.([^.]+)$/);
            if (extMatch) {
                resolvedFileType = extMatch[1].toLowerCase();
            }
        }
    }
    if (!resolvedFileType && fileName) {
        // Last resort: extract from filename
        const extMatch = fileName.match(/\.([^.]+)$/);
        if (extMatch) {
            resolvedFileType = extMatch[1].toLowerCase();
        }
    }
    resolvedFileType = resolvedFileType || 'unknown';

    // Extract error message — prefer API response body when available (e.g. 403 plan limit with formatted message)
    let errorMessage = 'Unknown upload error';
    if (error?.response?.data?.message) {
        errorMessage = error.response.data.message;
    } else if (error instanceof Error) {
        errorMessage = error.message;
    } else if (typeof error === 'string') {
        errorMessage = error;
    } else if (error?.message) {
        errorMessage = error.message;
    } else if (error?.error) {
        errorMessage = String(error.error);
    }

    // Extract HTTP status from error if not provided in context
    let resolvedHttpStatus = httpStatus;
    if (!resolvedHttpStatus && error?.response?.status) {
        resolvedHttpStatus = error.response.status;
    } else if (!resolvedHttpStatus && error?.status) {
        resolvedHttpStatus = error.status;
    } else if (error instanceof Response) {
        resolvedHttpStatus = error.status;
    }

    // Determine category, error code, message, and retryability
    let category = 'UNKNOWN';
    let errorCode = 'UPLOAD_UNKNOWN_ERROR';
    let userMessage = errorMessage;
    let retryable = false;

    // CORS errors: fetch throws TypeError, no response
    if (
        error instanceof TypeError &&
        (error.message.includes('fetch') ||
         error.message.includes('Failed to fetch') ||
         error.message.includes('NetworkError') ||
         error.message.includes('CORS'))
    ) {
        category = 'CORS';
        errorCode = 'UPLOAD_CORS_BLOCKED';
        userMessage = 'Upload blocked by browser security (CORS). This is typically a development environment configuration issue.';
        retryable = false;
    }
    // Network errors: timeout, connection refused, offline
    else if (
        error.name === 'AbortError' ||
        error.name === 'NetworkError' ||
        errorMessage.includes('timeout') ||
        errorMessage.includes('ECONNREFUSED') ||
        errorMessage.includes('offline') ||
        (!navigator.onLine && errorMessage.includes('fetch'))
    ) {
        category = 'NETWORK';
        errorCode = 'UPLOAD_NETWORK_FAILURE';
        userMessage = 'Network connection failed. Please check your internet connection and try again.';
        retryable = true;
    }
    // HTTP status-based classification
    else if (resolvedHttpStatus !== null) {
        if (resolvedHttpStatus === 401 || resolvedHttpStatus === 419) {
            // 401 Unauthorized or 419 CSRF Token Mismatch (session expired)
            category = 'AUTH';
            errorCode = resolvedHttpStatus === 419 ? 'UPLOAD_AUTH_EXPIRED' : 'UPLOAD_AUTH_REQUIRED';
            userMessage = resolvedHttpStatus === 419
                ? 'Your session expired. Please refresh and try again.'
                : 'Authentication required. Please log in and try again.';
            retryable = false; // Requires user action (refresh/login)
        } else if (resolvedHttpStatus === 403) {
            // 403 Forbidden - auth/permission OR plan limit (file size, storage)
            if (errorMessage.includes('file size') || errorMessage.includes('exceeds') || errorMessage.includes('storage limit')) {
                category = 'VALIDATION';
                errorCode = 'UPLOAD_FILE_TOO_LARGE';
                userMessage = humanizeBytesInMessage(errorMessage);
                retryable = false;
            } else {
                category = 'AUTH';
                errorCode = 'UPLOAD_PERMISSION_DENIED';
                userMessage = 'Upload permission denied. Please check your account permissions.';
                retryable = false;
            }
        } else if (resolvedHttpStatus === 404) {
            // 404 Not Found - could be expired session or invalid endpoint
            category = stage === 'finalize' ? 'PIPELINE' : 'UNKNOWN';
            errorCode = 'UPLOAD_SESSION_NOT_FOUND';
            userMessage = 'Upload session not found. The session may have expired.';
            retryable = false;
        } else if (resolvedHttpStatus === 409 || resolvedHttpStatus === 423) {
            // 409 Conflict or 423 Locked - pipeline/resource conflicts
            category = 'PIPELINE';
            errorCode = resolvedHttpStatus === 409 ? 'UPLOAD_CONFLICT' : 'UPLOAD_LOCKED';
            userMessage = resolvedHttpStatus === 409
                ? 'Upload conflict. The file may already exist or the session is invalid.'
                : 'Upload is locked. Another operation may be in progress.';
            retryable = true;
        } else if (resolvedHttpStatus === 413) {
            // 413 Payload Too Large
            category = 'VALIDATION';
            errorCode = 'UPLOAD_FILE_TOO_LARGE';
            userMessage = 'File is too large. Please check the file size limit for your plan.';
            retryable = false;
        } else if (resolvedHttpStatus === 422) {
            // 422 Unprocessable Entity (validation errors)
            category = 'VALIDATION';
            errorCode = 'UPLOAD_VALIDATION_FAILED';
            userMessage = errorMessage.includes('validation') 
                ? errorMessage 
                : 'File validation failed. Please check the file type and size.';
            retryable = false;
        } else if (resolvedHttpStatus >= 400 && resolvedHttpStatus < 500) {
            // Other 4xx client errors
            category = 'VALIDATION';
            errorCode = `UPLOAD_CLIENT_ERROR_${resolvedHttpStatus}`;
            userMessage = userMessage || `Upload failed: HTTP ${resolvedHttpStatus}`;
            retryable = false;
        } else if (resolvedHttpStatus >= 500) {
            // 5xx server errors
            category = 'NETWORK'; // Server issues are network-level from client perspective
            errorCode = 'UPLOAD_SERVER_ERROR';
            userMessage = 'Upload server error. Please try again in a moment.';
            retryable = true;
        }
    }
    // Stage-specific error patterns
    else if (stage === 'finalize') {
        // Finalize stage errors are typically pipeline issues
        category = 'PIPELINE';
        errorCode = 'UPLOAD_FINALIZE_FAILED';
        if (errorMessage.includes('validation') || errorMessage.includes('invalid')) {
            category = 'VALIDATION';
            errorCode = 'UPLOAD_FINALIZE_VALIDATION_FAILED';
        }
        retryable = false; // Finalize failures usually require starting over
    }
    // S3-specific error patterns
    else if (
        errorMessage.includes('ExpiredToken') ||
        errorMessage.includes('RequestTimeTooSkewed') ||
        errorMessage.includes('SignatureDoesNotMatch') ||
        errorMessage.includes('InvalidAccessKeyId') ||
        errorMessage.includes('AccessDenied') ||
        errorMessage.includes('NoSuchBucket') ||
        errorMessage.includes('NoSuchKey') ||
        errorMessage.includes('does not exist in S3') ||
        errorMessage.includes('expired') && errorMessage.includes('session')
    ) {
        category = 'PIPELINE'; // S3/storage issues are pipeline-level
        errorCode = 'UPLOAD_STORAGE_ERROR';
        userMessage = errorMessage.includes('expired') || errorMessage.includes('does not exist')
            ? 'Upload session expired. Please start a new upload.'
            : 'Storage error. The upload link may be invalid.';
        retryable = false;
    }
    // Validation error patterns
    else if (
        errorMessage.includes('too large') ||
        errorMessage.includes('file size') ||
        errorMessage.includes('exceeds') ||
        errorMessage.includes('unsupported') ||
        errorMessage.includes('invalid type')
    ) {
        category = 'VALIDATION';
        errorCode = 'UPLOAD_FILE_VALIDATION_FAILED';
        userMessage = humanizeBytesInMessage(errorMessage);
        retryable = false;
    }

    // Build normalized error object
    const normalized = {
        category,
        error_code: errorCode,
        message: userMessage,
        http_status: resolvedHttpStatus || undefined,
        upload_session_id: uploadSessionId || null,
        asset_id: assetId || null,
        file_name: fileName || 'unknown',
        file_type: resolvedFileType,
        retryable,
        // Raw error payload (dev only) - preserve original for debugging
        // Phase 2.5 Step 4: Use centralized environment detection
        raw: allowDiagnostics() 
            ? { 
                originalError: error,
                originalMessage: errorMessage,
                stage,
                context: {
                    httpStatus: resolvedHttpStatus,
                    uploadSessionId,
                    fileName,
                    fileType: resolvedFileType,
                },
            }
            : {}, // Empty object in production
    };

    // Dev-only logging: console.debug normalized error
    // Phase 2.5 Step 4: Use centralized environment detection - no prod logging noise
    if (allowDiagnostics()) {
        console.debug('[Upload Error Normalized]', {
            category: normalized.category,
            error_code: normalized.error_code,
            message: normalized.message,
            file_name: normalized.file_name,
            file_type: normalized.file_type,
            retryable: normalized.retryable,
            upload_session_id: normalized.upload_session_id,
        });
    }

    return normalized;
}

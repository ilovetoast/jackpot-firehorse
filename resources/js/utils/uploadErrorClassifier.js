/**
 * Phase 2.5: Upload Error Classifier
 * 
 * Classifies upload failures into structured categories for better diagnostics.
 * This utility helps identify WHY an upload failed (auth, CORS, network, S3, validation).
 * 
 * Error Categories:
 * - auth: Authentication/authorization failures (401, 403)
 * - cors: CORS/preflight blocked by browser
 * - network: Network failures (timeout, offline, connection refused)
 * - s3: S3-specific errors (expired URL, invalid signature, bucket issues)
 * - validation: File validation errors (too large, unsupported type)
 * - unknown: Unclassified errors
 */

import { allowDiagnostics } from './environment' // Phase 2.5 Step 4: Centralized environment detection

/**
 * Classify an upload error into a structured format
 * 
 * @param {Error|Response|any} error - The error to classify
 * @param {Object} context - Additional context
 * @param {number} [context.httpStatus] - HTTP status code if available
 * @param {string} [context.requestPhase] - Request phase ('initiate', 'upload', 'complete', 'finalize')
 * @param {string} [context.uploadSessionId] - Upload session ID
 * @param {string} [context.fileName] - File name
 * @param {number} [context.fileSize] - File size in bytes
 * @param {string} [context.presignedUrl] - Presigned URL (for expiration detection)
 * @returns {Object} Structured error payload
 */
export function classifyUploadError(error, context = {}) {
    const {
        httpStatus = null,
        requestPhase = 'unknown',
        uploadSessionId = null,
        fileName = null,
        fileSize = null,
        presignedUrl = null,
    } = context;

    let type = 'unknown';
    let message = 'Unknown upload error';
    let details = {};

    // Extract error message
    if (error instanceof Error) {
        message = error.message;
    } else if (typeof error === 'string') {
        message = error;
    } else if (error?.message) {
        message = error.message;
    }

    // CORS errors: fetch throws TypeError, no response, network error
    if (error instanceof TypeError && (
        error.message.includes('fetch') ||
        error.message.includes('Failed to fetch') ||
        error.message.includes('NetworkError') ||
        error.message.includes('CORS')
    )) {
        type = 'cors';
        message = 'Upload blocked by browser security (CORS). This is typically a development environment configuration issue.';
        details = {
            originalMessage: error.message,
            suggestion: 'Check CORS configuration on backend and ensure presigned URLs are valid',
        };
    }
    // Network errors: AbortError, connection refused, timeout, offline
    else if (
        error.name === 'AbortError' ||
        error.name === 'NetworkError' ||
        error.message.includes('timeout') ||
        error.message.includes('ECONNREFUSED') ||
        error.message.includes('offline') ||
        (!navigator.onLine && error.message.includes('fetch'))
    ) {
        type = 'network';
        message = 'Network connection failed. Please check your internet connection and try again.';
        details = {
            originalMessage: error.message,
            isOffline: !navigator.onLine,
        };
    }
    // HTTP status-based classification
    else if (httpStatus !== null) {
        // 401 Unauthorized
        if (httpStatus === 401) {
            type = 'auth';
            message = 'Authentication required. Please log in and try again.';
            details = { httpStatus };
        }
        // 403 Forbidden - could be auth or expired URL
        else if (httpStatus === 403) {
            // Check if it's an S3 error response (XML)
            const isS3Error = error instanceof Response || 
                             (error.body && typeof error.body === 'string' && error.body.includes('<?xml')) ||
                             (error.text && typeof error.text === 'function');
            
            // Check if presigned URL is expired
            const isExpired = presignedUrl && checkUrlExpiration(presignedUrl);
            
            if (isS3Error || isExpired) {
                type = 's3';
                message = 'Upload link expired or invalid. Please retry the upload.';
                details = {
                    httpStatus,
                    reason: isExpired ? 'url_expired' : 's3_error',
                    suggestion: 'The presigned URL may have expired. The upload will be retried automatically.',
                };
            } else {
                type = 'auth';
                message = 'Upload permission denied. Please check your account permissions.';
                details = { httpStatus };
            }
        }
        // 404 Not Found
        else if (httpStatus === 404) {
            type = 's3';
            message = 'Upload endpoint not found. The upload session may have expired.';
            details = {
                httpStatus,
                suggestion: 'The upload session may have expired. Please start a new upload.',
            };
        }
        // 413 Payload Too Large
        else if (httpStatus === 413) {
            type = 'validation';
            message = 'File is too large. Please check the file size limit for your plan.';
            details = {
                httpStatus,
                fileSize,
                suggestion: 'Check your plan limits or compress the file.',
            };
        }
        // 422 Unprocessable Entity (validation errors)
        else if (httpStatus === 422) {
            type = 'validation';
            message = 'File validation failed. Please check the file type and size.';
            details = {
                httpStatus,
                fileName,
                fileSize,
                suggestion: 'Check that the file type is supported and within size limits.',
            };
        }
        // Other 4xx client errors
        else if (httpStatus >= 400 && httpStatus < 500) {
            type = 'validation';
            message = `Upload failed: ${message || `HTTP ${httpStatus}`}`;
            details = { httpStatus };
        }
        // 5xx server errors
        else if (httpStatus >= 500) {
            type = 'network';
            message = 'Upload server error. Please try again in a moment.';
            details = {
                httpStatus,
                suggestion: 'This is a server-side error. Please retry the upload.',
            };
        }
    }
    // S3-specific error patterns
    else if (
        message.includes('ExpiredToken') ||
        message.includes('RequestTimeTooSkewed') ||
        message.includes('SignatureDoesNotMatch') ||
        message.includes('InvalidAccessKeyId') ||
        message.includes('AccessDenied') ||
        message.includes('NoSuchBucket') ||
        message.includes('NoSuchKey')
    ) {
        type = 's3';
        message = 'S3 upload error. The upload link may be invalid or expired.';
        details = {
            originalMessage: message,
            suggestion: 'The S3 upload link may have expired. Please retry the upload.',
        };
    }
    // Validation error patterns
    else if (
        message.includes('too large') ||
        message.includes('file size') ||
        message.includes('exceeds') ||
        message.includes('unsupported') ||
        message.includes('invalid type')
    ) {
        type = 'validation';
        message = `File validation error: ${message}`;
        details = {
            originalMessage: message,
            fileName,
            fileSize,
        };
    }

    // Build structured payload
    return {
        type,
        message,
        http_status: httpStatus || undefined,
        request_phase: requestPhase,
        upload_session_id: uploadSessionId || undefined,
        file_name: fileName || undefined,
        file_size: fileSize || undefined,
        details,
        timestamp: new Date().toISOString(),
        user_agent: navigator.userAgent,
        is_online: navigator.onLine,
    };
}

/**
 * Check if a presigned URL is expired
 * 
 * @param {string} url - Presigned URL to check
 * @returns {boolean} True if URL appears to be expired
 */
function checkUrlExpiration(url) {
    try {
        const urlObj = new URL(url);
        
        // Check X-Amz-Expires parameter
        const expiresParam = urlObj.searchParams.get('X-Amz-Expires');
        const dateParam = urlObj.searchParams.get('X-Amz-Date');
        
        if (expiresParam && dateParam) {
            // Parse X-Amz-Date (format: YYYYMMDDTHHMMSSZ)
            const dateMatch = dateParam.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/);
            if (dateMatch) {
                const [, year, month, day, hour, minute, second] = dateMatch;
                const urlDate = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}Z`);
                const expiresIn = parseInt(expiresParam, 10);
                const expirationTime = urlDate.getTime() + (expiresIn * 1000);
                
                // Check if expired (with 5 second buffer for clock skew)
                return Date.now() > (expirationTime - 5000);
            }
        }
        
        // Check Expires parameter (alternative format)
        const expiresParamAlt = urlObj.searchParams.get('Expires');
        if (expiresParamAlt) {
            const expirationTime = parseInt(expiresParamAlt, 10) * 1000;
            return Date.now() > (expirationTime - 5000);
        }
    } catch (e) {
        // If URL parsing fails, assume not expired (conservative)
        return false;
    }
    
    return false;
}

/**
 * Send diagnostics to backend
 * 
 * @param {Object} diagnosticPayload - Structured diagnostic payload
 * @returns {Promise<void>}
 */
export async function sendDiagnostics(diagnosticPayload) {
    try {
        // Phase 2.5 Step 4: Use centralized environment detection
        // Only send in development or if explicitly enabled
        if (!allowDiagnostics()) {
            // In production, silently skip diagnostics (don't log to console)
            return;
        }

        // Send to backend diagnostics endpoint
        const response = await fetch('/app/uploads/diagnostics', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(diagnosticPayload),
        });

        // Don't throw on diagnostics failure - it's best-effort
        if (!response.ok) {
            console.warn('[Upload Diagnostics] Failed to send diagnostics:', response.status);
        }
    } catch (error) {
        // Never throw from diagnostics - it's best-effort observability
        console.warn('[Upload Diagnostics] Error sending diagnostics:', error);
    }
}

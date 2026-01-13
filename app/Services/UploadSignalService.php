<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Phase 2.65: Upload Signal Emission Service
 * 
 * PURPOSE:
 * Emits normalized, machine-consumable "upload incident signals" for future AI-based
 * pattern detection and internal ticketing. Signals are passive data only - they
 * do NOT trigger alerts, notifications, or automation.
 * 
 * DESIGN PRINCIPLES:
 * - Signals are append-only records of upload errors/abnormal conditions
 * - Signals are designed for AI correlation, not human alerts
 * - Signal emission is best-effort and never throws exceptions
 * - No thresholds, heuristics, or automation belong in this service
 * 
 * SIGNAL STRUCTURE:
 * {
 *   "signal_type": "upload_error",
 *   "error_type": "auth|cors|network|s3|validation|unknown",
 *   "request_phase": "initiate|direct_upload|multipart_init|multipart_upload_part|multipart_complete",
 *   "tenant_id": int,
 *   "upload_session_id": string|null,
 *   "file_extension": string|null,
 *   "file_size_bucket": "<50MB|50-100MB|100-500MB|500MB+|unknown",
 *   "http_status": int|null,
 *   "environment": "production|staging|local|testing",
 *   "occurred_at": "2026-01-10T12:00:00Z"
 * }
 * 
 * STORAGE:
 * Signals are emitted to structured logs (Laravel's Log facade) with a dedicated
 * channel tag "[Upload Signal]" for easy filtering. This is the minimal viable
 * storage mechanism - no database tables or event dispatchers are used.
 * 
 * FUTURE USE:
 * - AI agents can analyze signal patterns across tenants/time
 * - Automated ticket creation based on signal correlation (implemented elsewhere)
 * - Trend analysis for upload reliability metrics
 * - Root cause analysis for recurring error patterns
 */
class UploadSignalService
{
    /**
     * Emit an upload error signal.
     * 
     * This method normalizes upload error data into a machine-consumable signal
     * format and logs it. Signal emission is best-effort and never throws.
     * 
     * @param array $data Raw error data from diagnostics or error handling
     * @param \App\Models\Tenant|null $tenant Current tenant (auto-resolved if null)
     * @return void
     */
    public function emitErrorSignal(array $data, ?\App\Models\Tenant $tenant = null): void
    {
        try {
            // Resolve tenant if not provided
            if (!$tenant) {
                $tenant = app('tenant');
            }

            // Normalize signal payload
            $signal = $this->normalizeSignal($data, $tenant);

            // Emit to structured logs with dedicated tag for filtering
            Log::channel('single')->info('[Upload Signal] Upload error signal emitted', [
                'signal' => $signal,
            ]);
        } catch (\Exception $e) {
            // Signal emission is best-effort - never throw
            // Silently fail to prevent disrupting upload flow
            Log::debug('[Upload Signal] Failed to emit signal (non-critical)', [
                'error' => $e->getMessage(),
                'original_data' => $data,
            ]);
        }
    }

    /**
     * Normalize raw error data into signal structure.
     * 
     * @param array $data Raw error data
     * @param \App\Models\Tenant|null $tenant Current tenant
     * @return array Normalized signal payload
     */
    protected function normalizeSignal(array $data, ?\App\Models\Tenant $tenant): array
    {
        // Extract error type (normalize from various sources)
        $errorType = $this->extractErrorType($data);
        
        // Extract request phase (normalize from various sources)
        $requestPhase = $this->extractRequestPhase($data);
        
        // Extract file extension
        $fileExtension = $this->extractFileExtension($data);
        
        // Extract file size bucket
        $fileSizeBucket = $this->extractFileSizeBucket($data);
        
        // Build normalized signal
        return [
            'signal_type' => 'upload_error',
            'error_type' => $errorType,
            'request_phase' => $requestPhase,
            'tenant_id' => $tenant?->id,
            'upload_session_id' => $data['upload_session_id'] ?? $data['uploadSessionId'] ?? null,
            'file_extension' => $fileExtension,
            'file_size_bucket' => $fileSizeBucket,
            'http_status' => $data['http_status'] ?? $data['httpStatus'] ?? null,
            'environment' => config('app.env'),
            'occurred_at' => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * Extract and normalize error type.
     * 
     * @param array $data Raw error data
     * @return string Normalized error type
     */
    protected function extractErrorType(array $data): string
    {
        // Check for explicit error_type
        if (isset($data['error_type'])) {
            return $this->normalizeErrorType($data['error_type']);
        }
        
        // Check for type field (from uploadErrorClassifier)
        if (isset($data['type'])) {
            return $this->normalizeErrorType($data['type']);
        }
        
        // Infer from HTTP status
        $httpStatus = $data['http_status'] ?? $data['httpStatus'] ?? null;
        if ($httpStatus) {
            if ($httpStatus === 401 || $httpStatus === 403) {
                return 'auth';
            }
            if ($httpStatus >= 400 && $httpStatus < 500) {
                return 'validation';
            }
            if ($httpStatus >= 500) {
                return 's3'; // Assume S3/server error for 5xx
            }
        }
        
        // Default to unknown
        return 'unknown';
    }

    /**
     * Normalize error type to allowed values.
     * 
     * @param string $errorType Raw error type
     * @return string Normalized error type
     */
    protected function normalizeErrorType(string $errorType): string
    {
        $normalized = strtolower(trim($errorType));
        
        $allowedTypes = ['auth', 'cors', 'network', 's3', 'validation', 'unknown'];
        
        if (in_array($normalized, $allowedTypes)) {
            return $normalized;
        }
        
        // Map common variations
        $mapping = [
            'authentication' => 'auth',
            'authorization' => 'auth',
            'cors_error' => 'cors',
            'network_error' => 'network',
            'timeout' => 'network',
            's3_error' => 's3',
            'aws_error' => 's3',
            'validation_error' => 'validation',
            'invalid' => 'validation',
        ];
        
        return $mapping[$normalized] ?? 'unknown';
    }

    /**
     * Extract and normalize request phase.
     * 
     * @param array $data Raw error data
     * @return string Normalized request phase
     */
    protected function extractRequestPhase(array $data): string
    {
        // Check for explicit request_phase
        if (isset($data['request_phase'])) {
            return $this->normalizeRequestPhase($data['request_phase']);
        }
        
        // Check for requestPhase (camelCase from frontend)
        if (isset($data['requestPhase'])) {
            return $this->normalizeRequestPhase($data['requestPhase']);
        }
        
        // Infer from context
        if (isset($data['upload_phase'])) {
            return $this->normalizeRequestPhase($data['upload_phase']);
        }
        
        // Default to unknown phase
        return 'unknown';
    }

    /**
     * Normalize request phase to allowed values.
     * 
     * @param string $phase Raw request phase
     * @return string Normalized request phase
     */
    protected function normalizeRequestPhase(string $phase): string
    {
        $normalized = strtolower(trim($phase));
        
        $allowedPhases = [
            'initiate',
            'direct_upload',
            'multipart_init',
            'multipart_upload_part',
            'multipart_complete',
            'unknown',
        ];
        
        if (in_array($normalized, $allowedPhases)) {
            return $normalized;
        }
        
        // Map common variations
        $mapping = [
            'init' => 'initiate',
            'initiation' => 'initiate',
            'direct' => 'direct_upload',
            'upload' => 'direct_upload',
            'multipart_initiate' => 'multipart_init',
            'multipart_part' => 'multipart_upload_part',
            'multipart_part_upload' => 'multipart_upload_part',
            'multipart_finalize' => 'multipart_complete',
            'multipart_completion' => 'multipart_complete',
        ];
        
        return $mapping[$normalized] ?? 'unknown';
    }

    /**
     * Extract file extension from file name.
     * 
     * @param array $data Raw error data
     * @return string|null File extension (without dot) or null
     */
    protected function extractFileExtension(array $data): ?string
    {
        $fileName = $data['file_name'] ?? $data['fileName'] ?? null;
        
        if (!$fileName) {
            return null;
        }
        
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        
        return $extension ? strtolower($extension) : null;
    }

    /**
     * Extract file size bucket.
     * 
     * Buckets are coarse-grained for pattern analysis:
     * - <50MB: Small files
     * - 50-100MB: Medium files
     * - 100-500MB: Large files
     * - 500MB+: Very large files
     * 
     * @param array $data Raw error data
     * @return string File size bucket
     */
    protected function extractFileSizeBucket(array $data): string
    {
        $fileSize = $data['file_size'] ?? $data['fileSize'] ?? null;
        
        if (!$fileSize || !is_numeric($fileSize)) {
            return 'unknown';
        }
        
        $sizeBytes = (int) $fileSize;
        
        if ($sizeBytes < 50 * 1024 * 1024) {
            return '<50MB';
        }
        
        if ($sizeBytes < 100 * 1024 * 1024) {
            return '50-100MB';
        }
        
        if ($sizeBytes < 500 * 1024 * 1024) {
            return '100-500MB';
        }
        
        return '500MB+';
    }
}

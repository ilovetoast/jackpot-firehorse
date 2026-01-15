/**
 * 🔒 Phase 2.5 — Observability Layer (LOCKED)
 * This file is part of a locked phase. Do not refactor or change behavior.
 * Future phases may consume emitted signals only.
 * 
 * Phase 2.5 Step 3: Dev-Only Upload Diagnostics Panel
 * 
 * Read-only diagnostics panel for developers to inspect upload sessions,
 * file-level status, and normalized errors. This panel is:
 * - Read-only (no mutations, no actions)
 * - Developer-facing only (hidden in production)
 * - Displays normalized error information from Step 1 & Step 2
 * 
 * AI-SUPPORT INTENT:
 * This panel makes it obvious how AI agents could later:
 * - Detect repeated failures (group by error_code)
 * - Group by file type (file_type field)
 * - Group by pipeline stage (pipeline_stage field)
 * - Identify retryable vs non-retryable errors
 * 
 * @param {Object} props
 * @param {Array} props.files - Array of v2Files with normalized errors
 */
import { useState, useMemo } from 'react'
import { ChevronDownIcon, ChevronUpIcon, InformationCircleIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline'
import { allowDiagnostics } from '../utils/environment' // Phase 2.5 Step 4: Centralized environment detection

/**
 * Format file size for display
 */
function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 Bytes'
    const k = 1024
    const sizes = ['Bytes', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

/**
 * Get file extension from filename
 */
function getFileType(filename) {
    if (!filename) return null
    const lastDot = filename.lastIndexOf('.')
    if (lastDot === -1 || lastDot === filename.length - 1) {
        return null
    }
    return filename.substring(lastDot + 1).toLowerCase()
}

/**
 * DevUploadDiagnostics - Dev-only panel showing upload diagnostic information
 * 
 * Displays normalized error information from upload files state.
 * Shows upload_session_id, file_name, file_type, status, and normalized errors.
 */
export default function DevUploadDiagnostics({ files = [] }) {
    const [isExpanded, setIsExpanded] = useState(false)

    // Phase 2.5 Step 4: Use centralized environment detection - never show in production
    const isDev = allowDiagnostics()

    // Extract diagnostic information from files
    // Phase 2.5 Step 3: Process v2Files to extract normalized error data
    const diagnostics = useMemo(() => {
        return files
            .map((file) => {
                // Extract normalized error if present
                const normalizedError = file.error?.normalized || null
                
                // Extract file type
                const fileType = normalizedError?.file_type || 
                                getFileType(file.file?.name) || 
                                getFileType(file.originalFilename) ||
                                'unknown'

                return {
                    clientId: file.clientId,
                    fileName: file.file?.name || file.originalFilename || 'unknown',
                    fileType: fileType,
                    fileSize: file.file?.size || null,
                    status: file.status,
                    uploadSessionId: file.uploadSessionId || normalizedError?.upload_session_id || null,
                    normalizedError: normalizedError,
                    // Legacy error format (for backward compatibility)
                    legacyError: file.error,
                    // Timestamps (if available)
                    addedAt: file.addedAt || null,
                }
            })
            .filter((diag) => {
                // Only show files with errors or in non-success states for diagnostics
                return diag.normalizedError || 
                       diag.status === 'failed' || 
                       diag.status === 'uploading' ||
                       diag.status === 'finalizing'
            })
    }, [files])

    // Don't render in production
    if (!isDev) {
        return null
    }

    // Don't render if no diagnostic data
    if (diagnostics.length === 0) {
        return null
    }

    return (
        <div className="mt-4 border border-amber-300 rounded-lg bg-amber-50">
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                className="w-full flex items-center justify-between px-4 py-2 text-left text-sm font-medium text-amber-900 hover:bg-amber-100 rounded-t-lg transition-colors"
            >
                <div className="flex items-center gap-2">
                    <InformationCircleIcon className="h-4 w-4 text-amber-600" />
                    <span className="font-semibold">Dev: Upload Diagnostics</span>
                    <span className="text-xs text-amber-700 bg-amber-200 px-2 py-0.5 rounded-full">
                        {diagnostics.length} {diagnostics.length === 1 ? 'item' : 'items'}
                    </span>
                </div>
                {isExpanded ? (
                    <ChevronUpIcon className="h-4 w-4 text-amber-600" />
                ) : (
                    <ChevronDownIcon className="h-4 w-4 text-amber-600" />
                )}
            </button>

            {isExpanded && (
                <div className="px-4 py-3 border-t border-amber-300 space-y-3 max-h-96 overflow-y-auto">
                    {diagnostics.map((diag, index) => (
                        <div 
                            key={diag.clientId || index} 
                            className="bg-white rounded p-3 border border-amber-200 shadow-sm"
                        >
                            {/* File Header */}
                            <div className="flex items-start justify-between mb-3">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 mb-1">
                                        {diag.normalizedError && (
                                            <ExclamationCircleIcon className="h-4 w-4 text-red-500 flex-shrink-0" />
                                        )}
                                        <div className="text-xs font-semibold text-gray-900 truncate">
                                            {diag.fileName}
                                        </div>
                                    </div>
                                    <div className="text-xs text-gray-500">
                                        {diag.fileType && (
                                            <span className="inline-block mr-2">
                                                Type: <span className="font-mono">{diag.fileType}</span>
                                            </span>
                                        )}
                                        {diag.fileSize && (
                                            <span className="inline-block mr-2">
                                                Size: {formatFileSize(diag.fileSize)}
                                            </span>
                                        )}
                                        <span className="inline-block">
                                            Status: <span className="font-semibold">{diag.status}</span>
                                        </span>
                                    </div>
                                </div>
                                {diag.normalizedError?.category && (
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium flex-shrink-0 ml-2 ${
                                        diag.normalizedError.category === 'AUTH' ? 'bg-red-100 text-red-700' :
                                        diag.normalizedError.category === 'CORS' ? 'bg-orange-100 text-orange-700' :
                                        diag.normalizedError.category === 'NETWORK' ? 'bg-yellow-100 text-yellow-700' :
                                        diag.normalizedError.category === 'VALIDATION' ? 'bg-blue-100 text-blue-700' :
                                        diag.normalizedError.category === 'PIPELINE' ? 'bg-purple-100 text-purple-700' :
                                        'bg-gray-100 text-gray-700'
                                    }`}>
                                        {diag.normalizedError.category}
                                    </span>
                                )}
                            </div>

                            {/* Normalized Error Information */}
                            {diag.normalizedError && (
                                <div className="mt-3 pt-3 border-t border-gray-200">
                                    <div className="text-xs font-semibold text-gray-700 mb-2">
                                        Normalized Error (AI-Ready)
                                    </div>
                                    <dl className="grid grid-cols-1 gap-2 text-xs">
                                        {diag.normalizedError.error_code && (
                                            <>
                                                <dt className="font-medium text-gray-500">Error Code:</dt>
                                                <dd className="text-gray-900 font-mono break-all">
                                                    {diag.normalizedError.error_code}
                                                </dd>
                                            </>
                                        )}
                                        {diag.normalizedError.message && (
                                            <>
                                                <dt className="font-medium text-gray-500">Message:</dt>
                                                <dd className="text-gray-900 break-words">
                                                    {diag.normalizedError.message}
                                                </dd>
                                            </>
                                        )}
                                        {diag.normalizedError.category && (
                                            <>
                                                <dt className="font-medium text-gray-500">Category:</dt>
                                                <dd className="text-gray-900 font-semibold">
                                                    {diag.normalizedError.category}
                                                </dd>
                                            </>
                                        )}
                                        {diag.normalizedError.pipeline_stage && (
                                            <>
                                                <dt className="font-medium text-gray-500">Pipeline Stage:</dt>
                                                <dd className="text-gray-900 font-mono">
                                                    {diag.normalizedError.pipeline_stage}
                                                </dd>
                                            </>
                                        )}
                                        {diag.normalizedError.retryable !== undefined && (
                                            <>
                                                <dt className="font-medium text-gray-500">Retryable:</dt>
                                                <dd className={diag.normalizedError.retryable ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                                    {diag.normalizedError.retryable ? 'Yes' : 'No'}
                                                </dd>
                                            </>
                                        )}
                                        {diag.normalizedError.http_status && (
                                            <>
                                                <dt className="font-medium text-gray-500">HTTP Status:</dt>
                                                <dd className={`font-mono ${
                                                    diag.normalizedError.http_status >= 400 ? 'text-red-600' : 'text-green-600'
                                                }`}>
                                                    {diag.normalizedError.http_status}
                                                </dd>
                                            </>
                                        )}
                                    </dl>
                                </div>
                            )}

                            {/* Context Information */}
                            <div className="mt-3 pt-3 border-t border-gray-200">
                                <div className="text-xs font-semibold text-gray-700 mb-2">
                                    Context (AI-Support Signals)
                                </div>
                                <dl className="grid grid-cols-1 gap-2 text-xs">
                                    {diag.uploadSessionId && (
                                        <>
                                            <dt className="font-medium text-gray-500">Upload Session ID:</dt>
                                            <dd className="text-gray-900 font-mono break-all text-xs">
                                                {diag.uploadSessionId}
                                            </dd>
                                        </>
                                    )}
                                    {diag.normalizedError?.upload_session_id && (
                                        <>
                                            <dt className="font-medium text-gray-500">Session ID (from error):</dt>
                                            <dd className="text-gray-900 font-mono break-all text-xs">
                                                {diag.normalizedError.upload_session_id}
                                            </dd>
                                        </>
                                    )}
                                    {diag.normalizedError?.file_type && (
                                        <>
                                            <dt className="font-medium text-gray-500">File Type (from error):</dt>
                                            <dd className="text-gray-900 font-mono">
                                                {diag.normalizedError.file_type}
                                            </dd>
                                        </>
                                    )}
                                    {diag.clientId && (
                                        <>
                                            <dt className="font-medium text-gray-500">Client ID:</dt>
                                            <dd className="text-gray-900 font-mono break-all text-xs">
                                                {diag.clientId}
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </div>

                            {/* AI-Support Intent Note */}
                            {diag.normalizedError && (
                                <div className="mt-3 pt-3 border-t border-gray-200">
                                    <div className="text-xs text-gray-500 italic">
                                        💡 AI agents can detect patterns: Group by error_code ({diag.normalizedError.error_code}), 
                                        file_type ({diag.normalizedError.file_type || diag.fileType}), 
                                        or pipeline_stage ({diag.normalizedError.pipeline_stage || 'unknown'})
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    )
}

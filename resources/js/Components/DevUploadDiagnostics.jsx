// Phase 2.5: Dev-only upload diagnostics panel
// Only visible when APP_ENV !== 'production' or window.__DEV_UPLOAD_DIAGNOSTICS__ === true

import { useState } from 'react'
import { ChevronDownIcon, ChevronUpIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

/**
 * DevUploadDiagnostics - Dev-only panel showing upload diagnostic information
 * 
 * This component is only visible in development environments.
 * It helps developers debug upload issues without modifying backend code.
 * 
 * Phase 2.5: Dev-only observability feature
 */
export default function DevUploadDiagnostics({ diagnostics = {} }) {
    const [isExpanded, setIsExpanded] = useState(false)

    // Check if diagnostics should be shown (dev-only)
    // Phase 2.5: Only visible in development or when explicitly enabled
    const isDev = typeof window !== 'undefined' && (
        window.__DEV_UPLOAD_DIAGNOSTICS__ === true ||
        (import.meta.env && import.meta.env.DEV) ||
        (import.meta.env && import.meta.env.MODE && import.meta.env.MODE !== 'production')
    )

    if (!isDev || !diagnostics || Object.keys(diagnostics).length === 0) {
        return null
    }

    return (
        <div className="mt-4 border border-gray-300 rounded-lg bg-gray-50">
            <button
                type="button"
                onClick={() => setIsExpanded(!isExpanded)}
                className="w-full flex items-center justify-between px-4 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-t-lg"
            >
                <div className="flex items-center gap-2">
                    <InformationCircleIcon className="h-4 w-4 text-gray-500" />
                    <span>Dev: Upload Diagnostics</span>
                </div>
                {isExpanded ? (
                    <ChevronUpIcon className="h-4 w-4 text-gray-500" />
                ) : (
                    <ChevronDownIcon className="h-4 w-4 text-gray-500" />
                )}
            </button>

            {isExpanded && (
                <div className="px-4 py-3 border-t border-gray-300 space-y-3">
                    {Object.entries(diagnostics).map(([fileId, diag]) => (
                        <div key={fileId} className="bg-white rounded p-3 border border-gray-200">
                            <div className="text-xs font-semibold text-gray-600 mb-2">
                                {fileId.substring(0, 8)}...
                            </div>
                            <dl className="grid grid-cols-1 gap-2 text-xs">
                                {diag.upload_session_id && (
                                    <>
                                        <dt className="font-medium text-gray-500">Upload Session ID:</dt>
                                        <dd className="font-mono text-gray-900 break-all">{diag.upload_session_id}</dd>
                                    </>
                                )}
                                {diag.s3_bucket && (
                                    <>
                                        <dt className="font-medium text-gray-500">S3 Bucket:</dt>
                                        <dd className="font-mono text-gray-900">{diag.s3_bucket}</dd>
                                    </>
                                )}
                                {diag.s3_key && (
                                    <>
                                        <dt className="font-medium text-gray-500">S3 Key:</dt>
                                        <dd className="font-mono text-gray-900 break-all">{diag.s3_key}</dd>
                                    </>
                                )}
                                {diag.part_number && (
                                    <>
                                        <dt className="font-medium text-gray-500">Part Number:</dt>
                                        <dd className="text-gray-900">{diag.part_number}</dd>
                                    </>
                                )}
                                {diag.multipart_upload_id && (
                                    <>
                                        <dt className="font-medium text-gray-500">Multipart Upload ID:</dt>
                                        <dd className="font-mono text-gray-900 break-all text-xs">{diag.multipart_upload_id}</dd>
                                    </>
                                )}
                                {diag.presigned_url_expires_at && (
                                    <>
                                        <dt className="font-medium text-gray-500">Presigned URL Expires At:</dt>
                                        <dd className="text-gray-900">{new Date(diag.presigned_url_expires_at).toLocaleString()}</dd>
                                    </>
                                )}
                                {diag.last_http_status !== undefined && (
                                    <>
                                        <dt className="font-medium text-gray-500">Last HTTP Status:</dt>
                                        <dd className={`font-mono ${diag.last_http_status >= 400 ? 'text-red-600' : 'text-green-600'}`}>
                                            {diag.last_http_status}
                                        </dd>
                                    </>
                                )}
                                {diag.last_error_type && (
                                    <>
                                        <dt className="font-medium text-gray-500">Last Error Type:</dt>
                                        <dd className="text-red-600 font-medium">{diag.last_error_type}</dd>
                                    </>
                                )}
                                {diag.last_error_message && (
                                    <>
                                        <dt className="font-medium text-gray-500">Last Error Message:</dt>
                                        <dd className="text-red-600 break-words">{diag.last_error_message}</dd>
                                    </>
                                )}
                            </dl>
                            {diag.last_presigned_url && (
                                <details className="mt-2">
                                    <summary className="text-xs font-medium text-gray-500 cursor-pointer hover:text-gray-700">
                                        Presigned URL (click to expand)
                                    </summary>
                                    <pre className="mt-1 text-xs font-mono bg-gray-50 p-2 rounded border border-gray-200 break-all overflow-auto max-h-32">
                                        {diag.last_presigned_url}
                                    </pre>
                                </details>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    )
}

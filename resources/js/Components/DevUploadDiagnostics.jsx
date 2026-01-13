// Phase 2.5: Dev-only upload diagnostics panel
// Only visible when APP_ENV !== 'production' or window.__DEV_UPLOAD_DIAGNOSTICS__ === true

import { useState, useMemo } from 'react'
import { ChevronDownIcon, ChevronUpIcon, InformationCircleIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline'

/**
 * DevUploadDiagnostics - Dev-only panel showing upload diagnostic information
 * 
 * This component is only visible in development environments.
 * It helps developers debug upload issues without modifying backend code.
 * 
 * Phase 2.5: Dev-only observability feature
 * 
 * @param {Object} props
 * @param {Object} props.diagnostics - Diagnostics object (legacy format for backward compatibility)
 * @param {Array} props.events - Array of diagnostic events (new format)
 */
export default function DevUploadDiagnostics({ diagnostics = {}, events = [] }) {
    const [isExpanded, setIsExpanded] = useState(false)

    // Check if diagnostics should be shown (dev-only)
    // Phase 2.5: Only visible in development or when explicitly enabled
    const isDev = typeof window !== 'undefined' && (
        window.__DEV_UPLOAD_DIAGNOSTICS__ === true ||
        process.env.NODE_ENV === 'development' ||
        (import.meta.env && import.meta.env.DEV) ||
        (import.meta.env && import.meta.env.MODE && import.meta.env.MODE !== 'production')
    )

    // Merge legacy diagnostics format with new events format
    const allEvents = useMemo(() => {
        const eventList = [...events]
        
        // Convert legacy diagnostics object to events format
        if (diagnostics && typeof diagnostics === 'object') {
            Object.entries(diagnostics).forEach(([fileId, diag]) => {
                if (diag.last_error_type || diag.last_error_message) {
                    eventList.push({
                        id: fileId,
                        request_phase: diag.request_phase || 'unknown',
                        error_type: diag.last_error_type,
                        message: diag.last_error_message,
                        http_status: diag.last_http_status,
                        timestamp: diag.timestamp || new Date().toISOString(),
                        upload_session_id: diag.upload_session_id,
                        file_name: diag.file_name,
                        file_size: diag.file_size,
                    })
                }
            })
        }
        
        // Sort by timestamp (newest first)
        return eventList.sort((a, b) => {
            const timeA = new Date(a.timestamp || 0).getTime()
            const timeB = new Date(b.timestamp || 0).getTime()
            return timeB - timeA
        })
    }, [diagnostics, events])

    if (!isDev || allEvents.length === 0) {
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
                    {allEvents.length === 0 ? (
                        <p className="text-xs text-gray-500 italic">No diagnostic events yet</p>
                    ) : (
                        allEvents.slice(0, 10).map((event, index) => (
                            <div key={event.id || index} className="bg-white rounded p-3 border border-gray-200">
                                <div className="flex items-start justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <ExclamationCircleIcon className="h-4 w-4 text-red-500 flex-shrink-0" />
                                        <div>
                                            <div className="text-xs font-semibold text-gray-900">
                                                {event.file_name || event.id?.substring(0, 8) || 'Unknown'}
                                            </div>
                                            <div className="text-xs text-gray-500">
                                                {event.timestamp ? new Date(event.timestamp).toLocaleString() : 'Unknown time'}
                                            </div>
                                        </div>
                                    </div>
                                    {event.error_type && (
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                            event.error_type === 'auth' ? 'bg-red-100 text-red-700' :
                                            event.error_type === 'cors' ? 'bg-orange-100 text-orange-700' :
                                            event.error_type === 'network' ? 'bg-yellow-100 text-yellow-700' :
                                            event.error_type === 's3' ? 'bg-purple-100 text-purple-700' :
                                            event.error_type === 'validation' ? 'bg-blue-100 text-blue-700' :
                                            'bg-gray-100 text-gray-700'
                                        }`}>
                                            {event.error_type}
                                        </span>
                                    )}
                                </div>
                                
                                <dl className="grid grid-cols-1 gap-2 text-xs mt-2">
                                    {event.request_phase && (
                                        <>
                                            <dt className="font-medium text-gray-500">Request Phase:</dt>
                                            <dd className="text-gray-900 font-mono">{event.request_phase}</dd>
                                        </>
                                    )}
                                    {event.error_type && (
                                        <>
                                            <dt className="font-medium text-gray-500">Error Type:</dt>
                                            <dd className="text-red-600 font-medium">{event.error_type}</dd>
                                        </>
                                    )}
                                    {event.message && (
                                        <>
                                            <dt className="font-medium text-gray-500">Message:</dt>
                                            <dd className="text-red-600 break-words">{event.message}</dd>
                                        </>
                                    )}
                                    {event.http_status !== undefined && (
                                        <>
                                            <dt className="font-medium text-gray-500">HTTP Status:</dt>
                                            <dd className={`font-mono ${event.http_status >= 400 ? 'text-red-600' : 'text-green-600'}`}>
                                                {event.http_status}
                                            </dd>
                                        </>
                                    )}
                                    {event.upload_session_id && (
                                        <>
                                            <dt className="font-medium text-gray-500">Upload Session ID:</dt>
                                            <dd className="font-mono text-gray-900 break-all text-xs">{event.upload_session_id}</dd>
                                        </>
                                    )}
                                    {event.file_size && (
                                        <>
                                            <dt className="font-medium text-gray-500">File Size:</dt>
                                            <dd className="text-gray-900">
                                                {event.file_size > 1024 * 1024 
                                                    ? `${(event.file_size / (1024 * 1024)).toFixed(2)} MB`
                                                    : `${(event.file_size / 1024).toFixed(2)} KB`}
                                            </dd>
                                        </>
                                    )}
                                </dl>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    )
}

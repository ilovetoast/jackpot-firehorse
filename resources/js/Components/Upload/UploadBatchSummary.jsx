import React, { useMemo, useState } from 'react'
import { ClipboardDocumentIcon, CheckCircleIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/react/24/outline'
import WhyRejectedModal from './WhyRejectedModal'

/**
 * Phase 7: End-of-batch summary panel.
 *
 * Renders a compact "12 uploaded, 3 blocked" pill underneath an upload
 * dialog (or in a follow-up toast tray). Each blocked file is listed
 * with its filename + short reason, and the whole list is one-click
 * copyable so the user can paste it into a ticket or Slack thread.
 *
 * The component is presentational. The parent owns its lifecycle
 * (when to render, when to hide). Inputs are simple shapes so the
 * caller doesn't need to know about React contexts:
 *
 *   files: Array<{
 *     id: string,
 *     name: string,
 *     status: 'finalized' | 'failed' | 'uploading' | 'pending_preflight' | ...,
 *     errorCode?: string,
 *     errorMessage?: string,
 *     errorStage?: 'preflight' | 'upload' | 'finalize',
 *     extension?: string,
 *   }>
 */
export default function UploadBatchSummary({ files = [], onDismiss = null, primaryColor = '#f97316' }) {
    const { uploaded, blocked } = useMemo(() => splitByOutcome(files), [files])
    const [whyOpen, setWhyOpen] = useState(null)
    const [copied, setCopied] = useState(false)

    if (uploaded.length === 0 && blocked.length === 0) {
        return null
    }

    const copyBlockedList = async () => {
        if (blocked.length === 0) return
        const text = blocked
            .map((f) => `• ${f.name}  —  ${friendlyReason(f.errorCode, f.errorMessage)}`)
            .join('\n')
        try {
            await navigator.clipboard.writeText(text)
            setCopied(true)
            setTimeout(() => setCopied(false), 2200)
        } catch (e) {
            console.warn('[UploadBatchSummary] clipboard write failed', e)
        }
    }

    return (
        <>
            <div
                role="status"
                aria-live="polite"
                className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
            >
                <div className="flex items-start gap-3">
                    <div className="flex flex-1 flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        {uploaded.length > 0 && (
                            <span className="inline-flex items-center gap-1.5 font-medium text-emerald-700">
                                <CheckCircleIcon className="h-4 w-4" />
                                {uploaded.length} uploaded
                            </span>
                        )}
                        {blocked.length > 0 && (
                            <span className="inline-flex items-center gap-1.5 font-medium text-amber-800">
                                <ExclamationTriangleIcon className="h-4 w-4" />
                                {blocked.length} blocked
                            </span>
                        )}
                        {blocked.length > 0 && (
                            <button
                                type="button"
                                onClick={copyBlockedList}
                                className="ml-auto inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-200"
                                aria-label="Copy list of blocked files"
                            >
                                <ClipboardDocumentIcon className="h-3.5 w-3.5" />
                                {copied ? 'Copied!' : 'Copy list'}
                            </button>
                        )}
                    </div>
                    {onDismiss && (
                        <button
                            type="button"
                            onClick={onDismiss}
                            className="-m-1 rounded-full p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                            aria-label="Dismiss"
                        >
                            <XMarkIcon className="h-4 w-4" />
                        </button>
                    )}
                </div>

                {blocked.length > 0 && (
                    <ul className="mt-3 max-h-40 space-y-1 overflow-y-auto pr-1 text-xs">
                        {blocked.map((f) => (
                            <li key={f.id} className="flex items-start gap-2 rounded-md bg-amber-50/70 px-2 py-1 ring-1 ring-amber-100">
                                <span className="mt-0.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                                <span className="min-w-0 flex-1 truncate text-slate-800" title={f.name}>
                                    {f.name}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setWhyOpen(f)}
                                    className="shrink-0 text-amber-900 underline-offset-2 hover:underline"
                                >
                                    Why?
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {whyOpen && (
                <WhyRejectedModal
                    open
                    onClose={() => setWhyOpen(null)}
                    fileName={whyOpen.name}
                    errorCode={whyOpen.errorCode}
                    errorMessage={whyOpen.errorMessage}
                    extension={whyOpen.extension}
                    primaryColor={primaryColor}
                />
            )}
        </>
    )
}

/**
 * Bucket files into "uploaded" and "blocked". A file is considered blocked
 * only when the failure stage is preflight or finalize (i.e. policy /
 * content rejection); transient upload-stage failures (network, S3) are
 * not surfaced here because retrying is the right answer for those.
 */
function splitByOutcome(files) {
    const uploaded = []
    const blocked = []
    for (const f of files) {
        const status = f?.status ?? f?.uploadStatus ?? null
        const stage = f?.errorStage ?? f?.error?.stage ?? null
        if (status === 'finalized' || status === 'completed' || status === 'uploaded') {
            uploaded.push(f)
        } else if (status === 'failed' && (stage === 'preflight' || stage === 'finalize')) {
            blocked.push({
                ...f,
                errorCode: f.errorCode ?? f.error?.code ?? null,
                errorMessage: f.errorMessage ?? f.error?.message ?? null,
                extension: f.extension ?? extractExtension(f.name),
            })
        }
    }

    return { uploaded, blocked }
}

function extractExtension(name = '') {
    if (typeof name !== 'string' || name === '') return ''
    const i = name.lastIndexOf('.')
    if (i <= 0) return ''
    return name.slice(i + 1).toLowerCase()
}

function friendlyReason(code, message) {
    if (code === 'blocked_executable') return 'executable file (blocked for safety)'
    if (code === 'blocked_archive') return 'compressed archive (zip / rar / 7z) blocked'
    if (code === 'blocked_double_extension') return 'suspicious double extension in filename'
    if (code === 'invalid_filename') return 'filename has invalid characters'
    if (code === 'unsupported_type') return 'file type not in the supported list'
    if (code === 'coming_soon_type') return 'file type coming soon (not enabled yet)'
    if (code?.startsWith?.('content_')) return 'real file contents did not match the declared type'
    if (code === 'file_size_limit') return 'file exceeds plan upload size'
    if (code === 'plan_cap_exceeded') return 'file exceeds your plan’s per-type cap'
    return message || 'rejected by policy'
}

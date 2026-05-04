/**
 * Sticky batch summary for multi-file upload: headline, counts, phase, overall progress.
 * `compact` reduces line-by-line updates (fewer DOM changes during rapid progress ticks).
 */

import { memo, useState } from 'react'

function cn(...parts) {
    return parts.filter(Boolean).join(' ')
}

function buildParts(counts) {
    const {
        uploading = 0,
        processing = 0,
        uploaded = 0,
        ready = 0,
        failed = 0,
        skipped = 0,
        queued = 0,
    } = counts || {}

    const parts = []
    if (ready > 0) parts.push(`${ready} ready`)
    if (uploaded > 0) parts.push(`${uploaded} uploaded`)
    if (processing > 0) parts.push(`${processing} processing previews`)
    if (uploading > 0 || queued > 0) parts.push(`${uploading + queued} uploading`)
    if (failed > 0) parts.push(`${failed} failed`)
    if (skipped > 0) parts.push(`${skipped} skipped`)
    return parts
}

const PHASE_LABELS = {
    preparing: 'Preparing files',
    checking: 'Checking limits',
    uploading: 'Uploading',
    finalizing: 'Finalizing',
    processing_previews: 'Processing previews',
    /** Post-finalize: bytes are in S3, assets exist, previews/metadata jobs still running */
    processing_followup: 'Preparing previews & metadata',
    batch_ready: 'Ready to finalize',
    complete: 'Complete',
    complete_with_errors: 'Complete with errors',
    idle: '',
}

function UploadBatchSummaryBar({
    totalCount,
    imageCount,
    counts,
    batchPhase,
    overallPercent,
    compact = false,
    /** Brand workspace primary — progress fill; avoids indigo/blue competing with tenant accent */
    brandPrimary = null,
}) {
    const [detailsOpen, setDetailsOpen] = useState(false)

    const headline =
        totalCount === 0
            ? 'No files selected'
            : imageCount >= totalCount * 0.85 && totalCount > 0
              ? `Uploading ${totalCount} ${totalCount === 1 ? 'photo' : 'photos'}`
              : `Uploading ${totalCount} ${totalCount === 1 ? 'file' : 'files'}`

    const parts = buildParts(counts)
    const summaryTitle = parts.join(' · ')
    const phase = PHASE_LABELS[batchPhase] || ''
    const displayPct = Math.min(100, Math.max(0, Math.round(overallPercent)))

    const showLongNote = totalCount >= 24

    return (
        <div
            className={cn(
                'rounded-lg border border-gray-200 bg-gradient-to-b from-gray-50/95 to-white px-4 py-3 shadow-sm',
                'backdrop-blur-sm'
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <h4 className="text-sm font-semibold text-gray-900" title={compact ? summaryTitle : undefined}>
                        {headline}
                    </h4>

                    {!compact && parts.length > 0 && (
                        <p className="mt-1 text-xs text-gray-600" title={summaryTitle}>
                            {summaryTitle}
                        </p>
                    )}

                    {!compact && phase && (
                        <p className="mt-1.5 text-xs font-medium text-gray-800" aria-live="polite">
                            {phase}
                        </p>
                    )}

                    {compact && (
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <span className="text-xs text-gray-600 tabular-nums">{displayPct}% overall</span>
                            {(parts.length > 0 || phase) && (
                                <button
                                    type="button"
                                    className="text-xs font-medium text-gray-700 hover:text-gray-900"
                                    onClick={() => setDetailsOpen((o) => !o)}
                                >
                                    {detailsOpen ? 'Hide details' : 'Details'}
                                </button>
                            )}
                        </div>
                    )}

                    {compact && detailsOpen && (
                        <div className="mt-2 rounded-md border border-gray-100 bg-white/80 px-2 py-1.5 text-xs text-gray-700">
                            {parts.length > 0 && <p>{summaryTitle}</p>}
                            {phase && <p className="mt-0.5 text-gray-800">{phase}</p>}
                        </div>
                    )}
                </div>
                <div className="w-full shrink-0 sm:w-44">
                    <div className="flex items-center justify-between gap-2 text-xs text-gray-500">
                        <span>Overall</span>
                        <span className="tabular-nums font-medium text-gray-700">{displayPct}%</span>
                    </div>
                    <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-200">
                        <div
                            className="h-full rounded-full transition-[width] duration-500 ease-out"
                            style={{
                                width: `${displayPct}%`,
                                backgroundColor: brandPrimary || '#4f46e5',
                            }}
                        />
                    </div>
                </div>
            </div>
            {showLongNote && (
                <p className="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-500">
                    Large batches may take a few minutes to finish previews.
                </p>
            )}
        </div>
    )
}

export default memo(UploadBatchSummaryBar, (prev, next) => {
    if (prev.totalCount !== next.totalCount) return false
    if (prev.imageCount !== next.imageCount) return false
    if (prev.batchPhase !== next.batchPhase) return false
    if (prev.compact !== next.compact) return false
    if (prev.brandPrimary !== next.brandPrimary) return false
    if (Math.round(prev.overallPercent) !== Math.round(next.overallPercent)) return false
    const a = JSON.stringify(prev.counts || {})
    const b = JSON.stringify(next.counts || {})
    if (a !== b) return false
    return true
})

/**
 * Executions drawer: compare Original, Enhanced, and Presentation in one modal (regenerate lives here).
 */
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { ArrowPathIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { EXECUTION_VERSION_DETAIL_BULLETS } from '../utils/executionVersionPreviewCopy'
import {
    formatIsoDateTimeLocal,
    formatThumbnailPipelineAttemptLabel,
} from '../utils/thumbnailModes'

/**
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string} [props.primaryColor]
 * @param {string|null|undefined} props.originalUrl
 * @param {string|null|undefined} props.enhancedUrl
 * @param {string|null|undefined} props.presentationUrl
 * @param {string|null|undefined} [props.originalLastGeneratedAt]
 * @param {string|null|undefined} [props.enhancedLastAttemptAt]
 * @param {string|null|undefined} [props.presentationLastAttemptAt]
 * @param {string|null|undefined} props.templateLabelEnhanced
 * @param {boolean} props.preferredPipelineFailed
 * @param {boolean} props.canRetryCleanPreferred
 * @param {() => void} [props.onRetryCleanPreferred]
 * @param {boolean} props.retryCleanPreferredLoading
 * @param {boolean} props.retryCleanPreferredDisabled
 * @param {string} props.enhancedPipelineStatus
 * @param {boolean} props.showEnhancedGenerate
 * @param {string} props.enhancedGenerateLabel
 * @param {() => void} [props.onEnhancedGenerate]
 * @param {boolean} props.enhancedGenerateLoading
 * @param {boolean} props.enhancedGenerateDisabled
 * @param {boolean} [props.enhancedDebugBboxOverlay]
 * @param {(v: boolean) => void} [props.onEnhancedDebugBboxOverlayChange]
 * @param {string} props.presentationPipelineStatus
 * @param {boolean} props.showPresentationGenerate
 * @param {string} props.presentationGenerateLabel
 * @param {() => void} [props.onPresentationGenerate]
 * @param {boolean} props.presentationGenerateLoading
 * @param {boolean} props.presentationGenerateDisabled
 */
export default function ExecutionTripleCompareModal({
    open,
    onClose,
    primaryColor = '#6366f1',
    originalUrl,
    enhancedUrl,
    presentationUrl,
    templateLabelEnhanced = null,
    preferredPipelineFailed = false,
    canRetryCleanPreferred = false,
    onRetryCleanPreferred = null,
    retryCleanPreferredLoading = false,
    retryCleanPreferredDisabled = false,
    enhancedPipelineStatus = '',
    showEnhancedGenerate = false,
    enhancedGenerateLabel = 'Generate',
    onEnhancedGenerate = null,
    enhancedGenerateLoading = false,
    enhancedGenerateDisabled = false,
    enhancedDebugBboxOverlay = false,
    onEnhancedDebugBboxOverlayChange = null,
    presentationPipelineStatus = '',
    showPresentationGenerate = false,
    presentationGenerateLabel = 'Generate',
    onPresentationGenerate = null,
    presentationGenerateLoading = false,
    presentationGenerateDisabled = false,
    originalLastGeneratedAt = null,
    enhancedLastAttemptAt = null,
    presentationLastAttemptAt = null,
}) {
    const enhSt = String(enhancedPipelineStatus || '').toLowerCase()
    const presSt = String(presentationPipelineStatus || '').toLowerCase()

    const originalTimeLine = (() => {
        const t = formatIsoDateTimeLocal(originalLastGeneratedAt)
        return t ? `Last generated ${t}` : null
    })()
    const enhancedTimeLine = formatThumbnailPipelineAttemptLabel(
        enhancedPipelineStatus,
        enhancedLastAttemptAt,
    )
    const presentationTimeLine = formatThumbnailPipelineAttemptLabel(
        presentationPipelineStatus,
        presentationLastAttemptAt,
    )

    const col = (title, sub, timeLine, url, bullets, footer) => (
        <div className="flex min-w-0 flex-col gap-2">
            <div>
                <p className="text-sm font-semibold text-gray-900">{title}</p>
                {sub ? <p className="text-xs text-gray-500">{sub}</p> : null}
                {timeLine ? <p className="text-[11px] text-gray-500">{timeLine}</p> : null}
            </div>
            <div className="relative flex min-h-[140px] flex-1 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 sm:min-h-[180px]">
                {url ? (
                    <img src={url} alt="" className="max-h-[220px] max-w-full object-contain sm:max-h-[280px]" />
                ) : (
                    <span className="px-3 text-center text-xs text-gray-500">No preview</span>
                )}
            </div>
            <ul className="list-disc space-y-0.5 pl-4 text-[11px] leading-snug text-gray-600">
                {bullets.map((line) => (
                    <li key={line}>{line}</li>
                ))}
            </ul>
            {footer ? <div className="mt-auto pt-1">{footer}</div> : null}
        </div>
    )

    return (
        <Dialog open={open} onClose={onClose} className="relative z-[10100]">
            <div className="fixed inset-0 bg-black/50" aria-hidden />
            <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-3 sm:p-6">
                <DialogPanel className="w-full max-w-6xl rounded-xl border border-gray-200 bg-white shadow-2xl">
                    <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-5">
                        <div>
                            <DialogTitle className="text-base font-semibold text-gray-900">
                                Compare preview versions
                            </DialogTitle>
                            <p className="mt-1 text-sm text-gray-600">
                                Original, enhanced (studio), and presentation side by side. Regenerate runs from here
                                when a version already exists.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                            aria-label="Close"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="grid gap-5 p-4 sm:grid-cols-3 sm:p-5">
                        {col(
                            'Original',
                            'Source thumbnail',
                            originalTimeLine,
                            originalUrl,
                            EXECUTION_VERSION_DETAIL_BULLETS.original,
                            preferredPipelineFailed && canRetryCleanPreferred && onRetryCleanPreferred ? (
                                <button
                                    type="button"
                                    onClick={onRetryCleanPreferred}
                                    disabled={retryCleanPreferredDisabled || retryCleanPreferredLoading}
                                    className="w-full rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-[10px] font-medium text-amber-900 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {retryCleanPreferredLoading ? 'Retrying…' : 'Retry clean thumbnail'}
                                </button>
                            ) : null,
                        )}
                        {col(
                            'Enhanced',
                            templateLabelEnhanced ? `Template: ${templateLabelEnhanced}` : 'Studio framing when available',
                            enhancedTimeLine,
                            enhancedUrl,
                            EXECUTION_VERSION_DETAIL_BULLETS.enhanced,
                            enhSt === 'processing' ? (
                                <div className="flex items-center gap-1 text-[10px] text-gray-600">
                                    <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-gray-500" />
                                    <span>Generating…</span>
                                </div>
                            ) : showEnhancedGenerate && onEnhancedGenerate ? (
                                <div className="flex flex-col gap-1.5">
                                    {typeof onEnhancedDebugBboxOverlayChange === 'function' ? (
                                        <label className="flex cursor-pointer items-center gap-2 text-[10px] text-gray-600">
                                            <input
                                                type="checkbox"
                                                checked={enhancedDebugBboxOverlay}
                                                onChange={(e) => onEnhancedDebugBboxOverlayChange(e.target.checked)}
                                                className="rounded border-gray-300"
                                            />
                                            Draw print bbox (red) on source in the saved enhanced image
                                        </label>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={onEnhancedGenerate}
                                        disabled={enhancedGenerateDisabled || enhancedGenerateLoading}
                                        className="w-full rounded-md px-2 py-1.5 text-[10px] font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                        style={{ backgroundColor: primaryColor }}
                                    >
                                        {enhancedGenerateLoading ? 'Queueing…' : enhancedGenerateLabel}
                                    </button>
                                </div>
                            ) : null,
                        )}
                        {col(
                            'Presentation',
                            'AI treatment',
                            presentationTimeLine,
                            presentationUrl,
                            EXECUTION_VERSION_DETAIL_BULLETS.presentation,
                            presSt === 'processing' ? (
                                <div className="flex items-center gap-1 text-[10px] text-gray-600">
                                    <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-gray-500" />
                                    <span>Generating…</span>
                                </div>
                            ) : showPresentationGenerate && onPresentationGenerate ? (
                                <button
                                    type="button"
                                    onClick={onPresentationGenerate}
                                    disabled={presentationGenerateDisabled || presentationGenerateLoading}
                                    className="w-full rounded-md px-2 py-1.5 text-[10px] font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                    style={{ backgroundColor: primaryColor }}
                                >
                                    {presentationGenerateLoading ? 'Queueing…' : presentationGenerateLabel}
                                </button>
                            ) : null,
                        )}
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}

/**
 * Executions drawer: compare Source, Studio, Presentation (CSS), and AI in one modal.
 */
import { useEffect, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { ArrowDownTrayIcon, ArrowPathIcon, XMarkIcon } from '@heroicons/react/24/outline'
import {
    formatIsoDateTimeLocal,
    formatThumbnailPipelineAttemptParts,
} from '../utils/thumbnailModes'
import ExecutionPresentationFrame from './execution/ExecutionPresentationFrame'

/** Aligned with config/presentation_preview.max_scene_description_length (default 500). */
const AI_SCENE_DESCRIPTION_MAX = 500

const PRESENTATION_PRESETS = [
    { id: 'neutral_studio', label: 'Neutral studio' },
    { id: 'desk_surface', label: 'Desk / surface' },
    { id: 'wall_pin', label: 'Wall / pin' },
]

function compactGeneratedMeta(iso) {
    const time = formatIsoDateTimeLocal(iso)
    if (!time) {
        return null
    }
    return (
        <div className="text-center lg:text-left">
            <div className="text-[10px] font-medium uppercase tracking-wide text-gray-500">Generated</div>
            <div className="text-[10px] tabular-nums text-gray-400">{time}</div>
        </div>
    )
}

function pipelineAttemptMeta(status, iso) {
    const parts = formatThumbnailPipelineAttemptParts(status, iso)
    if (!parts) {
        return null
    }
    return (
        <div className="text-center lg:text-left">
            <div className="text-[10px] font-medium uppercase tracking-wide text-gray-500">{parts.head}</div>
            <div className="text-[10px] tabular-nums text-gray-400">{parts.time}</div>
        </div>
    )
}

function downloadButton(onClick, busy, disabled, label) {
    if (typeof onClick !== 'function') {
        return null
    }
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled || busy}
            className="inline-flex w-full items-center justify-center gap-1.5 rounded-md border border-gray-200 bg-white px-2 py-1.5 text-[10px] font-semibold text-gray-800 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
        >
            <ArrowDownTrayIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
            {busy ? '…' : label}
        </button>
    )
}

/**
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string} [props.primaryColor]
 * @param {string|null|undefined} props.originalUrl
 * @param {string|null|undefined} props.studioUrl — enhanced / Studio View raster
 * @param {string|null|undefined} props.presentationCssBaseUrl — Studio → Source for CSS frame
 * @param {string} [props.presentationPreset]
 * @param {(preset: string) => void} [props.onPresentationPresetChange]
 * @param {boolean} [props.presentationPresetSaving]
 * @param {string|null|undefined} props.aiViewUrl — presentation-mode AI raster
 * @param {string|null|undefined} [props.originalLastGeneratedAt]
 * @param {string|null|undefined} [props.studioLastAttemptAt]
 * @param {string|null|undefined} [props.aiLastAttemptAt]
 * @param {string|null|undefined} props.templateLabelStudio
 * @param {boolean} props.preferredPipelineFailed
 * @param {boolean} props.canRetryCleanPreferred
 * @param {() => void} [props.onRetryCleanPreferred]
 * @param {boolean} props.retryCleanPreferredLoading
 * @param {boolean} props.retryCleanPreferredDisabled
 * @param {string} props.studioPipelineStatus
 * @param {boolean} props.showStudioOpenModal
 * @param {string} props.studioActionLabel
 * @param {() => void} [props.onStudioOpenModal]
 * @param {boolean} props.studioActionLoading
 * @param {boolean} props.studioActionDisabled
 * @param {string} props.aiPipelineStatus
 * @param {boolean} props.showAiGenerate
 * @param {string} props.aiGenerateLabel
 * @param {string} [props.initialAiSceneDescription] — last saved scene line from asset metadata (optional).
 * @param {(ctx?: { sceneDescription: string }) => void} [props.onAiGenerate]
 * @param {boolean} props.aiGenerateLoading
 * @param {boolean} props.aiGenerateDisabled
 * @param {() => void} [props.onDownloadOriginal]
 * @param {() => void} [props.onDownloadStudio]
 * @param {() => void} [props.onDownloadPresentationBase]
 * @param {() => void} [props.onDownloadAi]
 * @param {string|null} [props.downloadLoadingMode]
 * @param {() => void} [props.onStudioRequeue]
 * @param {boolean} [props.showStudioRequeue]
 * @param {boolean} [props.studioRequeueDisabled]
 * @param {boolean} [props.studioRequeueBusy]
 * @param {(ctx?: { sceneDescription: string }) => void} [props.onAiRequeue]
 * @param {boolean} [props.showAiRequeue]
 * @param {boolean} [props.aiRequeueDisabled]
 * @param {boolean} [props.aiRequeueBusy]
 * @param {string|null} [props.studioStatusNote]
 * @param {string|null} [props.aiStatusNote]
 */
export default function ExecutionTripleCompareModal({
    open,
    onClose,
    primaryColor = '#6366f1',
    originalUrl,
    studioUrl,
    presentationCssBaseUrl,
    presentationPreset = 'neutral_studio',
    onPresentationPresetChange = null,
    presentationPresetSaving = false,
    aiViewUrl,
    templateLabelStudio = null,
    preferredPipelineFailed = false,
    canRetryCleanPreferred = false,
    onRetryCleanPreferred = null,
    retryCleanPreferredLoading = false,
    retryCleanPreferredDisabled = false,
    studioPipelineStatus = '',
    showStudioOpenModal = false,
    studioActionLabel = 'Create Studio View',
    onStudioOpenModal = null,
    studioActionLoading = false,
    studioActionDisabled = false,
    aiPipelineStatus = '',
    showAiGenerate = false,
    aiGenerateLabel = 'Generate',
    onAiGenerate = null,
    aiGenerateLoading = false,
    aiGenerateDisabled = false,
    originalLastGeneratedAt = null,
    studioLastAttemptAt = null,
    aiLastAttemptAt = null,
    onDownloadOriginal = null,
    onDownloadStudio = null,
    onDownloadPresentationBase = null,
    onDownloadAi = null,
    downloadLoadingMode = null,
    onStudioRequeue = null,
    showStudioRequeue = false,
    studioRequeueDisabled = false,
    studioRequeueBusy = false,
    onAiRequeue = null,
    showAiRequeue = false,
    aiRequeueDisabled = false,
    aiRequeueBusy = false,
    studioStatusNote = null,
    aiStatusNote = null,
    initialAiSceneDescription = '',
}) {
    const [aiSceneDescription, setAiSceneDescription] = useState('')
    const studioSt = String(studioPipelineStatus || '').toLowerCase()
    const aiSt = String(aiPipelineStatus || '').toLowerCase()
    const dl = downloadLoadingMode

    useEffect(() => {
        if (!open) return
        const next = String(initialAiSceneDescription || '').slice(0, AI_SCENE_DESCRIPTION_MAX)
        setAiSceneDescription(next)
    }, [open, initialAiSceneDescription])

    const showAiSceneField = Boolean(showAiGenerate || showAiRequeue)

    const col = (title, sub, meta, body, footer) => (
        <div className="flex min-w-0 flex-col gap-2">
            <div>
                <p className="text-sm font-semibold text-gray-900">{title}</p>
                {sub ? <p className="text-xs text-gray-500">{sub}</p> : null}
            </div>
            {meta ? <div className="min-h-0">{meta}</div> : null}
            <div className="relative flex min-h-[140px] flex-1 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50 sm:min-h-[180px]">
                {body}
            </div>
            {footer ? <div className="mt-auto flex flex-col gap-1.5 border-t border-gray-100 pt-2">{footer}</div> : null}
        </div>
    )

    const presentationBody = presentationCssBaseUrl ? (
        <div className="h-full w-full min-h-[140px] sm:min-h-[180px]">
            <ExecutionPresentationFrame
                imageUrl={presentationCssBaseUrl}
                preset={presentationPreset}
                className="min-h-[140px]"
            />
        </div>
    ) : (
        <span className="px-3 text-center text-xs text-gray-500">No base image</span>
    )

    const presentationFooter =
        typeof onDownloadPresentationBase === 'function' || typeof onPresentationPresetChange === 'function' ? (
            <div className="flex flex-col gap-1.5">
                {downloadButton(
                    onDownloadPresentationBase,
                    dl === 'presentation_base',
                    false,
                    'Download base image',
                )}
                {typeof onPresentationPresetChange === 'function' ? (
                    <div className="flex flex-col gap-1">
                        <label className="text-[10px] font-medium text-gray-600" htmlFor="compare-pres-preset">
                            Preset
                        </label>
                        <select
                            id="compare-pres-preset"
                            value={presentationPreset}
                            disabled={presentationPresetSaving}
                            onChange={(e) => onPresentationPresetChange(e.target.value)}
                            className="rounded-md border border-gray-300 bg-white px-2 py-1 text-[10px] text-gray-900"
                        >
                            {PRESENTATION_PRESETS.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : null}
            </div>
        ) : null

    return (
        <Dialog open={open} onClose={onClose} className="relative z-[10100]">
            <div className="fixed inset-0 bg-black/50" aria-hidden />
            <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-3 sm:p-6">
                <DialogPanel className="w-full max-w-6xl rounded-xl border border-gray-200 bg-white shadow-2xl">
                    <div className="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 sm:px-5">
                        <div>
                            <DialogTitle className="text-base font-semibold text-gray-900">
                                Compare &amp; manage preview
                            </DialogTitle>
                            <p className="mt-1 text-sm text-gray-600">
                                Source, Studio, Presentation (CSS), and AI side by side. Downloads, last generated times,
                                and regeneration live here.
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

                    <div className="grid gap-5 p-4 sm:grid-cols-2 lg:grid-cols-4 sm:p-5">
                        {col(
                            'Source',
                            'Pipeline thumbnail',
                            compactGeneratedMeta(originalLastGeneratedAt),
                            originalUrl ? (
                                <img
                                    src={originalUrl}
                                    alt=""
                                    className="max-h-[220px] max-w-full object-contain sm:max-h-[280px]"
                                />
                            ) : (
                                <span className="px-3 text-center text-xs text-gray-500">No preview</span>
                            ),
                            <>
                                {downloadButton(
                                    onDownloadOriginal,
                                    dl === 'original',
                                    false,
                                    'Download source',
                                )}
                                {preferredPipelineFailed && canRetryCleanPreferred && onRetryCleanPreferred ? (
                                    <button
                                        type="button"
                                        onClick={onRetryCleanPreferred}
                                        disabled={retryCleanPreferredDisabled || retryCleanPreferredLoading}
                                        className="w-full rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-[10px] font-medium text-amber-900 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {retryCleanPreferredLoading ? 'Retrying…' : 'Retry clean thumbnail'}
                                    </button>
                                ) : null}
                            </>,
                        )}
                        {col(
                            'Studio',
                            templateLabelStudio ? `Framing: ${templateLabelStudio}` : 'Manual crop + framing',
                            <>
                                {pipelineAttemptMeta(studioPipelineStatus, studioLastAttemptAt)}
                                {studioStatusNote ? (
                                    <p className="mt-1 line-clamp-3 text-center text-[10px] text-amber-900 lg:text-left">
                                        {studioStatusNote}
                                    </p>
                                ) : null}
                            </>,
                            studioUrl ? (
                                <div className="flex h-full w-full items-center justify-center bg-[length:10px_10px] [background-image:repeating-conic-gradient(#f1f5f9_0%_25%,#ffffff_0%_50%)]">
                                    <img
                                        src={studioUrl}
                                        alt=""
                                        className="max-h-[220px] max-w-full object-contain sm:max-h-[280px]"
                                    />
                                </div>
                            ) : (
                                <span className="px-3 text-center text-xs text-gray-500">No Studio View yet</span>
                            ),
                            <>
                                {downloadButton(onDownloadStudio, dl === 'enhanced', false, 'Download Studio view')}
                                {studioSt === 'processing' ? (
                                    <div className="flex items-center gap-1 text-[10px] text-gray-600">
                                        <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-gray-500" />
                                        <span>Saving…</span>
                                    </div>
                                ) : null}
                                {showStudioRequeue && onStudioRequeue ? (
                                    <button
                                        type="button"
                                        title="Re-queues with a full-frame crop (admin escape hatch)."
                                        onClick={onStudioRequeue}
                                        disabled={studioRequeueDisabled}
                                        className="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-[10px] font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {studioRequeueBusy ? 'Queueing…' : 'Re-queue Studio job'}
                                    </button>
                                ) : null}
                                {showStudioOpenModal && onStudioOpenModal ? (
                                    <button
                                        type="button"
                                        onClick={onStudioOpenModal}
                                        disabled={studioActionDisabled || studioActionLoading}
                                        className="w-full rounded-md px-2 py-1.5 text-[10px] font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                        style={{ backgroundColor: primaryColor }}
                                    >
                                        {studioActionLoading ? 'Queueing…' : studioActionLabel}
                                    </button>
                                ) : null}
                            </>,
                        )}
                        {col('Presentation', 'CSS presets (no AI)', null, presentationBody, presentationFooter)}
                        {col(
                            'AI',
                            'Generated scene (optional)',
                            <>
                                {pipelineAttemptMeta(aiPipelineStatus, aiLastAttemptAt)}
                                {aiStatusNote ? (
                                    <p className="mt-1 line-clamp-3 text-center text-[10px] text-amber-900 lg:text-left">
                                        {aiStatusNote}
                                    </p>
                                ) : null}
                            </>,
                            aiViewUrl ? (
                                <img
                                    src={aiViewUrl}
                                    alt=""
                                    className="max-h-[220px] max-w-full object-contain sm:max-h-[280px]"
                                />
                            ) : (
                                <span className="px-3 text-center text-xs text-gray-500">No AI view yet</span>
                            ),
                            <>
                                {showAiSceneField ? (
                                    <div className="flex flex-col gap-1">
                                        <label className="text-[10px] font-medium text-gray-600" htmlFor="compare-ai-scene">
                                            Environment (optional)
                                        </label>
                                        <textarea
                                            id="compare-ai-scene"
                                            rows={2}
                                            maxLength={AI_SCENE_DESCRIPTION_MAX}
                                            value={aiSceneDescription}
                                            onChange={(e) =>
                                                setAiSceneDescription(e.target.value.slice(0, AI_SCENE_DESCRIPTION_MAX))
                                            }
                                            disabled={aiGenerateLoading || aiRequeueBusy || aiSt === 'processing'}
                                            placeholder="e.g. Architect's desk, warm afternoon light"
                                            className="resize-y rounded-md border border-gray-300 bg-white px-2 py-1.5 text-[10px] text-gray-900 placeholder:text-gray-400 disabled:bg-gray-50 disabled:text-gray-500"
                                        />
                                        <p className="text-[9px] leading-snug text-gray-500">
                                            We prepend standard instructions to preserve your creative and only place it
                                            in this scene for presentation.
                                        </p>
                                    </div>
                                ) : null}
                                {downloadButton(onDownloadAi, dl === 'presentation', false, 'Download AI view')}
                                {(aiGenerateLoading || aiRequeueBusy) && aiSt !== 'processing' ? (
                                    <div className="flex items-center gap-1 text-[10px] text-gray-600">
                                        <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-gray-500" />
                                        <span>Submitting request…</span>
                                    </div>
                                ) : null}
                                {aiSt === 'processing' ? (
                                    <div className="flex items-center gap-1 text-[10px] text-gray-600">
                                        <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin text-gray-500" />
                                        <span>Generating…</span>
                                    </div>
                                ) : null}
                                {showAiRequeue && onAiRequeue ? (
                                    <button
                                        type="button"
                                        title='Starts a new job even if status is still "in progress".'
                                        onClick={() => onAiRequeue({ sceneDescription: aiSceneDescription })}
                                        disabled={aiRequeueDisabled}
                                        className="w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-[10px] font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {aiRequeueBusy ? 'Queueing…' : 'Queue AI job again'}
                                    </button>
                                ) : null}
                                {showAiGenerate && onAiGenerate ? (
                                    <button
                                        type="button"
                                        onClick={() => onAiGenerate({ sceneDescription: aiSceneDescription })}
                                        disabled={aiGenerateDisabled || aiGenerateLoading}
                                        className="w-full rounded-md px-2 py-1.5 text-[10px] font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                                        style={{ backgroundColor: primaryColor }}
                                    >
                                        {aiGenerateLoading ? 'Queueing…' : aiGenerateLabel}
                                    </button>
                                ) : null}
                            </>,
                        )}
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}

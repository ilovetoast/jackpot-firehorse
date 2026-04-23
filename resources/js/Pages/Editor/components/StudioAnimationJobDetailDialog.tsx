import { Link } from '@inertiajs/react'
import { useEffect, useState, useId } from 'react'
import { ExclamationTriangleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/24/outline'
import {
    getStudioAnimationFailureRecoveryLine,
    getStudioAnimationStallHints,
    postStudioAnimationRetry,
    studioAnimationRailJobLabel,
    type StudioAnimationJobDto,
} from '../editorStudioAnimationBridge'

function fmtWhen(iso: string | null | undefined): string {
    if (!iso) return '—'
    try {
        return new Date(iso).toLocaleString()
    } catch {
        return iso
    }
}

function statusTitle(status: string, playBackAvailable: boolean): string {
    const m: Record<string, string> = {
        queued: 'Waiting for a worker to start this job.',
        rendering: 'Preparing the locked start frame from your composition.',
        submitting: 'Sending the start frame to the video provider.',
        processing: 'The provider is generating your clip.',
        downloading: 'Downloading the finished video.',
        finalizing: 'Saving the file to your library.',
        complete: playBackAvailable
            ? 'Finished — video is saved as an asset.'
            : 'Finished on the server, but no video is linked for playback. The library file may have been removed, or the output was never attached.',
        failed: 'This run did not complete.',
        canceled: 'Canceled.',
    }
    return m[status] ?? status
}

export type StudioVideoInsertMode = 'add_back' | 'add_front' | 'replace_source'

export type InsertAnimationVideoArgs = {
    assetId: string
    fileUrl: string
    name: string
    endMs: number
    mode: StudioVideoInsertMode
    /** Required when mode is replace_source */
    replaceLayerId?: string | null
    provenance?: Record<string, string | number | undefined>
}

type Props = {
    job: StudioAnimationJobDto | null
    open: boolean
    onClose: () => void
    onJobsUpdated: () => void
    /** Failed/canceled/stale; should confirm then DELETE; resolve true if removed. */
    onRequestDiscardJob?: (jobId: string) => Promise<boolean>
    /** Editor composition title — dialog heading matches Versions rail tiles. */
    compositionTitleForLabel?: string
    /** When set with {@link onInsertOutputAsVideoLayer} / {@link onExportBakedVideo}, show composition actions. */
    compositionId?: string | null
    /** When the job references a source layer and that layer still exists, allows “replace source”. */
    sourceLayerReplaceable?: boolean
    /** Add this run’s output as a new video layer on the composition (server + local state). */
    onInsertOutputAsVideoLayer?: (args: InsertAnimationVideoArgs) => Promise<void>
    /** Queue a worker export; resolves with the new output asset id when available. */
    onExportBakedVideo?: () => Promise<string | null>
}

export function StudioAnimationJobDetailDialog(props: Props) {
    const {
        job,
        open,
        onClose,
        onJobsUpdated,
        onRequestDiscardJob,
        compositionTitleForLabel = '',
        compositionId = null,
        onInsertOutputAsVideoLayer,
        onExportBakedVideo,
        sourceLayerReplaceable = false,
    } = props
    const insertGroupId = useId()
    const [insertMode, setInsertMode] = useState<StudioVideoInsertMode>('add_back')
    const [retryBusy, setRetryBusy] = useState(false)
    const [discardBusy, setDiscardBusy] = useState(false)
    const [insertVideoBusy, setInsertVideoBusy] = useState(false)
    const [exportBakedBusy, setExportBakedBusy] = useState(false)
    const [insertLayerError, setInsertLayerError] = useState<string | null>(null)
    const [bakedExportError, setBakedExportError] = useState<string | null>(null)
    const [bakedExportOutputAssetId, setBakedExportOutputAssetId] = useState<string | null>(null)

    useEffect(() => {
        if (open && job?.id) {
            setInsertLayerError(null)
            setBakedExportError(null)
            setBakedExportOutputAssetId(null)
            setInsertMode('add_back')
        }
    }, [open, job?.id])

    if (!open || !job) {
        return null
    }

    const reserved = typeof job.credits_reserved === 'number' ? job.credits_reserved : 0
    const charged = Boolean(job.credits_charged)
    const units = typeof job.credits_charged_units === 'number' ? job.credits_charged_units : 0
    const playBackAvailable = Boolean(job.output?.asset_view_url)
    const outputAssetId = job.output?.asset_id && job.output.asset_id !== '' ? String(job.output.asset_id) : null

    const retryLabel =
        job.retry_kind === 'poll_only'
            ? 'Resume polling'
            : job.retry_kind === 'finalize_only'
              ? 'Retry finalize'
              : 'Retry'

    const stall = getStudioAnimationStallHints(job)
    const queueHint =
        typeof job.rollout_diagnostics?.queue_ai === 'string' ? (job.rollout_diagnostics.queue_ai as string) : null
    const headingLabel = studioAnimationRailJobLabel(compositionTitleForLabel, job.id)

    return (
        <div
            className="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 p-4"
            onClick={(e) => {
                if (e.target === e.currentTarget) {
                    onClose()
                }
            }}
            role="presentation"
        >
            <div
                role="dialog"
                aria-labelledby="studio-anim-detail-title"
                className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-start justify-between gap-2">
                    <h2 id="studio-anim-detail-title" className="text-lg font-semibold text-white">
                        {headingLabel}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1 text-gray-500 hover:bg-gray-800 hover:text-gray-300"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <p className="mt-1 text-[11px] uppercase tracking-wide text-violet-300/90">{job.status}</p>
                <p className="mt-1 text-xs text-gray-400" title={statusTitle(job.status, playBackAvailable)}>
                    {statusTitle(job.status, playBackAvailable)}
                </p>

                {stall.level !== 'none' ? (
                    <div
                        className={`mt-3 flex gap-2 rounded-lg border p-3 text-[11px] leading-snug ${
                            stall.level === 'warn'
                                ? 'border-amber-700/80 bg-amber-950/40 text-amber-100/95'
                                : 'border-sky-800/70 bg-sky-950/35 text-sky-100/90'
                        }`}
                        role="status"
                    >
                        {stall.level === 'warn' ? (
                            <ExclamationTriangleIcon className="mt-0.5 h-4 w-4 shrink-0 text-amber-300" aria-hidden />
                        ) : (
                            <InformationCircleIcon className="mt-0.5 h-4 w-4 shrink-0 text-sky-300" aria-hidden />
                        )}
                        <div className="min-w-0 space-y-1.5">
                            {stall.lines.map((line, i) => (
                                <p key={i}>{line}</p>
                            ))}
                        </div>
                    </div>
                ) : null}

                {queueHint ? (
                    <p className="mt-2 text-[10px] text-gray-500">
                        Queue name (diagnostics): <span className="font-mono text-gray-400">{queueHint}</span>
                    </p>
                ) : null}

                <dl className="mt-4 space-y-2 text-xs text-gray-300">
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Typical wait</dt>
                        <dd className="text-right text-gray-200">About 1–4 minutes once a worker starts the job</dd>
                    </div>
                    {job.created_at ? (
                        <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                            <dt className="text-gray-500">Created</dt>
                            <dd className="text-right tabular-nums">{fmtWhen(job.created_at)}</dd>
                        </div>
                    ) : null}
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Credits</dt>
                        <dd className="text-right">
                            {charged ? (
                                <span className="text-emerald-200/90">Charged: {units} units</span>
                            ) : job.status === 'complete' ? (
                                <span className="text-gray-400">Not billed</span>
                            ) : (
                                <span className="text-amber-100/90">Up to {reserved || '—'} if this run completes</span>
                            )}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Started</dt>
                        <dd className="text-right tabular-nums">{fmtWhen(job.started_at)}</dd>
                    </div>
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Finished</dt>
                        <dd className="text-right tabular-nums">{fmtWhen(job.completed_at)}</dd>
                    </div>
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Clip</dt>
                        <dd className="text-right">
                            {job.duration_seconds}s · {job.motion_preset ?? '—'} · {job.aspect_ratio}
                            {job.generate_audio ? ' · audio on' : ''}
                        </dd>
                    </div>
                    {job.status === 'complete' &&
                    job.output?.width != null &&
                    job.output?.height != null &&
                    (job.output.width > 0 || job.output.height > 0) ? (
                        <div className="border-b border-gray-800 pb-2">
                            <dt className="text-gray-500">Delivered file size</dt>
                            <dd className="mt-0.5 text-right text-gray-200">
                                {job.output.width}×{job.output.height}px
                                <span className="block text-[10px] text-gray-500">
                                    Set by the video provider, not your canvas pixel dimensions. Scale or crop in the editor if needed.
                                </span>
                            </dd>
                        </div>
                    ) : null}
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Source</dt>
                        <dd className="text-right text-gray-200">{job.source_strategy}</dd>
                    </div>
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Provider</dt>
                        <dd className="truncate text-right">
                            {job.provider} <span className="text-gray-500">/ {job.provider_model}</span>
                        </dd>
                    </div>
                    {job.prompt ? (
                        <div className="border-b border-gray-800 pb-2">
                            <dt className="text-gray-500">Prompt</dt>
                            <dd className="mt-1 text-gray-200">{job.prompt}</dd>
                        </div>
                    ) : null}
                </dl>

                {job.status === 'complete' && !playBackAvailable ? (
                    <p className="mt-4 text-sm leading-snug text-amber-200/90" role="status">
                        This run is marked done, but there is no video file to play. The library file may have been deleted after
                        the job finished, or the save step did not complete. Create a <strong className="text-gray-100">new</strong>{' '}
                        animation from the document to get another clip, and make sure the AI queue worker and storage (for example
                        S3) are working if this keeps happening.
                    </p>
                ) : null}

                {job.status === 'complete' && playBackAvailable ? (
                    <video
                        src={job.output?.asset_view_url ?? undefined}
                        className="mt-4 w-full rounded-md border border-gray-700"
                        controls
                        playsInline
                        preload="metadata"
                    />
                ) : null}
                {job.status === 'complete' && outputAssetId ? (
                    <p className="mt-3 text-xs leading-snug text-gray-400">
                        This clip is already saved in your brand library as a video asset. Open it to add categories,
                        collections, or approval — same as any upload.
                    </p>
                ) : null}
                {job.status === 'complete' && outputAssetId ? (
                    <Link
                        href={`/app/assets/${encodeURIComponent(outputAssetId)}/view`}
                        className="mt-2 inline-block text-sm font-medium text-violet-300 hover:text-violet-200"
                    >
                        Open in library →
                    </Link>
                ) : null}

                {job.status === 'complete' && playBackAvailable && outputAssetId && job.output?.asset_view_url && compositionId && onInsertOutputAsVideoLayer ? (
                    <div className="mt-4 flex flex-col gap-2">
                        {insertLayerError ? (
                            <p className="text-sm leading-snug text-red-300/90" role="status">
                                {insertLayerError}
                            </p>
                        ) : null}
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Insert clip</p>
                        <div className="space-y-2 rounded-lg border border-gray-800 bg-gray-950/50 p-2.5" role="radiogroup" aria-labelledby={insertGroupId}>
                            <p id={insertGroupId} className="sr-only">
                                How to add this video to the composition
                            </p>
                            <label className="flex cursor-pointer items-start gap-2 text-xs text-gray-200">
                                <input
                                    type="radio"
                                    className="mt-0.5"
                                    name="jp-studio-insert-mode"
                                    checked={insertMode === 'add_back'}
                                    onChange={() => setInsertMode('add_back')}
                                />
                                <span>
                                    <span className="font-medium">Behind current overlays</span>
                                    <span className="mt-0.5 block text-[10px] text-gray-500">New video layer in back — type and logos stay on top.</span>
                                </span>
                            </label>
                            <label className="flex cursor-pointer items-start gap-2 text-xs text-gray-200">
                                <input
                                    type="radio"
                                    className="mt-0.5"
                                    name="jp-studio-insert-mode"
                                    checked={insertMode === 'add_front'}
                                    onChange={() => setInsertMode('add_front')}
                                />
                                <span>
                                    <span className="font-medium">New video layer on top</span>
                                    <span className="mt-0.5 block text-[10px] text-gray-500">Max z-index — over other layers.</span>
                                </span>
                            </label>
                            <label
                                className={`flex items-start gap-2 text-xs ${
                                    sourceLayerReplaceable && job.source_layer_id ? 'cursor-pointer text-gray-200' : 'cursor-not-allowed text-gray-500'
                                }`}
                            >
                                <input
                                    type="radio"
                                    className="mt-0.5"
                                    name="jp-studio-insert-mode"
                                    disabled={!sourceLayerReplaceable || !job.source_layer_id}
                                    checked={insertMode === 'replace_source'}
                                    onChange={() => setInsertMode('replace_source')}
                                />
                                <span>
                                    <span className="font-medium">Replace source layer</span>
                                    <span className="mt-0.5 block text-[10px] text-gray-500">
                                        {sourceLayerReplaceable && job.source_layer_id
                                            ? 'Swap the animated layer in place (same position & stack).'
                                            : 'Not available — source layer missing from the canvas or this run used the full frame.'}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <button
                            type="button"
                            disabled={insertVideoBusy || exportBakedBusy}
                            className="w-full rounded-lg bg-violet-800 px-3 py-2.5 text-sm font-medium text-white hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                            onClick={() => {
                                const url = String(job.output?.asset_view_url ?? '')
                                const endMs = Math.min(
                                    3_600_000,
                                    Math.max(1000, Math.round((job.output?.duration_seconds ?? 5) * 1000))
                                )
                                let mode: StudioVideoInsertMode = insertMode
                                if (mode === 'replace_source' && (!sourceLayerReplaceable || !job.source_layer_id)) {
                                    mode = 'add_back'
                                }
                                const prov = {
                                    sourceMode: job.source_strategy,
                                    provider: job.provider,
                                    model: job.provider_model,
                                    jobId: job.id,
                                    outputAssetId,
                                    durationMs: endMs,
                                }
                                setInsertLayerError(null)
                                setInsertVideoBusy(true)
                                void onInsertOutputAsVideoLayer({
                                    assetId: outputAssetId,
                                    fileUrl: url,
                                    name: 'Animation',
                                    endMs,
                                    mode,
                                    replaceLayerId: job.source_layer_id,
                                    provenance: prov,
                                })
                                    .then(() => {
                                        onClose()
                                    })
                                    .catch((e) => {
                                        setInsertLayerError(
                                            e instanceof Error ? e.message : 'Could not add the video to the composition'
                                        )
                                    })
                                    .finally(() => {
                                        setInsertVideoBusy(false)
                                    })
                            }}
                        >
                            {insertVideoBusy ? 'Inserting…' : 'Insert into composition'}
                        </button>
                        <p className="text-[10px] leading-snug text-gray-500">
                            Baked export uses the <strong className="text-gray-400">primary video for export</strong> (set in
                            layer properties if you have more than one). Image and generated-image layers above that video are
                            composited in. <strong className="text-gray-400">Text layers are not included</strong> in the MP4
                            yet—flatten copy to an image first if it must appear in export. Masks and blend modes are not
                            applied server-side.
                        </p>
                    </div>
                ) : null}

                {job.status === 'complete' && playBackAvailable && compositionId && onExportBakedVideo ? (
                    <div className="mt-3 flex flex-col gap-2">
                        {bakedExportError ? (
                            <p className="text-sm leading-snug text-red-300/90" role="status">
                                {bakedExportError}
                            </p>
                        ) : null}
                        {bakedExportOutputAssetId ? (
                            <p className="text-xs text-emerald-200/90">
                                Baked video saved.{' '}
                                <Link
                                    href={`/app/assets/${encodeURIComponent(bakedExportOutputAssetId)}/view`}
                                    className="font-medium text-violet-300 hover:text-violet-200"
                                >
                                    Open in library →
                                </Link>
                            </p>
                        ) : null}
                        <button
                            type="button"
                            disabled={exportBakedBusy || insertVideoBusy}
                            className="w-full rounded-lg border border-violet-700/60 bg-violet-950/30 px-3 py-2.5 text-sm font-medium text-violet-100 hover:bg-violet-950/50 disabled:cursor-not-allowed disabled:opacity-50"
                            onClick={() => {
                                setBakedExportError(null)
                                setExportBakedBusy(true)
                                void onExportBakedVideo()
                                    .then((id) => {
                                        if (id) {
                                            setBakedExportOutputAssetId(id)
                                        }
                                    })
                                    .catch((e) => {
                                        setBakedExportError(
                                            e instanceof Error ? e.message : 'Baked video export failed'
                                        )
                                    })
                                    .finally(() => {
                                        setExportBakedBusy(false)
                                    })
                            }}
                        >
                            {exportBakedBusy ? 'Exporting…' : 'Export final video (baked MP4)'}
                        </button>
                    </div>
                ) : null}

                {job.status === 'failed' && job.user_facing_error ? (
                    <p className="mt-4 text-sm leading-snug text-red-300/90">{job.user_facing_error}</p>
                ) : null}

                {job.status === 'failed' && job.retry_kind ? (
                    <p className="mt-2 text-[11px] text-gray-500">
                        Recovery: {getStudioAnimationFailureRecoveryLine(job)}
                    </p>
                ) : null}

                {job.status === 'failed' &&
                (job.error_code || job.error_message || (job.last_pipeline_event && Object.keys(job.last_pipeline_event).length > 0)) ? (
                    <details className="mt-3 rounded-lg border border-gray-800 bg-gray-950/60 px-3 py-2 text-[11px] text-gray-400">
                        <summary className="cursor-pointer select-none font-medium text-gray-300 hover:text-gray-200">
                            Technical details
                        </summary>
                        <p className="mt-2 border-t border-gray-800 pt-2 text-[10px] leading-snug text-gray-500">
                            Operators: query <span className="font-mono text-gray-400">studio_animation_jobs</span> (and
                            worker logs) for the same fields when triaging. There is no Studio animation admin index yet.
                        </p>
                        <dl className="mt-2 space-y-1.5">
                            {job.error_code ? (
                                <div>
                                    <dt className="text-[10px] uppercase tracking-wide text-gray-600">error_code</dt>
                                    <dd className="mt-0.5 font-mono text-xs text-amber-100/90">{job.error_code}</dd>
                                </div>
                            ) : null}
                            {job.error_message ? (
                                <div>
                                    <dt className="text-[10px] uppercase tracking-wide text-gray-600">error_message</dt>
                                    <dd className="mt-0.5 whitespace-pre-wrap break-words text-xs text-gray-200">{job.error_message}</dd>
                                </div>
                            ) : null}
                            {job.last_pipeline_event && Object.keys(job.last_pipeline_event).length > 0 ? (
                                <div>
                                    <dt className="text-[10px] uppercase tracking-wide text-gray-600">last_pipeline_event</dt>
                                    <dd className="mt-0.5">
                                        <pre className="max-h-40 overflow-auto rounded border border-gray-800 bg-black/40 p-2 font-mono text-[10px] leading-snug text-gray-300">
                                            {JSON.stringify(job.last_pipeline_event, null, 2)}
                                        </pre>
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                    </details>
                ) : null}

                {job.status === 'failed' ? (
                    <div className="mt-4 flex flex-col gap-2">
                        <button
                            type="button"
                            disabled={retryBusy || discardBusy}
                            className="w-full rounded-lg bg-gray-700 px-3 py-2 text-sm font-medium text-white hover:bg-gray-600 disabled:opacity-50"
                            onClick={() => {
                                setRetryBusy(true)
                                void postStudioAnimationRetry(job.id)
                                    .then(() => {
                                        onJobsUpdated()
                                        onClose()
                                    })
                                    .finally(() => {
                                        setRetryBusy(false)
                                    })
                            }}
                        >
                            {retryBusy ? 'Retrying…' : retryLabel}
                        </button>
                        {onRequestDiscardJob ? (
                            <button
                                type="button"
                                disabled={retryBusy || discardBusy}
                                className="w-full rounded-lg border border-red-900/50 bg-red-950/30 px-3 py-2 text-sm font-medium text-red-100 hover:bg-red-950/50 disabled:opacity-50"
                                onClick={() => {
                                    setDiscardBusy(true)
                                    void onRequestDiscardJob(job.id)
                                        .then((removed) => {
                                            if (removed) {
                                                onJobsUpdated()
                                                onClose()
                                            }
                                        })
                                        .finally(() => {
                                            setDiscardBusy(false)
                                        })
                                }}
                            >
                                {discardBusy ? 'Removing…' : 'Remove from Versions rail'}
                            </button>
                        ) : null}
                    </div>
                ) : null}
                {job.status === 'canceled' && onRequestDiscardJob ? (
                    <button
                        type="button"
                        disabled={discardBusy}
                        className="mt-4 w-full rounded-lg border border-red-900/50 bg-red-950/30 px-3 py-2 text-sm font-medium text-red-100 hover:bg-red-950/50 disabled:opacity-50"
                        onClick={() => {
                            setDiscardBusy(true)
                            void onRequestDiscardJob(job.id)
                                .then((removed) => {
                                    if (removed) {
                                        onJobsUpdated()
                                        onClose()
                                    }
                                })
                                .finally(() => {
                                    setDiscardBusy(false)
                                })
                        }}
                    >
                        {discardBusy ? 'Removing…' : 'Remove from Versions rail'}
                    </button>
                ) : null}

                {job.stale_removable === true && job.status !== 'failed' && job.status !== 'canceled' && onRequestDiscardJob ? (
                    <div className="mt-4 flex flex-col gap-2">
                        <p className="text-xs leading-snug text-amber-100/90">
                            This run has been in progress longer than the server time limit. You can remove it from the
                            list; it will not be billed as a successful render.
                        </p>
                        <button
                            type="button"
                            disabled={discardBusy}
                            className="w-full rounded-lg border border-amber-900/50 bg-amber-950/30 px-3 py-2 text-sm font-medium text-amber-100 hover:bg-amber-950/50 disabled:opacity-50"
                            onClick={() => {
                                setDiscardBusy(true)
                                void onRequestDiscardJob(job.id)
                                    .then((removed) => {
                                        if (removed) {
                                            onJobsUpdated()
                                            onClose()
                                        }
                                    })
                                    .finally(() => {
                                        setDiscardBusy(false)
                                    })
                            }}
                        >
                            {discardBusy ? 'Removing…' : 'Remove stuck run from rail'}
                        </button>
                    </div>
                ) : null}

                <p className="mt-4 text-[10px] leading-snug text-gray-600">
                    Each job saves at most one video. Some retries reuse that file (no duplicate asset, no extra credit
                    charge).
                </p>
            </div>
        </div>
    )
}

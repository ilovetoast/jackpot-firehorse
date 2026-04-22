import { useState } from 'react'
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

type Props = {
    job: StudioAnimationJobDto | null
    open: boolean
    onClose: () => void
    onJobsUpdated: () => void
    /** Failed/canceled only; should confirm then DELETE; resolve true if removed. */
    onRequestDiscardJob?: (jobId: string) => Promise<boolean>
    /** Editor composition title — dialog heading matches Versions rail tiles. */
    compositionTitleForLabel?: string
}

export function StudioAnimationJobDetailDialog(props: Props) {
    const { job, open, onClose, onJobsUpdated, onRequestDiscardJob, compositionTitleForLabel = '' } = props
    const [retryBusy, setRetryBusy] = useState(false)
    const [discardBusy, setDiscardBusy] = useState(false)

    if (!open || !job) {
        return null
    }

    const reserved = typeof job.credits_reserved === 'number' ? job.credits_reserved : 0
    const charged = Boolean(job.credits_charged)
    const units = typeof job.credits_charged_units === 'number' ? job.credits_charged_units : 0
    const playBackAvailable = Boolean(job.output?.asset_view_url)

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
                    <div className="flex justify-between gap-2 border-b border-gray-800 pb-2">
                        <dt className="text-gray-500">Provider</dt>
                        <dd className="truncate text-right">{job.provider}</dd>
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
                    />
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

                <p className="mt-4 text-[10px] leading-snug text-gray-600">
                    Each job saves at most one video. Some retries reuse that file (no duplicate asset, no extra credit
                    charge).
                </p>
            </div>
        </div>
    )
}

import { ArrowPathIcon, FilmIcon } from '@heroicons/react/24/outline'
import { XMarkIcon } from '@heroicons/react/20/solid'
import { getStudioAnimationStallHints, studioAnimationRailJobLabel, type StudioAnimationJobDto } from '../../editorStudioAnimationBridge'

function animStatusLabel(status: string): string {
    if (status === 'queued') return 'Queued'
    if (status === 'rendering') return 'Rendering'
    if (status === 'submitting') return 'Submitting'
    if (status === 'processing') return 'Processing'
    if (status === 'downloading') return 'Downloading'
    if (status === 'finalizing') return 'Finalizing'
    if (status === 'complete') return 'Done'
    if (status === 'failed') return 'Failed'
    if (status === 'canceled') return 'Canceled'
    return status
}

function animBadgeClass(status: string): string {
    if (status === 'complete') return 'bg-emerald-900/50 text-emerald-100 ring-1 ring-emerald-800/50'
    if (status === 'failed') return 'bg-red-950/60 text-red-100 ring-1 ring-red-900/50'
    if (status === 'canceled') return 'bg-gray-800 text-gray-400 ring-1 ring-gray-700'
    if (status === 'rendering' || status === 'submitting') return 'bg-amber-950/50 text-amber-100 ring-1 ring-amber-900/40'
    if (status === 'processing' || status === 'downloading') return 'bg-sky-950/50 text-sky-100 ring-1 ring-sky-900/40'
    if (status === 'finalizing') return 'bg-violet-950/50 text-violet-100 ring-1 ring-violet-900/40'
    return 'bg-gray-800 text-gray-300 ring-1 ring-gray-700'
}

type Props = {
    jobs: StudioAnimationJobDto[]
    loading: boolean
    selectedJobId: string | null
    onSelectJob: (jobId: string) => void
    /** Current composition display name (editor title) for tile labels. */
    compositionTitle?: string
    onRequestDiscardJob?: (jobId: string) => void | Promise<unknown>
}

/**
 * Horizontal “video version” tiles for the Studio Versions rail (matches ~76px version tile width).
 */
export function StudioAnimationRailChips(props: Props) {
    const { jobs, loading, selectedJobId, onSelectJob, compositionTitle = '', onRequestDiscardJob } = props

    if (!loading && jobs.length === 0) {
        return null
    }

    return (
        <>
            {loading && jobs.length === 0 && (
                <div className="flex w-[76px] shrink-0 flex-col items-center gap-0.5 rounded-lg border border-dashed border-gray-700 bg-gray-900/40 p-1">
                    <div className="flex h-14 w-14 items-center justify-center rounded-md border border-gray-700 bg-gray-800/80 text-gray-400">
                        <ArrowPathIcon className="h-6 w-6 animate-spin" aria-hidden />
                    </div>
                    <span className="w-full text-center text-[8px] font-medium leading-tight text-gray-500">Videos</span>
                </div>
            )}
            {jobs.map((a) => {
                const active = selectedJobId === a.id
                const stall = getStudioAnimationStallHints(a)
                const stallRing =
                    stall.level === 'warn'
                        ? 'ring-1 ring-amber-500/70'
                        : stall.level === 'notice'
                          ? 'ring-1 ring-sky-600/50'
                          : ''
                const canDiscard =
                    Boolean(onRequestDiscardJob) && (a.status === 'failed' || a.status === 'canceled')
                const tileLabel = studioAnimationRailJobLabel(compositionTitle, a.id)
                return (
                    <div
                        key={a.id}
                        className={`relative flex w-[76px] shrink-0 flex-col items-center gap-0.5 rounded-lg p-1 ${
                            active ? 'bg-violet-950/35 ring-1 ring-violet-600/50' : `bg-gray-900/80 ${stallRing}`
                        }`}
                    >
                        {canDiscard ? (
                            <button
                                type="button"
                                data-testid={`studio-discard-animation-${a.id}`}
                                title="Remove this run from the rail"
                                aria-label="Remove failed video from rail"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    void onRequestDiscardJob?.(a.id)
                                }}
                                className="absolute -right-0.5 -top-0.5 z-[3] flex h-6 w-6 items-center justify-center rounded-full bg-gray-950/95 text-red-300 shadow-md ring-1 ring-red-800/70 hover:bg-red-950/90 hover:text-red-100"
                            >
                                <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                            </button>
                        ) : null}
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation()
                                onSelectJob(a.id)
                            }}
                            title={
                                stall.level !== 'none'
                                    ? 'Taking longer than usual — click for details'
                                    : 'View status, credits, and preview'
                            }
                            className="flex w-full flex-col items-center gap-1 rounded-md p-0.5 text-left transition-colors hover:bg-gray-800/90"
                        >
                            <div className="relative flex h-14 w-14 items-center justify-center overflow-hidden rounded-md bg-gradient-to-br from-violet-950 to-gray-900 ring-1 ring-violet-800/40">
                                <FilmIcon className="h-7 w-7 text-violet-200/90" aria-hidden />
                                <span
                                    className={`absolute bottom-0.5 left-1/2 z-[1] max-w-[68px] -translate-x-1/2 truncate rounded px-1 py-px text-[7px] font-bold uppercase tracking-wide ${animBadgeClass(a.status)}`}
                                >
                                    {animStatusLabel(a.status)}
                                </span>
                            </div>
                            <span
                                className="line-clamp-2 w-full break-words text-center text-[8px] font-semibold leading-tight text-violet-100/90"
                                title={tileLabel}
                            >
                                {tileLabel}
                            </span>
                        </button>
                    </div>
                )
            })}
        </>
    )
}

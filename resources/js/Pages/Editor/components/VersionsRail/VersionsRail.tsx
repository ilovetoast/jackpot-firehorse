import type { StudioCreativeSetDto, StudioCreativeSetVariantDto } from '../../studioCreativeSetTypes'

function statusRingClass(status: string, isActive: boolean): string {
    if (isActive) {
        return 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'generating') {
        return 'ring-2 ring-amber-500/70 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'failed') {
        return 'ring-2 ring-red-500/70 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'draft') {
        return 'ring-1 ring-gray-600 ring-offset-2 ring-offset-gray-950'
    }
    return 'ring-1 ring-gray-700 ring-offset-2 ring-offset-gray-950'
}

export function VersionsRail(props: {
    creativeSet: StudioCreativeSetDto
    activeCompositionId: string | null
    onSelectComposition: (compositionId: string) => void
    onDuplicateFromCurrent: () => void
    duplicateBusy: boolean
    onRetryVersion?: (generationJobItemId: string) => void
    retryBusyItemId?: string | null
}) {
    const {
        creativeSet,
        activeCompositionId,
        onSelectComposition,
        onDuplicateFromCurrent,
        duplicateBusy,
        onRetryVersion,
        retryBusyItemId,
    } = props

    return (
        <div className="flex shrink-0 flex-col border-t border-gray-800 bg-gray-950 px-3 py-2">
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500">Versions</p>
                    <p className="truncate text-[11px] font-medium text-gray-300" title={creativeSet.name}>
                        {creativeSet.name}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => onDuplicateFromCurrent()}
                    disabled={duplicateBusy || !activeCompositionId}
                    className="shrink-0 rounded-md border border-gray-700 bg-gray-900 px-2 py-1 text-[10px] font-semibold text-gray-200 hover:border-gray-500 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    title="Duplicate the open version into a new composition in this set"
                >
                    {duplicateBusy ? 'Duplicating…' : 'Duplicate version'}
                </button>
            </div>
            <div className="flex gap-2 overflow-x-auto pb-1 pt-0.5" style={{ scrollbarWidth: 'thin' }}>
                {creativeSet.variants.map((v: StudioCreativeSetVariantDto) => {
                    const active = activeCompositionId === v.composition_id
                    const retryId = v.retryable_generation_job_item_id ?? null
                    const canRetry = Boolean(retryId && onRetryVersion)
                    return (
                        <div
                            key={v.id}
                            className={`flex w-[76px] shrink-0 flex-col items-center gap-0.5 rounded-lg p-1 ${
                                active ? 'bg-gray-800' : 'bg-gray-900/80'
                            }`}
                        >
                            <button
                                type="button"
                                onClick={() => onSelectComposition(v.composition_id)}
                                title={v.label || `Composition ${v.composition_id}`}
                                className="flex w-full flex-col items-center gap-1 rounded-md p-0.5 text-left transition-colors hover:bg-gray-800/90"
                            >
                                <div
                                    className={`relative h-14 w-14 overflow-hidden rounded-md bg-gray-800 ${statusRingClass(v.status, active)}`}
                                >
                                    {v.thumbnail_url ? (
                                        <img src={v.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center text-[9px] text-gray-600">
                                            No preview
                                        </div>
                                    )}
                                    {v.status === 'generating' && (
                                        <span className="absolute inset-0 flex items-center justify-center bg-black/40 text-[9px] font-semibold text-amber-200">
                                            …
                                        </span>
                                    )}
                                    {v.status === 'failed' && (
                                        <span className="absolute inset-0 flex items-center justify-center bg-black/50 text-[9px] font-semibold text-red-300">
                                            !
                                        </span>
                                    )}
                                </div>
                                <span className="line-clamp-2 w-full text-center text-[9px] font-medium leading-tight text-gray-400">
                                    {v.label || `Version ${v.sort_order + 1}`}
                                </span>
                            </button>
                            {canRetry && (
                                <button
                                    type="button"
                                    disabled={retryBusyItemId === retryId}
                                    onClick={(e) => {
                                        e.stopPropagation()
                                        onRetryVersion?.(retryId!)
                                    }}
                                    className="w-full rounded border border-red-900/50 bg-red-950/40 px-1 py-0.5 text-[9px] font-semibold text-red-200 hover:bg-red-950/70 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {retryBusyItemId === retryId ? 'Retrying…' : 'Retry version'}
                                </button>
                            )}
                        </div>
                    )
                })}
            </div>
        </div>
    )
}

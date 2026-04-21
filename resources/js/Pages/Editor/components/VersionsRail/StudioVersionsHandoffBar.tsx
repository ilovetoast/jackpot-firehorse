import { ArrowDownTrayIcon } from '@heroicons/react/24/outline'

type Props = {
    selectedCount: number
    variantCount: number
    heroCompositionId: string | null
    packNewcomerCompositionIds: string[]
    exportBusy: boolean
    exportPhase: 'idle' | 'capturing' | 'zipping' | 'downloading'
    exportDetail: string
    sameFormatDisabled: boolean
    sameFormatTitle: string
    onDone: () => void
    onClearSelection: () => void
    onSelectAll: () => void
    onSelectHero: () => void
    onSelectNew: () => void
    onSelectSameFormat: () => void
    onExportSelectedPng: () => void
    onExportSelectedJpeg: () => void
    onExportHeroPng: () => void
    onExportHeroJpeg: () => void
    onExportHeroAndAlternatesPng: () => void
    onExportHeroAndAlternatesJpeg: () => void
    canExportHeroPlus: boolean
}

export function StudioVersionsHandoffBar(props: Props) {
    const {
        selectedCount,
        variantCount,
        heroCompositionId,
        packNewcomerCompositionIds,
        exportBusy,
        exportPhase,
        exportDetail,
        sameFormatDisabled,
        sameFormatTitle,
        onDone,
        onClearSelection,
        onSelectAll,
        onSelectHero,
        onSelectNew,
        onSelectSameFormat,
        onExportSelectedPng,
        onExportSelectedJpeg,
        onExportHeroPng,
        onExportHeroJpeg,
        onExportHeroAndAlternatesPng,
        onExportHeroAndAlternatesJpeg,
        canExportHeroPlus,
    } = props

    const hasHero = Boolean(heroCompositionId)
    const hasNewcomers = packNewcomerCompositionIds.length > 0

    return (
        <div className="mb-1.5 flex flex-col gap-1.5 rounded-md border border-sky-900/50 bg-sky-950/25 px-2 py-1.5">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-[10px] font-semibold text-sky-100/90">Handoff</span>
                <span className="text-[10px] text-sky-200/80">
                    {selectedCount} of {variantCount} selected
                </span>
                <button
                    type="button"
                    onClick={() => onDone()}
                    disabled={exportBusy}
                    className="ml-auto rounded border border-sky-700/60 bg-sky-900/40 px-1.5 py-0.5 text-[9px] font-semibold text-sky-50 hover:bg-sky-900/60 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Done
                </button>
            </div>
            <div className="flex flex-wrap gap-1">
                <button
                    type="button"
                    disabled={exportBusy}
                    onClick={() => onSelectHero()}
                    className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    title={hasHero ? 'Select the hero version' : 'Set a hero on a tile first'}
                >
                    Hero
                </button>
                <button
                    type="button"
                    disabled={exportBusy || !hasNewcomers}
                    onClick={() => onSelectNew()}
                    className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    title="Select versions from the latest generated pack"
                >
                    New
                </button>
                <button
                    type="button"
                    disabled={exportBusy}
                    onClick={() => onSelectAll()}
                    className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    All
                </button>
                <button
                    type="button"
                    disabled={exportBusy || sameFormatDisabled}
                    title={sameFormatTitle}
                    onClick={() => onSelectSameFormat()}
                    className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Same format
                </button>
                <button
                    type="button"
                    disabled={exportBusy || selectedCount === 0}
                    onClick={() => onClearSelection()}
                    className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Clear
                </button>
            </div>
            <div className="flex flex-wrap items-center gap-1 border-t border-sky-900/40 pt-1.5">
                <div className="w-full">
                    <span className="text-[9px] font-medium text-sky-200/70">Export</span>
                    <p className="text-[8px] leading-snug text-sky-200/55">
                        One file when a single version is selected; otherwise one ZIP (hero first when marked).
                    </p>
                </div>
                <button
                    type="button"
                    disabled={exportBusy || selectedCount < 1}
                    onClick={() => onExportSelectedPng()}
                    className="inline-flex items-center gap-0.5 rounded border border-sky-700/60 bg-sky-900/35 px-1.5 py-0.5 text-[9px] font-semibold text-sky-50 hover:bg-sky-900/55 disabled:cursor-not-allowed disabled:opacity-40"
                    title={
                        selectedCount > 1
                            ? 'ZIP: all selected versions as PNG'
                            : 'Download PNG for the selected version'
                    }
                >
                    <ArrowDownTrayIcon className="h-3 w-3" aria-hidden />
                    Selected PNG{selectedCount > 1 ? ' (ZIP)' : ''}
                </button>
                <button
                    type="button"
                    disabled={exportBusy || selectedCount < 1}
                    onClick={() => onExportSelectedJpeg()}
                    className="inline-flex items-center gap-0.5 rounded border border-sky-700/60 bg-sky-900/35 px-1.5 py-0.5 text-[9px] font-semibold text-sky-50 hover:bg-sky-900/55 disabled:cursor-not-allowed disabled:opacity-40"
                    title={selectedCount > 1 ? 'ZIP: all selected versions as JPG' : 'Download JPG for the selected version'}
                >
                    <ArrowDownTrayIcon className="h-3 w-3" aria-hidden />
                    Selected JPG{selectedCount > 1 ? ' (ZIP)' : ''}
                </button>
                <button
                    type="button"
                    disabled={exportBusy || !hasHero}
                    onClick={() => onExportHeroPng()}
                    className="inline-flex items-center gap-0.5 rounded border border-amber-900/50 bg-amber-950/30 px-1.5 py-0.5 text-[9px] font-semibold text-amber-100 hover:bg-amber-950/50 disabled:cursor-not-allowed disabled:opacity-40"
                    title="Single-file PNG export of the hero"
                >
                    Hero PNG
                </button>
                <button
                    type="button"
                    disabled={exportBusy || !hasHero}
                    onClick={() => onExportHeroJpeg()}
                    className="inline-flex items-center gap-0.5 rounded border border-amber-900/50 bg-amber-950/30 px-1.5 py-0.5 text-[9px] font-semibold text-amber-100 hover:bg-amber-950/50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Hero JPG
                </button>
                <button
                    type="button"
                    disabled={exportBusy || !canExportHeroPlus}
                    onClick={() => onExportHeroAndAlternatesPng()}
                    className="inline-flex items-center gap-0.5 rounded border border-amber-900/40 bg-gray-900/60 px-1.5 py-0.5 text-[9px] font-semibold text-amber-50/95 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    title="ZIP: hero first, then other selected versions (PNG)"
                >
                    Hero + alts PNG (ZIP)
                </button>
                <button
                    type="button"
                    disabled={exportBusy || !canExportHeroPlus}
                    onClick={() => onExportHeroAndAlternatesJpeg()}
                    className="inline-flex items-center gap-0.5 rounded border border-amber-900/40 bg-gray-900/60 px-1.5 py-0.5 text-[9px] font-semibold text-amber-50/95 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    title="ZIP: hero first, then other selected versions (JPG)"
                >
                    Hero + alts JPG (ZIP)
                </button>
            </div>
            {exportBusy && (
                <div className="rounded border border-sky-800/50 bg-sky-950/40 px-2 py-1 text-[9px] text-sky-100/90">
                    <p className="font-semibold">
                        {exportPhase === 'capturing' && 'Preparing bundle — capturing…'}
                        {exportPhase === 'zipping' && 'Preparing bundle — building ZIP…'}
                        {exportPhase === 'downloading' && 'Bundle ready — downloading…'}
                        {exportPhase === 'idle' && 'Working…'}
                    </p>
                    {exportDetail ? <p className="mt-0.5 text-sky-200/85">{exportDetail}</p> : null}
                </div>
            )}
        </div>
    )
}

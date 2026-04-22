import { FilmIcon } from '@heroicons/react/24/outline'

/**
 * Lightweight launcher: outcome-first entry to Versions creation (wraps existing generation + duplicate).
 */
type Props = {
    open: boolean
    onClose: () => void
    /** Social-size pack: square, portrait, story — opens advanced generate prefilled. */
    onChooseFormatPack: () => void
    /** All preset colors — opens generate prefilled. */
    onChooseColorPack: () => void
    /** Curated studio / lifestyle / outdoor / minimal — opens generate prefilled. */
    onChooseScenePack: () => void
    /** Manual duplicate of the open composition. */
    onChooseDuplicateCurrent: () => void
    duplicateBusy: boolean
    /** True while a quick pack is resolving presets (disables pack buttons). */
    packBusy?: boolean
    /** Full color × scene × format picker. */
    onChooseAdvanced: () => void
    /** Short clip from the current composition (opens animate modal). */
    onChooseAnimateVideo: () => void
}

export function VersionBuilderModal(props: Props) {
    const {
        open,
        onClose,
        onChooseFormatPack,
        onChooseColorPack,
        onChooseScenePack,
        onChooseDuplicateCurrent,
        duplicateBusy,
        packBusy = false,
        onChooseAdvanced,
        onChooseAnimateVideo,
    } = props

    if (!open) {
        return null
    }

    return (
        <div className="fixed inset-0 z-[101] flex items-center justify-center bg-black/60 p-4">
            <div
                role="dialog"
                aria-labelledby="version-builder-title"
                data-testid="version-builder-dialog"
                className="w-full max-w-md rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="version-builder-title" className="text-lg font-semibold text-white">
                    Create versions
                </h2>
                <p className="mt-1 text-sm text-gray-400">
                    Pick what you want to add to this set. We’ll use your open creative as the starting point.
                </p>

                <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <button
                        type="button"
                        data-testid="version-builder-format-pack"
                        onClick={() => onChooseFormatPack()}
                        disabled={packBusy}
                        className="rounded-lg border-2 border-indigo-500/70 bg-indigo-950/40 p-3 text-left transition-colors hover:border-indigo-400 hover:bg-indigo-950/60 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <p className="text-sm font-semibold text-white">Format pack</p>
                        <p className="mt-0.5 text-[11px] leading-snug text-indigo-100/90">
                            Square, portrait &amp; story — ideal for social campaigns.
                        </p>
                    </button>
                    <button
                        type="button"
                        data-testid="version-builder-color-pack"
                        onClick={() => onChooseColorPack()}
                        disabled={packBusy}
                        className="rounded-lg border border-gray-600 bg-gray-800/80 p-3 text-left transition-colors hover:border-gray-500 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <p className="text-sm font-semibold text-gray-100">Color pack</p>
                        <p className="mt-0.5 text-[11px] leading-snug text-gray-400">
                            Product colorways from every preset swatch.
                        </p>
                    </button>
                    <button
                        type="button"
                        data-testid="version-builder-scene-pack"
                        onClick={() => onChooseScenePack()}
                        disabled={packBusy}
                        className="rounded-lg border border-gray-600 bg-gray-800/80 p-3 text-left transition-colors hover:border-gray-500 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <p className="text-sm font-semibold text-gray-100">Scene pack</p>
                        <p className="mt-0.5 text-[11px] leading-snug text-gray-400">
                            Studio, indoor lifestyle, outdoor, and minimal looks.
                        </p>
                    </button>
                    <button
                        type="button"
                        onClick={() => onChooseDuplicateCurrent()}
                        disabled={duplicateBusy}
                        className="rounded-lg border border-dashed border-gray-600 bg-gray-900/60 p-3 text-left transition-colors hover:border-gray-500 hover:bg-gray-800/80 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <p className="text-sm font-semibold text-gray-200">Duplicate current</p>
                        <p className="mt-0.5 text-[11px] leading-snug text-gray-500">
                            Same layout, no generation — for a manual branch.
                        </p>
                    </button>
                    <button
                        type="button"
                        data-testid="version-builder-animate-video"
                        onClick={() => onChooseAnimateVideo()}
                        disabled={packBusy}
                        className="rounded-lg border border-violet-600/50 bg-violet-950/30 p-3 text-left transition-colors hover:border-violet-500/70 hover:bg-violet-950/45 disabled:cursor-not-allowed disabled:opacity-40 sm:col-span-2"
                    >
                        <div className="flex items-start gap-2">
                            <FilmIcon className="mt-0.5 h-5 w-5 shrink-0 text-violet-300" aria-hidden />
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-white">Animate video</p>
                                <p className="mt-0.5 text-[11px] leading-snug text-violet-100/85">
                                    One clip from this composition — appears in the Versions rail; usually a few minutes.
                                    In Animate, a <strong className="font-semibold text-violet-50">background-only</strong>{' '}
                                    start frame (no type in the shot) often looks best; you can still send the{' '}
                                    <strong className="font-semibold text-violet-50">full canvas</strong> if you need the
                                    full layout.
                                </p>
                            </div>
                        </div>
                    </button>
                </div>

                <button
                    type="button"
                    onClick={() => onChooseAdvanced()}
                    disabled={packBusy}
                    className="mt-3 w-full rounded-lg border border-gray-600 py-2 text-sm font-medium text-gray-200 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Advanced setup…
                </button>
                <p className="mt-1 text-center text-[10px] text-gray-500">
                    Grouped format presets (social, marketplace, web, and more), plus colors, scenes, and combination
                    toggles.
                </p>

                <div className="mt-4 flex justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-300 hover:bg-gray-800"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    )
}

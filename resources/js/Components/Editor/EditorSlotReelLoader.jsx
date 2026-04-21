/**
 * Compact slot-reel loader for Studio AI (matches marketing homepage asset paths).
 * Loops diamond → cherry → 7 infinitely via a duplicated vertical strip.
 */
const SLOT_SRC = {
    diamond: '/jp-parts/diamond-slot.svg',
    cherry: '/jp-parts/cherry-slot.svg',
    seven: '/jp-parts/seven-slot.svg',
}

const REEL_KEYS = ['diamond', 'cherry', 'seven']
const CELL_PX = 44

export default function EditorSlotReelLoader({
    className = '',
    /** Line under the reel, e.g. "Editing…" */
    label,
    /** Extra content below the label (e.g. variation thumbnails) */
    children,
}) {
    const strip = [...REEL_KEYS, ...REEL_KEYS]

    return (
        <div className={`flex flex-col items-center gap-2 ${className}`}>
            <div
                className="editor-slot-reel-frame flex-shrink-0 overflow-hidden rounded-lg bg-white shadow-inner ring-1 ring-indigo-400/45 dark:ring-indigo-500/40"
                style={{ width: Math.round(CELL_PX * 0.92), height: CELL_PX }}
            >
                <div className="editor-slot-reel-track flex flex-col will-change-transform">
                    {strip.map((key, i) => (
                        <div
                            key={`${key}-${i}`}
                            className="flex shrink-0 items-center justify-center"
                            style={{ height: CELL_PX, padding: '10%' }}
                        >
                            <img
                                src={SLOT_SRC[key]}
                                alt=""
                                className="h-full w-full object-contain invert select-none"
                                draggable={false}
                            />
                        </div>
                    ))}
                </div>
            </div>
            {label ? (
                <span className="text-center text-xs font-semibold tracking-wide text-gray-800 dark:text-gray-100">
                    {label}
                </span>
            ) : null}
            {children}
        </div>
    )
}

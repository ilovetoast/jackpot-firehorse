/**
 * Right-pane placeholder while overview asset collage JSON loads.
 * Matches AssetCollage layout contract (see AssetCollage.jsx).
 */
export default function OverviewCollageSkeleton() {
    return (
        <div
            className="absolute right-0 bottom-0 h-full w-[38%] pointer-events-none hidden lg:block overflow-hidden"
            style={{
                contain: 'layout paint',
                maskImage: 'linear-gradient(to bottom, transparent 0%, black 22%, black 85%, transparent 100%)',
                WebkitMaskImage: 'linear-gradient(to bottom, transparent 0%, black 22%, black 85%, transparent 100%)',
                maskSize: '100% 100%',
                maskRepeat: 'no-repeat',
                maskPosition: 'center',
            }}
            aria-hidden
        >
            <div className="absolute inset-0 grid grid-cols-3 gap-8 justify-items-center px-6 pb-4 items-end">
                {[0, 1, 2].map((ci) => (
                    <div
                        key={ci}
                        className="flex w-full max-w-full flex-col-reverse justify-end gap-3"
                        style={{ marginBottom: ['-42%', '0%', '48%'][ci] ?? '0%' }}
                    >
                        {[0, 1, 2].map((ii) => (
                            <div
                                key={ii}
                                className="w-full animate-pulse rounded-2xl bg-white/[0.07] ring-1 ring-white/[0.06]"
                                style={{ aspectRatio: '4/5' }}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    )
}

/**
 * Public Page Theme Preview
 * Simulates hero header, logo, accent CTA, background, download card.
 * Live updates when accent color, logo, or background changes.
 * Logo uses transparent display (no gray block) — logoUrl uses medium style.
 */
export default function PublicPageThemePreview({ logoUrl, logoUrlFallback, accentColor = '#6366f1', backgroundUrl }) {
    return (
        <div className="rounded-lg border border-gray-200 overflow-hidden bg-gray-50 shadow-inner">
            <div
                className="relative min-h-[240px] flex flex-col"
                style={{
                    backgroundImage: backgroundUrl ? `url(${backgroundUrl})` : undefined,
                    backgroundSize: 'cover',
                    backgroundPosition: 'center',
                }}
            >
                {/* Overlay when background image */}
                {backgroundUrl && (
                    <div className="absolute inset-0 bg-black/30" />
                )}
                <div className="relative flex-1 p-4 flex flex-col">
                    {/* Logo top left — no bg block; transparent as on live public page */}
                    <div className="flex-shrink-0 mb-4">
                        {logoUrl ? (
                            <img
                                src={logoUrl}
                                alt=""
                                className="h-8 w-auto max-w-[120px] object-contain object-left"
                                onError={(e) => {
                                    if (logoUrlFallback && e.target.src !== logoUrlFallback) {
                                        e.target.src = logoUrlFallback
                                    }
                                }}
                            />
                        ) : (
                            <div className="h-8 w-24 rounded bg-white/20 flex items-center justify-center">
                                <span className="text-[10px] font-medium text-white/90">Logo</span>
                            </div>
                        )}
                    </div>
                    {/* Hero area */}
                    <div className="flex-1 flex flex-col justify-center">
                        <div className="h-4 w-3/4 bg-white/20 rounded mb-3 max-w-[140px]" />
                        <div className="h-3 w-1/2 bg-white/15 rounded mb-4 max-w-[100px]" />
                        {/* Accent CTA button */}
                        <button
                            type="button"
                            className="self-start px-4 py-2 rounded-md text-sm font-medium text-white shadow-sm w-24"
                            style={{ backgroundColor: accentColor }}
                        >
                            CTA
                        </button>
                    </div>
                    {/* Download card preview */}
                    <div className="mt-4 rounded-lg bg-white/95 backdrop-blur-sm p-3 shadow-sm">
                        <div className="h-2 w-full bg-gray-200 rounded mb-2" />
                        <div className="h-2 w-4/5 bg-gray-100 rounded" />
                    </div>
                </div>
            </div>
        </div>
    )
}

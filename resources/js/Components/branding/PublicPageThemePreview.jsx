/**
 * Public Page Theme Preview — realistic mock of a public download / campaign surface.
 * Updates with logo, accent, and hero background. Taller frame so the layout does not feel squished.
 */
export default function PublicPageThemePreview({ logoUrl, logoUrlFallback, accentColor = '#6366f1', backgroundUrl }) {
    const hasPhoto = Boolean(backgroundUrl)
    const lineStrong = hasPhoto ? 'bg-white/35' : 'bg-slate-300/90'
    const lineSoft = hasPhoto ? 'bg-white/22' : 'bg-slate-200/90'
    const lineFaint = hasPhoto ? 'bg-white/12' : 'bg-slate-200/60'

    return (
        <div className="rounded-2xl border border-slate-200/90 bg-slate-50/80 shadow-[0_12px_40px_-12px_rgba(15,23,42,0.12)] overflow-hidden">
            <div
                className="relative min-h-[min(22rem,55vw)] sm:min-h-[24rem] flex flex-col"
                style={{
                    backgroundImage: hasPhoto ? `url(${backgroundUrl})` : undefined,
                    backgroundSize: 'cover',
                    backgroundPosition: 'center',
                    ...(!hasPhoto
                        ? {
                              background: 'linear-gradient(165deg, #f8fafc 0%, #eef2f7 40%, #e2e8f0 100%)',
                          }
                        : {}),
                }}
            >
                {hasPhoto && <div className="pointer-events-none absolute inset-0 bg-gradient-to-b from-black/55 via-black/20 to-black/50" />}

                {/* Light browser / window chrome */}
                <div
                    className={`relative z-10 flex items-center gap-1.5 px-3 py-2.5 border-b ${
                        hasPhoto ? 'border-white/15 bg-black/25 backdrop-blur-md' : 'border-slate-200/80 bg-white/60 backdrop-blur-sm'
                    }`}
                >
                    <span className={`h-2 w-2 rounded-full ${hasPhoto ? 'bg-red-400/90' : 'bg-red-400/70'}`} />
                    <span className={`h-2 w-2 rounded-full ${hasPhoto ? 'bg-amber-300/90' : 'bg-amber-300/70'}`} />
                    <span className={`h-2 w-2 rounded-full ${hasPhoto ? 'bg-emerald-400/80' : 'bg-emerald-500/50'}`} />
                    <div
                        className={`ml-2 h-5 flex-1 max-w-[9rem] rounded-md ${hasPhoto ? 'bg-white/12' : 'bg-slate-200/90'}`}
                    />
                </div>

                <div className="relative z-10 flex flex-1 flex-col p-5 sm:p-6">
                    {/* Brand bar */}
                    <div className="mb-5 flex min-h-[2.25rem] items-start">
                        {logoUrl ? (
                            <img
                                src={logoUrl}
                                alt=""
                                className="h-9 w-auto max-w-[9rem] object-contain object-left drop-shadow sm:h-10 sm:max-w-[11rem]"
                                style={hasPhoto ? { filter: 'drop-shadow(0 1px 2px rgba(0,0,0,0.35))' } : undefined}
                                onError={(e) => {
                                    if (logoUrlFallback && e.target.src !== logoUrlFallback) {
                                        e.target.src = logoUrlFallback
                                    }
                                }}
                            />
                        ) : (
                            <div
                                className={`flex h-9 items-center justify-center rounded-lg border px-3 ${
                                    hasPhoto
                                        ? 'border-white/25 bg-white/10'
                                        : 'border-slate-200 bg-white/80'
                                }`}
                            >
                                <span className={`text-[10px] font-semibold tracking-wide ${hasPhoto ? 'text-white/90' : 'text-slate-500'}`}>
                                    Brand
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Hero copy + primary action */}
                    <div className="flex flex-1 flex-col justify-center gap-3 pb-2">
                        <div className="space-y-2.5 max-w-xs">
                            <div className={`h-2.5 w-44 rounded-full ${lineStrong}`} />
                            <div className={`h-2 w-32 rounded-full ${lineSoft}`} />
                            <div className={`h-1.5 w-24 rounded-full ${lineFaint}`} />
                        </div>
                        <div className="pt-1">
                            <div
                                role="presentation"
                                className="inline-flex items-center justify-center rounded-lg px-5 py-2.5 text-xs font-semibold text-white shadow-md"
                                style={{
                                    backgroundColor: accentColor,
                                    boxShadow: `0 6px 20px -4px ${accentColor}88`,
                                }}
                            >
                                Download
                            </div>
                        </div>
                    </div>

                    {/* Download / asset card — full width, breathing room from bottom edge */}
                    <div className="mt-4 border-t border-slate-200/50 pt-5 sm:mt-5 sm:pt-6">
                        <div
                            className={`rounded-xl border p-4 shadow-sm ${
                                hasPhoto ? 'border-white/20 bg-white/95 backdrop-blur-sm' : 'border-slate-200/90 bg-white'
                            }`}
                        >
                            <div className="mb-3 flex items-center justify-between gap-2">
                                <div className="h-2 w-20 rounded bg-slate-200" />
                                <div className="h-1.5 w-12 rounded bg-slate-100" />
                            </div>
                            <div className="flex items-center gap-3 rounded-lg border border-slate-100 bg-slate-50/80 p-3">
                                <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-200/80">
                                    <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                    </svg>
                                </div>
                                <div className="min-w-0 flex-1 space-y-1.5">
                                    <div className="h-2 w-[85%] max-w-[11rem] rounded bg-slate-200" />
                                    <div className="h-1.5 w-[55%] max-w-[6rem] rounded bg-slate-100" />
                                </div>
                                <div
                                    className="hidden h-7 w-14 flex-shrink-0 rounded-md sm:block"
                                    style={{ backgroundColor: accentColor, opacity: 0.85 }}
                                    aria-hidden
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}

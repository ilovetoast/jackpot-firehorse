/**
 * Shared gateway preview renderer — uses the exact same visual structure
 * as the real EnterTransition / InviteAccept pages (reduced / static mode).
 * Import this in portal settings panels so preview ≡ production.
 *
 * @see docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md — entry style + destination are system-driven in production preview.
 */
function previewTagline(entry, brandDnaTagline) {
    const s = entry?.tagline_source
    if (s === 'hidden') {
        return null
    }
    if (s === 'custom') {
        const o = entry?.tagline_override
        return o && String(o).trim() ? String(o).trim() : null
    }
    if (s === 'brand') {
        return brandDnaTagline && String(brandDnaTagline).trim() ? String(brandDnaTagline).trim() : null
    }
    if (entry?.tagline_override && String(entry.tagline_override).trim()) {
        return String(entry.tagline_override).trim()
    }
    if (brandDnaTagline && String(brandDnaTagline).trim()) {
        return String(brandDnaTagline).trim()
    }
    return null
}

export function EntryPreview({ brand, entry, brandDnaTagline = null }) {
    const primary = brand?.primary_color || '#6366f1'
    const name = brand?.name || 'Brand'
    const letter = name.charAt(0).toUpperCase()
    const style = 'cinematic'
    const tagline = previewTagline(entry, brandDnaTagline)

    return (
        <div
            className="relative overflow-hidden rounded-xl text-center"
            style={{
                background: `radial-gradient(circle at 20% 20%, ${primary}33, transparent), radial-gradient(circle at 80% 80%, ${primary}22, transparent), #0B0B0D`,
            }}
        >
            {/* Cinematic overlay layers — matches GatewayLayout */}
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/30" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/40" />
            </div>

            <div className="relative z-10 py-10 px-6">
                {/* Logo fallback — matches EnterTransition */}
                <div className="mx-auto mb-4 flex justify-center">
                    <div
                        className="h-14 w-14 rounded-2xl flex items-center justify-center"
                        style={{ background: `linear-gradient(135deg, ${primary}CC, ${primary}55)` }}
                    >
                        <span className="text-xl font-bold text-white">{letter}</span>
                    </div>
                </div>

                <h4 className="text-lg font-semibold tracking-tight text-white/90">{name}</h4>

                {tagline && (
                    <p className="text-xs text-white/50 mt-1 max-w-xs mx-auto">{tagline}</p>
                )}

                <p className="text-[10px] text-white/30 mt-1 tracking-wide">
                    Entering {name}
                </p>

                {/* Progress bar — matches EnterTransition */}
                {style === 'cinematic' && (
                    <div className="mx-auto mt-4 w-32 h-0.5 bg-white/10 rounded overflow-hidden">
                        <div
                            className="h-full w-2/3 rounded"
                            style={{ backgroundColor: primary }}
                        />
                    </div>
                )}

                {/* Style / destination badges — product defaults */}
                <div className="mt-4 flex justify-center gap-2">
                    <span className="text-[10px] px-3 py-1 rounded-md bg-white/10 text-white/60">Cinematic</span>
                    <span className="text-[10px] px-3 py-1 rounded-md bg-white/10 text-white/60">→ Overview</span>
                </div>
            </div>
        </div>
    )
}

export function InvitePreview({ brand, invite }) {
    const primary = brand?.primary_color || '#6366f1'
    const name = brand?.name || 'Brand'

    const headline = invite?.headline || `Welcome to ${name}`
    const subtext = invite?.subtext || null
    const ctaLabel = invite?.cta_label || 'Accept & Enter'

    return (
        <div
            className="relative overflow-hidden rounded-xl text-center"
            style={{
                background: `radial-gradient(circle at 20% 20%, ${primary}33, transparent), radial-gradient(circle at 80% 80%, ${primary}22, transparent), #0B0B0D`,
            }}
        >
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/30" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/40" />
            </div>

            <div className="relative z-10 py-10 px-6 max-w-xs mx-auto">
                <div className="mx-auto h-12 w-12 rounded-2xl bg-white/[0.06] flex items-center justify-center mb-4">
                    <svg className="w-6 h-6 text-white/50" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                    </svg>
                </div>
                <h4 className="text-base font-semibold text-white/90">{headline}</h4>
                {subtext && (
                    <p className="text-xs text-white/50 mt-2">{subtext}</p>
                )}
                <div className="mt-5">
                    <span
                        className="inline-block text-xs font-semibold text-white px-5 py-2 rounded-lg"
                        style={{ backgroundColor: primary }}
                    >
                        {ctaLabel}
                    </span>
                </div>
            </div>
        </div>
    )
}

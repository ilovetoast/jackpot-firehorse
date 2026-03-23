/**
 * Brand Intelligence debug visualization: color regions, logo detections, optional attention map, top reference thumbnails.
 * Coordinates are normalized 0–1 relative to the preview container (matches server payload).
 */

const ORANGE = 'rgba(249, 115, 22, 0.42)'
const GREEN = 'rgba(34, 197, 94, 0.55)'
const GREEN_TEXT = 'rgb(21, 128, 61)'

function pct(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return 0
    return `${Math.max(0, Math.min(1, n)) * 100}%`
}

export default function BrandDebugOverlay({ image: _image, debug, enabled }) {
    if (!enabled || !debug) {
        return null
    }

    const regions = Array.isArray(debug.color_regions) ? debug.color_regions : []
    const logos = Array.isArray(debug.logo_detections) ? debug.logo_detections : []
    const refs = Array.isArray(debug.top_references) ? debug.top_references : []
    const attention = typeof debug.attention_map === 'string' && debug.attention_map.trim() !== '' ? debug.attention_map : null

    const hasContent =
        regions.length > 0 || logos.length > 0 || refs.length > 0 || attention !== null
    if (!hasContent) {
        return null
    }

    return (
        <div className="pointer-events-none absolute inset-0 z-30 overflow-hidden" aria-hidden>
            {attention && (
                <img
                    src={attention}
                    alt=""
                    className="absolute inset-0 h-full w-full object-contain opacity-40 mix-blend-multiply"
                />
            )}

            {regions.map((r, i) => (
                <div
                    // eslint-disable-next-line react/no-array-index-key -- server has no stable id
                    key={`cr-${i}`}
                    className="absolute box-border rounded-sm"
                    style={{
                        left: pct(r.x),
                        top: pct(r.y),
                        width: pct(r.width),
                        height: pct(r.height),
                        border: `2px solid ${ORANGE}`,
                        backgroundColor: 'rgba(249, 115, 22, 0.12)',
                    }}
                    title={r.color && r.score != null ? `${r.color} · ${(r.score * 100).toFixed(0)}%` : undefined}
                />
            ))}

            {logos.map((l, i) => {
                const method = l.method === 'OCR' || l.method === 'Embedding' ? l.method : 'Embedding'
                const conf =
                    typeof l.confidence === 'number' && !Number.isNaN(l.confidence)
                        ? Math.min(1, Math.max(0, l.confidence))
                        : 0
                return (
                    <div
                        // eslint-disable-next-line react/no-array-index-key
                        key={`logo-${i}`}
                        className="absolute flex flex-col items-stretch"
                        style={{
                            left: pct(l.x),
                            top: pct(l.y),
                            width: pct(l.width),
                            height: pct(l.height),
                            border: `2px solid ${GREEN}`,
                            backgroundColor: 'rgba(34, 197, 94, 0.08)',
                        }}
                    >
                        <span
                            className="mt-0.5 ml-0.5 inline-flex max-w-full truncate rounded px-1 py-0.5 text-[10px] font-semibold leading-tight shadow-sm"
                            style={{ color: GREEN_TEXT, backgroundColor: 'rgba(255,255,255,0.92)' }}
                        >
                            {method} · {(conf * 100).toFixed(0)}%
                        </span>
                    </div>
                )
            })}

            {refs.length > 0 && (
                <div className="absolute bottom-2 right-2 max-w-[min(100%,12rem)] rounded-md border border-slate-200/90 bg-white/95 p-2 shadow-md backdrop-blur-sm">
                    <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">Top Matches</p>
                    <ul className="flex flex-col gap-1.5">
                        {refs.map((ref, idx) => (
                            <li
                                // eslint-disable-next-line react/no-array-index-key
                                key={ref.id ?? `ref-${idx}`}
                                className="flex items-center gap-2"
                            >
                                <div className="h-9 w-9 flex-shrink-0 overflow-hidden rounded border border-slate-200 bg-slate-100">
                                    {ref.thumbnail ? (
                                        <img src={ref.thumbnail} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center text-[9px] text-slate-400">
                                            —
                                        </div>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[10px] text-slate-500">
                                        {ref.id
                                            ? `${String(ref.id).slice(0, 8)}…`
                                            : '—'}
                                    </p>
                                    <p className="text-xs font-semibold text-slate-800">
                                        {typeof ref.similarity === 'number' && !Number.isNaN(ref.similarity)
                                            ? `${(Math.min(1, Math.max(0, ref.similarity)) * 100).toFixed(0)}%`
                                            : '—'}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    )
}

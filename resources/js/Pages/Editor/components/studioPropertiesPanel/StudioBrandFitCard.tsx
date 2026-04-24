import type { LayerBrandFitGuidance } from './layerBrandFitGuidance'

const chipDot: Record<LayerBrandFitGuidance['tone'], string> = {
    aligned: 'bg-emerald-500/45',
    improve: 'bg-amber-500/40',
    review: 'bg-orange-600/35',
    unknown: 'bg-slate-500/45',
}

/** Subtle left accent — state signal without alert-style fills */
const shellAccent: Record<LayerBrandFitGuidance['tone'], string> = {
    aligned: 'border-l-[3px] border-l-emerald-600/35',
    improve: 'border-l-[3px] border-l-amber-600/30',
    review: 'border-l-[3px] border-l-orange-600/28',
    unknown: 'border-l-[3px] border-l-slate-600/40',
}

/** Strip leading “Brand fit:” from guidance headline for the status line. */
function statusLineFromHeadline(headline: string): string {
    const m = headline.match(/^Brand fit:\s*(.+)$/i)
    return (m ? m[1] : headline).trim()
}

/**
 * Calm insight — dot + left accent carry state; copy stays neutral and readable.
 */
export function StudioBrandFitCard({ guidance }: { guidance: LayerBrandFitGuidance }) {
    const status = statusLineFromHeadline(guidance.headline)
    return (
        <div
            className={`rounded-xl border border-gray-800/90 bg-gray-900/40 py-2 pl-2 pr-3 shadow-sm ${shellAccent[guidance.tone]}`}
        >
            <div className="flex items-center gap-1.5">
                <span
                    className={`inline-block h-1 w-1 shrink-0 rounded-full ${chipDot[guidance.tone]}`}
                    aria-hidden
                />
                <span className="text-[9px] font-semibold uppercase tracking-[0.12em] text-gray-500">
                    Brand fit
                </span>
            </div>
            <p className="mt-1.5 text-[11px] font-medium leading-snug text-gray-200">{status}</p>
            {guidance.detail ? (
                <p className="mt-1 text-[10px] leading-relaxed text-gray-400">{guidance.detail}</p>
            ) : null}
            {guidance.suggestions.length > 0 ? (
                <ul className="mt-1.5 list-disc space-y-0.5 pl-3.5 text-[9px] leading-snug text-gray-500 marker:text-gray-600">
                    {guidance.suggestions.slice(0, 2).map((x, i) => (
                        <li key={i}>{x}</li>
                    ))}
                </ul>
            ) : null}
        </div>
    )
}

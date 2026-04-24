/**
 * Shared Tailwind fragments for the Studio properties column.
 * Tonal reference: left flyout rail (`border-gray-700`, `bg-gray-900`, row `text-gray-200`).
 */

export const studioPanelChrome = {
    /** Properties column root — matches layer stack flyout */
    aside: 'border-l border-gray-700 bg-gray-900 text-gray-200',
    /** Top title strip */
    headerStrip:
        'flex flex-wrap items-center justify-between gap-2 border-b border-gray-700 bg-gray-900 px-3 py-2',
    /** Includes `jp-studio-properties-scroll` — branded scrollbar (see resources/css/app.css). */
    scroll: 'jp-studio-properties-scroll flex-1 overflow-y-auto px-3.5 py-2.5 text-xs',
} as const

/** Semantic borders — neutral structure vs purple accents */
export const studioPanelBorders = {
    subtle: 'border-gray-700/70',
    card: 'border-gray-700',
    interactive: 'border-gray-600',
    active: 'border-indigo-500/35',
    ai: 'border-violet-500/30',
} as const

/** Section-sized cards and shells (not every nested control group). */
export const studioPanelSurfaces = {
    /** Identity / anchor — selected layer header */
    layerAnchor: `rounded-xl border border-gray-700 border-l-[3px] border-l-indigo-400/35 bg-gradient-to-b from-gray-900 via-gray-900 to-gray-900/95 px-3 py-2.5 shadow-sm ring-1 ring-inset ring-indigo-500/12`,
    /** Single utility card (layout block, canvas expanded, etc.) — slightly above panel for hierarchy */
    cardQuiet: `rounded-lg border ${studioPanelBorders.card} bg-gray-800/40 shadow-sm ring-1 ring-inset ring-black/25`,
    /** Technical inset — flatter than hero cards; subtle slate left accent */
    technicalInset: `rounded-md border border-gray-800/90 border-l-[3px] border-l-slate-600/30 bg-gray-900/45 px-2.5 py-2`,
    /** Canvas summary row (collapsed) */
    canvasSummaryRow: `flex w-full items-center justify-between gap-2 rounded-lg border ${studioPanelBorders.subtle} bg-gray-800/35 px-2.5 py-1.5 text-left text-[11px] text-gray-200 transition-colors hover:border-indigo-500/30 hover:bg-gray-800/55`,
    /** Canvas expanded body */
    canvasExpanded: `mt-1.5 space-y-2.5 rounded-lg border border-gray-800/90 bg-gray-800/25 px-2.5 py-2 ring-0`,
} as const

export const studioPanelInputs = {
    compactNumber: `w-[4.25rem] rounded-md border border-gray-700/90 bg-gray-900/60 px-1.5 py-0.5 text-center text-[11px] text-gray-200 tabular-nums shadow-inner focus:border-indigo-500/50 focus:outline-none focus:ring-1 focus:ring-indigo-500/35`,
    fieldSm: `w-full rounded-md border border-gray-700/90 bg-gray-900/55 px-2 py-1 text-[11px] text-gray-200 shadow-inner focus:border-indigo-500/50 focus:outline-none focus:ring-1 focus:ring-indigo-500/30`,
} as const

/** Readable hierarchy aligned to the layer stack rail */
export const studioPanelText = {
    primary: 'text-gray-200',
    secondary: 'text-gray-300',
    muted: 'text-gray-400',
    /** Major group headings (Canvas, Layout, …) — one line with icon */
    label: 'text-[10px] font-semibold uppercase tracking-[0.11em] text-gray-200/95',
    labelMuted: 'text-[10px] font-semibold uppercase tracking-[0.11em] text-gray-400',
    labelAi: 'text-[10px] font-semibold uppercase tracking-[0.11em] text-violet-200/95',
    labelBrand: 'text-[10px] font-semibold uppercase tracking-[0.11em] text-violet-200/90',
    sectionDesc: 'max-w-prose text-[10px] leading-relaxed text-gray-400/95',
    fieldLabel: 'text-[10px] font-medium text-gray-400',
    micro: 'text-[10px] leading-snug text-gray-400',
    microHint: 'text-[10px] leading-snug text-gray-400',
} as const

/**
 * @deprecated Prefer `studioPanelText` — kept for incremental migration / call sites.
 */
export const studioPanelType = {
    sectionLabelDefault: studioPanelText.label,
    sectionLabelMuted: studioPanelText.labelMuted,
    sectionLabelAi: studioPanelText.labelAi,
    sectionLabelBrand: studioPanelText.labelBrand,
    sectionDesc: studioPanelText.sectionDesc,
    fieldLabel: studioPanelText.fieldLabel,
    microHint: studioPanelText.microHint,
} as const

/** Section header band + hatch — see `jp-studio-section-header-band` in app.css */
export const studioPanelPattern = {
    sectionHatch: 'jp-studio-section-hatch',
    sectionHeaderBand: 'jp-studio-section-header-band',
    sectionHeaderHatch: 'jp-studio-section-header-band__hatch',
    /** Left gutter band — pairs with `sectionHeaderBand` for a continuous section break */
    sectionHeaderBandGutter: 'jp-studio-section-header-band-gutter',
} as const

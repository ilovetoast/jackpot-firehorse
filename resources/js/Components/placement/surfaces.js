/**
 * Shared “placement” surfaces: brand (library / brand settings), tenant (Jackpot company),
 * system (platform admin). Use for info panels, scope callouts, and modal confirmations
 * so indigo/amber one-offs don’t drift from product chrome.
 */

/** @typedef {'brand' | 'tenant' | 'system'} Placement */

/** @typedef {'default' | 'warning' | 'critical'} PlacementTone */

/** @type {Record<Placement, { panel: string, title: string, body: string, muted: string, hint: string, checkbox: string }>} */
export const PLACEMENT_SURFACES = {
    brand: {
        /** Library / brand-workspace info: white panel + warm border (aligns with {@link ../../components/brand-workspace/BrandWorkspaceCallout.jsx} `variant="brand"`). */
        panel:
            'rounded-xl border border-orange-200/90 bg-white shadow-sm ring-1 ring-orange-500/[0.06]',
        title: 'text-slate-900',
        body: 'text-slate-600',
        muted: 'text-slate-500',
        hint: 'text-slate-600',
        checkbox: 'border-gray-300 text-orange-600 focus:ring-orange-500',
    },
    tenant: {
        panel: 'rounded-xl border border-violet-200/90 bg-violet-50/50 shadow-sm',
        title: 'text-gray-900',
        body: 'text-gray-700',
        muted: 'text-violet-900/55',
        hint: 'text-violet-900/75',
        checkbox: 'border-gray-300 text-violet-600 focus:ring-violet-500',
    },
    system: {
        panel: 'rounded-xl border border-blue-200/90 bg-blue-50/80 shadow-sm',
        title: 'text-gray-900',
        body: 'text-gray-700',
        muted: 'text-blue-900/55',
        hint: 'text-blue-900/80',
        checkbox: 'border-gray-300 text-blue-600 focus:ring-blue-500',
    },
}

/** Subtle ring layered on the placement panel (resource / caution without a new color system). */
/** @type {Record<PlacementTone, string>} */
export const PLACEMENT_TONE_RING = {
    default: '',
    warning: 'ring-1 ring-amber-300/50 ring-inset',
    critical: 'ring-1 ring-red-300/45 ring-inset',
}

/**
 * Primary button classes for “queue / confirm” actions that should follow placement
 * (destructive reds stay caller-defined).
 * @param {Placement} placement
 */
export function placementPrimaryQuietButtonClasses(placement) {
    if (placement === 'system') {
        return 'text-white bg-blue-600 hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
    }
    if (placement === 'brand') {
        return 'text-white bg-orange-600 hover:bg-orange-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600'
    }
    return 'text-white bg-violet-600 hover:bg-violet-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600'
}

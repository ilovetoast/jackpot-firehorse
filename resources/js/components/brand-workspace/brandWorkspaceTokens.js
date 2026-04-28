/**
 * @file Brand workspace + app **visual system** (two modes)
 *
 * ─────────────────────────────────────────────────────────────────────────
 * 1) CINEMATIC BRAND PAGES — brand world, not product admin
 *    Examples: `Pages/Overview/Index`, `Overview/CreatorProgress`, `Prostaff/CreatorsDashboard`,
 *    other immersive / dark **brand** dashboards.
 *    - Core UI: **black / charcoal / white** glass, translucent panels.
 *    - **Brand color**: atmosphere only — soft glow, radial wash, nav underline (AppNav), tiny accents,
 *      optional hairline. Never the only signal for “system” success.
 *    - **Do not** use **Jackpot violet** as a **large** surface, hero panel, or dominant banner on these pages.
 *    - **Buttons**: white, ghost, or neutral; links often white/soft.
 *    - **Alerts / info**: dark glass, neutral, or **subtle brand-tinted** border; **warm amber** for real warnings.
 *
 * 2) WORKBENCH / PRODUCT PAGES — Jackpot operating on a brand
 *    Examples: Insights, Review, Manage, Settings / Brand edit (incl. DNA), categories/fields/tags/values.
 *    - Structure: **white / slate / zinc / charcoal** (`BrandWorkbenchMasthead` header).
 *    - **Jackpot violet** (`JACKPOT_VIOLET`, `violet-*`): active nav, tabs, primary & save, toggles, focus,
 *      AI/product modules, selected states, badges.
 *    - **Brand color**: **identity only** — logo, tiny chip/dot in masthead, very subtle header wash.
 *    - **Amber / orange**: real attention / limits / blockers.
 *
 * Cinematic and workbench must **not** both paint “active” with customer brand and violet in the same pattern.
 * ─────────────────────────────────────────────────────────────────────────
 */
export const BRAND_WORKBENCH_MAX = 'mx-auto w-full max-w-7xl'
export const BRAND_WORKBENCH_PAD_X = 'px-4 sm:px-6 lg:px-8'
/** Vertical padding in main workbench content column */
export const BRAND_WORKBENCH_PAD_Y = 'py-6 sm:py-8'
export const BRAND_WORKBENCH_CONTENT = `${BRAND_WORKBENCH_MAX} ${BRAND_WORKBENCH_PAD_X} ${BRAND_WORKBENCH_PAD_Y}`

/** Left rail width (Insights, Manage) — must stay aligned with masthead grid */
export const WORKBENCH_ASIDE_WIDTH = 'lg:w-56'
/** Space between dark masthead block and the aside + main two-column area */
export const WORKBENCH_BODY_TOP_GAP = 'mt-6 sm:mt-7 lg:mt-8'
export const workbenchPageColumnsClass = `flex flex-col ${WORKBENCH_BODY_TOP_GAP} gap-6 lg:flex-row lg:items-start lg:gap-8 xl:gap-10`

/** Default neutral when a brand has no primary color yet (scope chrome, inactive density) */
export const BRAND_ACCENT_FALLBACK = '#64748b'

/** Product UI default accent when a hex is required and brand color is missing (nav rescue, etc.) */
export const JACKPOT_VIOLET = '#7c3aed'

/** Jackpot product UI (saves, toggles, primary CTAs, focus rings) — workbench only */
export const productButtonPrimary =
    'inline-flex items-center justify-center rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2'

export const productFocusInput = 'focus:border-violet-500 focus:ring-violet-500'

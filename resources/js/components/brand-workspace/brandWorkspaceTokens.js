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
 * 2) WORKBENCH / BRAND-SCOPED SETTINGS — operating on a specific brand (Insights, Manage, Brand edit, …)
 *    - Structure: **white / slate / zinc / charcoal** (`BrandWorkbenchMasthead` header).
 *    - **Brand chrome**: `BrandWorkbenchChrome` sets `--wb-*` CSS variables from the brand palette (primary → accent → secondary),
 *      with the same contrast intelligence as workspace primary buttons (`getWorkspacePrimaryActionButtonColors`, `ensureAccentContrastOnWhite`).
 *    - Tailwind `violet-*` utilities inside `.brand-workbench-theme` are remapped to those vars via `brand-workbench-theme.css`.
 *    - **Tenant / company** surfaces (library chrome, `/app/companies/settings`, profile) keep **Jackpot / site indigo** — do not wrap those in `BrandWorkbenchChrome`.
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

/** Primary CTA / focus within `.brand-workbench-theme` (CSS vars; fallbacks match Jackpot when vars unset) */
export const productButtonPrimary =
    'inline-flex items-center justify-center rounded-md bg-[var(--wb-accent)] px-4 py-2 text-sm font-medium text-[var(--wb-on-accent)] shadow-sm hover:bg-[var(--wb-accent-hover)] hover:text-[var(--wb-on-accent-hover)] focus:outline-none focus:ring-2 focus:ring-[var(--wb-ring)] focus:ring-offset-2'

export const productFocusInput = 'focus:border-[color:var(--wb-link)] focus:ring-[color:var(--wb-link)]'

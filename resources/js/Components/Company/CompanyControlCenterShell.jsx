import { Link } from '@inertiajs/react'

/**
 * Company / account “control plane” — Jackpot system styling (violet primary accent), not the selected brand’s creative theme.
 *
 * Color roles (company shell):
 * - Violet: primary accent (CTAs, active nav, key badges, emphasized links)
 * - Slate: surfaces, text, borders
 * - White on dark: hero typography; no competing blue for the same role as violet in this shell
 *
 * - CompanyCommandHero: dashboard — dark hero with integrated stat strip
 * - CompanyAdminTopBar: other company pages — compact dark bar
 */

const noiseStyle = {
    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.22'/%3E%3C/svg%3E")`,
}

export function CompanyScopePill({ children = 'Company' }) {
    return (
        <span
            className="inline-flex items-center rounded-full border border-white/20 bg-violet-500/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.2em] text-violet-100/95"
            aria-hidden
        >
            {children}
        </span>
    )
}

/**
 * Single “stat rail” inside the dark hero — shared border, hairline dividers (gap-px + grid), not isolated cards.
 * @param {{ id?: string, label: string, value: React.ReactNode, sub?: string }[]} props.items
 */
export function CompanyHeroStatStrip({ items = [] }) {
    if (!items.length) {
        return null
    }
    return (
        <div className="mt-6 sm:mt-7 border-t border-white/10 pt-5 sm:pt-6">
            <ul
                className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-white/10 bg-white/10 sm:grid-cols-3 lg:grid-cols-6"
                role="list"
                aria-label="Key company metrics"
            >
                {items.map((it, i) => (
                    <li
                        key={it.id || i}
                        className="flex min-h-0 min-w-0 flex-col justify-center bg-slate-950/55 px-3 py-2.5 backdrop-blur-sm sm:min-h-[4.5rem] sm:px-4 sm:py-2.5"
                    >
                        <p className="text-[9px] font-bold uppercase leading-tight tracking-[0.2em] text-violet-200/55">
                            {it.label}
                        </p>
                        <p className="mt-0.5 text-base font-semibold leading-tight tabular-nums text-white sm:text-lg">
                            {it.value}
                        </p>
                        {it.sub ? (
                            <p className="mt-0.5 line-clamp-2 text-[10px] leading-tight text-white/45 sm:text-[11px] sm:leading-snug">
                                {it.sub}
                            </p>
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    )
}

/**
 * @param {object} props
 * @param {string} props.companyName
 * @param {string} [props.planLabel]
 * @param {string} [props.title]
 * @param {string} [props.description]
 * @param {Array<{ id?: string, label: string, value: React.ReactNode, sub?: string }>} [props.stats] — stat strip in hero
 * @param {React.ReactNode} [props.actions]
 * @param {React.ReactNode} [props.children]
 */
export function CompanyCommandHero({
    companyName,
    planLabel,
    title = 'Company dashboard',
    description = 'Account-wide view of your company. Open brands, manage people, and jump to admin tasks.',
    stats = [],
    actions = null,
    children = null,
}) {
    return (
        <div className="relative overflow-hidden border-b border-violet-950/30 bg-gradient-to-br from-slate-950 via-slate-900 to-violet-950/90 text-white">
            <div
                className="pointer-events-none absolute inset-0 bg-[length:20px_20px] opacity-[0.1]"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.04)_1px,transparent_1px)',
                }}
                aria-hidden
            />
            <div
                className="pointer-events-none absolute inset-0 mix-blend-overlay opacity-35"
                style={noiseStyle}
                aria-hidden
            />
            <div className="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between lg:gap-10">
                    <div className="min-w-0 max-w-3xl flex-1">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <CompanyScopePill>Company</CompanyScopePill>
                            {planLabel ? (
                                <span
                                    className="inline-flex items-center rounded-md border border-violet-300/30 bg-violet-500/20 px-2 py-0.5 text-xs font-semibold text-violet-100"
                                    title="Current plan"
                                >
                                    {planLabel}
                                </span>
                            ) : null}
                        </div>
                        <h1 className="mt-3 text-2xl font-bold tracking-tight text-white sm:text-3xl">{title}</h1>
                        <p className="mt-1.5 text-sm font-medium text-white/90">{companyName}</p>
                        <p className="mt-2 max-w-2xl text-sm leading-relaxed text-white/70">{description}</p>
                    </div>
                    {actions ? <div className="flex w-full max-w-md flex-shrink-0 flex-col gap-3 lg:items-end">{actions}</div> : null}
                </div>

                <CompanyHeroStatStrip items={stats} />
                {children}
            </div>
        </div>
    )
}

/**
 * @param {object} props
 * @param {string} props.title
 * @param {string} [props.subtitle]
 */
export function CompanyAdminTopBar({ title, subtitle }) {
    return (
        <div className="relative overflow-hidden border-b border-violet-950/30 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-950 text-white">
            <div
                className="pointer-events-none absolute inset-0 bg-[length:20px_20px] opacity-[0.1]"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.04)_1px,transparent_1px)',
                }}
                aria-hidden
            />
            <div className="relative z-10 mx-auto max-w-7xl px-4 py-4 sm:px-6 sm:py-5 lg:px-8">
                <div className="flex flex-wrap items-center gap-2.5">
                    <CompanyScopePill>Company</CompanyScopePill>
                </div>
                <h1 className="mt-1 text-xl font-bold tracking-tight sm:text-2xl">{title}</h1>
                {subtitle ? <p className="mt-1 max-w-3xl text-sm text-white/75">{subtitle}</p> : null}
            </div>
        </div>
    )
}

/** Primary CTA — violet (Jackpot primary accent) */
export function CompanyControlPrimaryCta({ href, children, className = '', ...rest }) {
    if (href) {
        return (
            <Link
                href={href}
                className={[
                    'inline-flex w-full sm:w-auto items-center justify-center rounded-md bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm',
                    'ring-1 ring-inset ring-violet-400/20 hover:bg-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-500',
                    className,
                ].join(' ')}
                {...rest}
            >
                {children}
            </Link>
        )
    }
    return null
}

import { Link } from '@inertiajs/react'
import { hexToRgba } from '../../utils/colorUtils'
import { BRAND_ACCENT_FALLBACK } from './brandWorkspaceTokens'

/**
 * Compact brand-context header for workbench (light) pages.
 * Charcoal / near-black base with a **restrained** brand tint (no blue multicolor hero).
 * Bridges cinematic brand pages and neutral operational surfaces.
 */
export default function BrandWorkbenchMasthead({
    companyName = '',
    brandName = '',
    title,
    description,
    /** Brand primary hex — drives subtle atmospheric tint */
    brandColor = BRAND_ACCENT_FALLBACK,
    canLinkCompany = false,
    companyHref = '/app/companies/settings',
    className = '',
    children = null,
}) {
    const safeAccent = (() => {
        const c = brandColor
        if (!c || typeof c !== 'string') return BRAND_ACCENT_FALLBACK
        const t = c.trim()
        if (t.startsWith('#') && (t.length === 7 || t.length === 4)) return t
        if (/^[0-9a-fA-F]{6}$/i.test(t)) return `#${t}`
        if (/^[0-9a-fA-F]{3}$/i.test(t)) return `#${t}`
        return BRAND_ACCENT_FALLBACK
    })()

    return (
        <div
            className={`relative overflow-hidden rounded-2xl border border-zinc-800/90 shadow-sm ${className}`.trim()}
            style={{
                backgroundColor: '#111113',
                /* Identity: very soft brand wash only — product chrome stays neutral/violet elsewhere */
                backgroundImage: `radial-gradient(ellipse 100% 80% at 0% -10%, ${hexToRgba(safeAccent, 0.07)} 0%, transparent 52%), radial-gradient(ellipse 70% 50% at 100% 100%, ${hexToRgba(safeAccent, 0.04)} 0%, transparent 45%)`,
                boxShadow: `inset 0 1px 0 0 ${hexToRgba(safeAccent, 0.07)}`,
            }}
        >
            <div className="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/[0.04] to-transparent" aria-hidden />
            <div className="relative px-5 py-4 sm:px-6 sm:py-4">
                {companyName || brandName ? (
                    <nav className="mb-2.5 text-xs sm:text-sm" aria-label="Breadcrumb">
                        <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-zinc-500">
                            {companyName ? (
                                <li>
                                    {canLinkCompany ? (
                                        <Link
                                            href={companyHref}
                                            className="font-medium text-zinc-400 transition hover:text-zinc-200"
                                        >
                                            {companyName}
                                        </Link>
                                    ) : (
                                        <span className="font-medium text-zinc-400">{companyName}</span>
                                    )}
                                </li>
                            ) : null}
                            {companyName && brandName ? (
                                <li className="select-none text-zinc-600" aria-hidden="true">
                                    /
                                </li>
                            ) : null}
                            {brandName ? (
                                <li className="flex items-center gap-2 font-semibold text-zinc-100" aria-current="page">
                                    <span
                                        className="h-1.5 w-1.5 shrink-0 rounded-full ring-1 ring-white/10"
                                        style={{ backgroundColor: safeAccent }}
                                        aria-hidden
                                    />
                                    {brandName}
                                </li>
                            ) : null}
                        </ol>
                    </nav>
                ) : null}

                <h1 className="text-lg font-semibold tracking-tight text-zinc-50 sm:text-xl">{title}</h1>
                {description ? (
                    <p className="mt-1.5 max-w-3xl text-sm leading-relaxed text-zinc-500">{description}</p>
                ) : null}
                {children}
            </div>
        </div>
    )
}

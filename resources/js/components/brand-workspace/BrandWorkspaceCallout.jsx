/**
 * Shared workbench callout: icon well + title + description + trailing actions.
 *
 * - `variant="brand"` (default): white panel, border tinted from workspace primary; icon well uses a flat brand tint.
 * - `variant="positive"`: flat violet-tinted panel (e.g. brand DNA already active).
 *
 * Surfaces stay **solid** (no gradients) so brand settings / Insights stay calm next to orange or other accents.
 */

import { useMemo } from 'react'
import { Link } from '@inertiajs/react'
import { usePage } from '@inertiajs/react'
import {
    getLuminance,
    getSolidFillButtonForegroundHex,
    getWorkspaceButtonColor,
    hexToRgba,
    normalizeHexColor,
} from '../../utils/colorUtils'

/**
 * @param {object} props
 * @param {'brand'|'positive'} [props.variant]
 * @param {import('react').ReactNode} props.icon — e.g. `<DocumentTextIcon className="h-6 w-6" aria-hidden />`
 * @param {import('react').ReactNode} props.title
 * @param {import('react').ReactNode} [props.description]
 * @param {import('react').ReactNode} [props.children] — CTA row (links / buttons)
 * @param {string} [props.className]
 * @param {string} [props.headingId]
 */
export default function BrandWorkspaceCallout({
    variant = 'brand',
    icon,
    title,
    description = null,
    children = null,
    className = '',
    headingId = 'brand-workspace-callout-heading',
}) {
    const { auth } = usePage().props
    const brand = auth?.activeBrand
    const brandKey = brand?.id ?? 'none'

    const chrome = useMemo(() => {
        if (variant === 'positive') {
            return {
                mode: 'positive',
                outerClass:
                    'border-violet-200/90 bg-violet-50/80 shadow-sm ring-1 ring-violet-500/[0.06]',
                iconWellClass: 'bg-violet-100/90 ring-1 ring-violet-200/60',
                iconWrapClass: 'text-violet-700',
            }
        }
        const accent = normalizeHexColor(getWorkspaceButtonColor(brand))
        const lum = getLuminance(accent)
        const isVeryLight = lum > 0.92
        const borderAlpha = isVeryLight ? 0.32 : 0.22
        return {
            mode: 'brand',
            outerStyle: {
                borderColor: hexToRgba(accent, borderAlpha),
                backgroundColor: '#ffffff',
                boxShadow: '0 1px 2px 0 rgb(15 23 42 / 0.05)',
            },
            iconWellStyle: {
                backgroundColor: hexToRgba(accent, isVeryLight ? 0.14 : 0.15),
                boxShadow: `inset 0 0 0 1px ${hexToRgba(accent, isVeryLight ? 0.22 : 0.14)}`,
            },
            iconWrapClass: isVeryLight ? 'text-slate-800' : '',
            iconWrapStyle: isVeryLight ? undefined : { color: accent },
        }
    }, [variant, brandKey, brand])

    const isPositive = chrome.mode === 'positive'

    return (
        <section
            className={`rounded-xl border p-4 sm:p-5 ${isPositive ? chrome.outerClass : ''} ${className}`.trim()}
            style={!isPositive ? chrome.outerStyle : undefined}
            aria-labelledby={headingId}
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 gap-3">
                    <div
                        className={`flex-shrink-0 rounded-lg p-2.5 ${isPositive ? chrome.iconWellClass : ''}`}
                        style={!isPositive ? chrome.iconWellStyle : undefined}
                    >
                        <span
                            className={`flex items-center justify-center [&>svg]:h-6 [&>svg]:w-6 ${chrome.iconWrapClass}`.trim()}
                            style={!isPositive ? chrome.iconWrapStyle : undefined}
                        >
                            {icon}
                        </span>
                    </div>
                    <div className="min-w-0">
                        <h2 id={headingId} className="text-base font-semibold text-slate-900">
                            {title}
                        </h2>
                        {description ? (
                            <div className="mt-1.5 text-sm leading-relaxed text-slate-600">{description}</div>
                        ) : null}
                    </div>
                </div>
                {children ? (
                    <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-shrink-0 sm:flex-row">{children}</div>
                ) : null}
            </div>
        </section>
    )
}

/**
 * Solid primary CTA using workspace button color (same as Add Asset / workbench primary actions).
 */
export function WorkspacePrimaryCtaLink({ href, children, className = '', brand: brandOverride = null }) {
    const { auth } = usePage().props
    const brand = brandOverride ?? auth?.activeBrand
    const fill = normalizeHexColor(getWorkspaceButtonColor(brand))
    const fg = getSolidFillButtonForegroundHex(fill)

    return (
        <Link
            href={href}
            className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition hover:brightness-[0.94] active:brightness-[0.88] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${className}`.trim()}
            style={{
                backgroundColor: fill,
                color: fg,
                outlineColor: fill,
            }}
        >
            {children}
        </Link>
    )
}

/**
 * Secondary outline CTA on brand callout panels (neutral ring).
 */
export function WorkspaceSecondaryCtaLink({ href, children, className = '' }) {
    return (
        <Link
            href={href}
            className={`inline-flex items-center justify-center gap-2 rounded-lg bg-white/90 px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm ring-1 ring-inset ring-slate-200/90 hover:bg-white ${className}`.trim()}
        >
            {children}
        </Link>
    )
}

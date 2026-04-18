/**
 * BrandedAiCard — shared visual treatment for premium / AI-powered settings sections
 * (Studio, Brand Alignment, Asset Field Intelligence).
 *
 * Visual reference: the "Video AI" block in BulkActionsModal.jsx — a rounded-xl panel
 * with a tinted background, a pill badge, an accent icon tile, title + description,
 * and slot-rendered controls below. This component standardizes that treatment so new
 * AI settings cards don't drift from the pattern.
 *
 * Variants:
 *  - 'violet' : Studio (matches existing Video AI treatment across the app).
 *  - 'indigo' : Asset Field Intelligence (matches existing indigo treatment in the
 *               AiTaggingSettings "Asset fields" section).
 *  - 'brand'  : Brand Alignment. Uses the tenant's primary brand color (supply via
 *               `brandPrimary`, and ideally the WCAG-safe `brandPrimaryOnWhite`
 *               variant from colorUtils.ensureAccentContrastOnWhite for text/icons
 *               that render on white surfaces).
 *
 * `cascadedOff` visually de-emphasizes the card when the tenant-level master AI
 * switch is off — matches the `opacity-50 pointer-events-none` pattern already used
 * in AiTaggingSettings when a parent toggle disables children.
 */

import { SparklesIcon } from '@heroicons/react/24/outline'

const VARIANT_TOKENS = {
    violet: {
        panelBorder: 'border-violet-200/90',
        panelBg: 'bg-violet-50/35',
        divider: 'border-violet-100/90',
        badgeBg: 'bg-violet-600',
        badgeText: 'text-white',
        iconTileBg: 'bg-violet-100',
        iconFg: 'text-violet-700',
        headingFg: 'text-violet-900/80',
    },
    indigo: {
        panelBorder: 'border-indigo-100',
        panelBg: 'bg-indigo-50/30',
        divider: 'border-indigo-100/80',
        badgeBg: 'bg-indigo-600',
        badgeText: 'text-white',
        iconTileBg: 'bg-indigo-100',
        iconFg: 'text-indigo-700',
        headingFg: 'text-indigo-900/70',
    },
}

export default function BrandedAiCard({
    variant = 'violet',
    brandPrimary = null,
    brandPrimaryOnWhite = null,
    badgeLabel,
    title,
    description = null,
    icon: Icon = SparklesIcon,
    cascadedOff = false,
    children,
    className = '',
}) {
    const isBrand = variant === 'brand'
    const tokens = VARIANT_TOKENS[variant] || VARIANT_TOKENS.violet

    const panelStyle = isBrand
        ? {
              borderColor: brandPrimaryOnWhite || brandPrimary || '#6366f1',
              backgroundColor: 'rgba(255,255,255,0.6)',
          }
        : undefined
    const badgeStyle = isBrand
        ? { backgroundColor: brandPrimaryOnWhite || brandPrimary || '#6366f1', color: '#fff' }
        : undefined
    const iconTileStyle = isBrand
        ? {
              backgroundColor: (brandPrimaryOnWhite || brandPrimary || '#6366f1') + '22',
          }
        : undefined
    const iconFgStyle = isBrand
        ? { color: brandPrimaryOnWhite || brandPrimary || '#6366f1' }
        : undefined
    const dividerStyle = isBrand
        ? { borderColor: (brandPrimaryOnWhite || brandPrimary || '#6366f1') + '33' }
        : undefined

    return (
        <div
            className={`relative rounded-xl border p-5 shadow-sm transition-opacity ${
                isBrand ? 'border' : `${tokens.panelBorder} ${tokens.panelBg}`
            } ${cascadedOff ? 'opacity-50 pointer-events-none' : ''} ${className}`}
            style={panelStyle}
            aria-disabled={cascadedOff || undefined}
        >
            <div
                className={`flex flex-wrap items-center gap-x-2 gap-y-1 border-b pb-2 ${
                    isBrand ? '' : tokens.divider
                }`}
                style={dividerStyle}
            >
                <span
                    className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${
                        isBrand ? '' : `${tokens.badgeBg} ${tokens.badgeText}`
                    }`}
                    style={badgeStyle}
                >
                    {badgeLabel}
                </span>
                <h3 className="text-sm font-semibold text-gray-800">{title}</h3>
                <span
                    className={`ml-auto flex h-8 w-8 items-center justify-center rounded-md ${
                        isBrand ? '' : tokens.iconTileBg
                    }`}
                    style={iconTileStyle}
                >
                    <Icon className={`h-4 w-4 ${isBrand ? '' : tokens.iconFg}`} style={iconFgStyle} />
                </span>
            </div>

            {description && (
                <p className="mt-3 text-sm leading-snug text-gray-600">{description}</p>
            )}

            {children && <div className="mt-4">{children}</div>}
        </div>
    )
}

import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import type { ReactNode } from 'react'
import { studioPanelPattern, studioPanelText } from './studioPanelUi'
import type { StudioPropertiesSectionId } from './studioSectionRailTypes'
import { StudioSectionRailIconButton } from './StudioSectionRail'

/** Gutter width — content column uses matching `pl-*` after the rail */
const sectionRailGutterClass = 'w-8 shrink-0'
/** Horizontal inset for section title / description / body text after the vertical rail */
const railContentPl = 'pl-2.5'
/** One rule color for section breaks (horizontal + vertical junctions) */
const sectionRule = 'border-gray-700/85'
const railGutterChrome = `border-r ${sectionRule} bg-gray-950/45`
const railGutterSpacerClass = `${sectionRailGutterClass} ${railGutterChrome}`

export type StudioPanelGroupTone = 'default' | 'ai' | 'brand' | 'muted'

/**
 * Major section shell for the Studio properties column — spacing, label, optional
 * description. Keeps canvas / layer / primary / source / layout / style visually distinct.
 */
export function StudioPanelGroup({
    label,
    icon,
    description,
    tone = 'default',
    first = false,
    children,
    className = '',
    contentClassName = '',
    sectionId,
    collapsible = false,
    collapsed = false,
    onToggleCollapsed,
    hideDescriptionWhenCollapsed = true,
    railIcon,
    railShortLabel,
    railActive,
}: {
    label: string
    icon?: ReactNode
    description?: string
    tone?: StudioPanelGroupTone
    /** First block in the scroll area — skips top rule so it doesn’t double with padding */
    first?: boolean
    children: ReactNode
    className?: string
    /** Extra classes on the inner content stack (rhythm between cards inside a section) */
    contentClassName?: string
    /** Stable id for scroll rail / in-view tracking */
    sectionId?: StudioPropertiesSectionId
    collapsible?: boolean
    /** When true, body content is hidden but header band + label remain */
    collapsed?: boolean
    onToggleCollapsed?: () => void
    /** If false, description stays visible when collapsed */
    hideDescriptionWhenCollapsed?: boolean
    /** Left gutter index icon — aligns with this section’s header when set with sectionId + onToggleCollapsed */
    railIcon?: ReactNode
    railShortLabel?: string
    railActive?: boolean
}) {
    const slug = label
        .toLowerCase()
        .replace(/&/g, '')
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
    const labelCls: Record<StudioPanelGroupTone, string> = {
        default: studioPanelText.label,
        ai: studioPanelText.labelAi,
        brand: studioPanelText.labelBrand,
        muted: studioPanelText.labelMuted,
    }
    /** Icon matches label tier so label + icon read as one unit */
    const iconTone: Record<StudioPanelGroupTone, string> = {
        default: 'text-gray-200/90',
        ai: 'text-violet-200/90',
        brand: 'text-violet-200/90',
        muted: 'text-gray-400',
    }
    const headingId = `studio-panel-${slug}`
    const sectionDomId = sectionId ? `jp-studio-section-${sectionId}` : undefined
    const bodyId = sectionDomId ? `${sectionDomId}-body` : undefined
    const showDescription = Boolean(description) && !(collapsed && hideDescriptionWhenCollapsed)

    const showRail =
        Boolean(sectionId && onToggleCollapsed && railIcon && railShortLabel) &&
        typeof railActive === 'boolean'

    /** When the rail shows the section glyph, skip the inline header icon to avoid duplicates. */
    const showHeaderIcon = Boolean(icon) && !showRail

    const labelRowBottomMargin = showRail ? 'mb-1.5' : 'mb-2'

    const chevronButton =
        collapsible && onToggleCollapsed ? (
            <button
                type="button"
                className={`shrink-0 rounded-md border border-gray-800/80 bg-gray-900/40 p-0.5 text-gray-500 transition-colors hover:border-gray-600 hover:bg-gray-800/50 hover:text-gray-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/45 ${!showRail && showDescription ? 'mt-0.5 self-start' : ''}`}
                aria-expanded={!collapsed}
                aria-controls={!collapsed && bodyId ? bodyId : undefined}
                title={collapsed ? `Expand ${label}` : `Collapse ${label}`}
                aria-label={collapsed ? `Expand ${label} section` : `Collapse ${label} section`}
                onClick={onToggleCollapsed}
            >
                {collapsed ? (
                    <ChevronRightIcon className="h-3.5 w-3.5" aria-hidden />
                ) : (
                    <ChevronDownIcon className="h-3.5 w-3.5" aria-hidden />
                )}
            </button>
        ) : null

    const labelRow = (
        <div
            className={`${labelRowBottomMargin} flex min-w-0 justify-between gap-1.5 ${showDescription ? 'items-start' : 'items-center'}`}
        >
            <div
                className={`flex min-w-0 flex-1 gap-1.5 ${showDescription ? 'items-start' : 'items-center'}`}
            >
                {showHeaderIcon ? (
                    <span
                        className={`shrink-0 [&>svg]:h-3 [&>svg]:w-3 ${showDescription ? 'mt-[0.2rem]' : ''} ${iconTone[tone]}`}
                        aria-hidden
                    >
                        {icon}
                    </span>
                ) : null}
                <div className="min-w-0 flex-1">
                    <h2 id={headingId} className={`leading-tight ${labelCls[tone]}`}>
                        {label}
                    </h2>
                    {showDescription ? (
                        <p className={`${studioPanelText.sectionDesc} mt-1`}>{description}</p>
                    ) : null}
                </div>
            </div>
            {chevronButton}
        </div>
    )

    const bodyBlock =
        !collapsed ? (
            <div id={bodyId} className={contentClassName || 'space-y-2.5'}>
                {children}
            </div>
        ) : null

    /** Rail + title: items-center keeps label vertically centered with the icon in collapsed rows */
    const railTitleRow = (
        <div
            className={`flex min-w-0 items-center gap-0 ${!first ? `border-t ${sectionRule}` : ''} ${!first ? 'mt-4' : ''}`}
        >
            <div
                className={`${sectionRailGutterClass} flex shrink-0 flex-col items-center justify-center ${railGutterChrome} py-1`}
            >
                <StudioSectionRailIconButton
                    shortLabel={railShortLabel!}
                    icon={railIcon}
                    expanded={!collapsed}
                    active={railActive!}
                    onToggle={onToggleCollapsed!}
                />
            </div>
            <div
                className={`min-w-0 flex-1 bg-gradient-to-b from-gray-800/12 to-transparent py-1 pr-3.5 ${railContentPl}`}
            >
                <div className="flex min-w-0 items-center justify-between gap-1.5">
                    <h2
                        id={headingId}
                        className={`mb-0 min-w-0 flex-1 pb-0 leading-none ${labelCls[tone]}`}
                    >
                        {label}
                    </h2>
                    {chevronButton}
                </div>
            </div>
        </div>
    )

    const railDescriptionBlock = showDescription ? (
        <div className={`flex min-w-0 border-t ${sectionRule}`}>
            <div className={railGutterSpacerClass} aria-hidden />
            <div className={`min-w-0 flex-1 bg-gradient-to-b from-gray-800/8 to-transparent py-1.5 pr-3.5 ${railContentPl}`}>
                <p className={studioPanelText.sectionDesc}>{description}</p>
            </div>
        </div>
    ) : null

    const railBodyRow =
        bodyBlock != null ? (
            <div className={`flex min-w-0 border-t ${sectionRule}`}>
                <div className={railGutterSpacerClass} aria-hidden />
                <div className={`min-w-0 flex-1 pr-3.5 pt-1.5 ${railContentPl}`}>
                    {!first ? (
                        <div
                            className={`mb-1.5 h-1.5 max-w-full rounded-sm ${studioPanelPattern.sectionHatch} opacity-[0.65]`}
                            aria-hidden
                        />
                    ) : null}
                    {bodyBlock}
                </div>
            </div>
        ) : null

    return (
        <section
            id={sectionDomId}
            className={className}
            aria-labelledby={headingId}
            {...(sectionId ? { 'data-studio-section': sectionId } : {})}
        >
            {showRail ? (
                <div className="-mx-3.5 flex flex-col">
                    {railTitleRow}
                    {railDescriptionBlock}
                    {railBodyRow}
                </div>
            ) : (
                <>
                    {!first ? (
                        <div className="-mx-3.5 mt-4 border-t border-gray-700/85" aria-hidden>
                            <div className={studioPanelPattern.sectionHeaderBand}>
                                <div className={studioPanelPattern.sectionHeaderHatch} />
                            </div>
                        </div>
                    ) : null}
                    <div className={first ? '' : 'mt-1.5'}>
                        {labelRow}
                        {bodyBlock}
                    </div>
                </>
            )}
        </section>
    )
}

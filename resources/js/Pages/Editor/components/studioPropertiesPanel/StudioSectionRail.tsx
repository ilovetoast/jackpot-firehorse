import type { ReactNode } from 'react'

/** Shared rail icon states — compact marker; expanded vs active vs collapsed */
export function studioSectionRailIconButtonClasses(expanded: boolean, active: boolean): string {
    if (active && expanded) {
        return 'border-indigo-400/40 bg-indigo-600/25 text-indigo-50 ring-1 ring-indigo-400/22'
    }
    if (active && !expanded) {
        return 'border-indigo-500/30 bg-indigo-950/30 text-indigo-100/95 ring-1 ring-indigo-500/18'
    }
    if (expanded) {
        return 'border-indigo-500/20 bg-gray-800/40 text-indigo-200/85 ring-1 ring-indigo-500/12'
    }
    return 'border-gray-800/75 bg-gray-900/35 text-gray-500 hover:border-gray-700/80 hover:bg-gray-800/40 hover:text-gray-300'
}

/**
 * Single section index control — sits in the left gutter aligned with that section’s header.
 */
export function StudioSectionRailIconButton({
    shortLabel,
    icon,
    expanded,
    active,
    onToggle,
}: {
    shortLabel: string
    icon: ReactNode
    expanded: boolean
    active: boolean
    onToggle: () => void
}) {
    const title = `${shortLabel} — ${expanded ? 'expanded' : 'collapsed'}`
    return (
        <button
            type="button"
            title={title}
            aria-label={title}
            aria-expanded={expanded}
            onClick={onToggle}
            className={`box-border inline-flex size-9 shrink-0 items-center justify-center rounded border p-0 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400/50 focus-visible:ring-offset-0 ${studioSectionRailIconButtonClasses(expanded, active)}`}
        >
            <span className="[&>svg]:h-4 [&>svg]:w-4">{icon}</span>
        </button>
    )
}

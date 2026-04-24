import type { ReactNode } from 'react'
import { studioPanelText } from './studioPanelUi'

export function StudioPanelSection({
    title,
    children,
    className = '',
    titleClassName,
}: {
    title?: string
    children: ReactNode
    className?: string
    /** Override default in-card subsection label */
    titleClassName?: string
}) {
    const t = titleClassName ?? `${studioPanelText.fieldLabel} uppercase tracking-wide`
    return (
        <section className={`space-y-2 ${className}`}>
            {title ? <h3 className={t}>{title}</h3> : null}
            {children}
        </section>
    )
}

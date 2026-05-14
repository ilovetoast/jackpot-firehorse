import { PLACEMENT_SURFACES, PLACEMENT_TONE_RING } from './surfaces'

/**
 * Contextual info / confirm panel (library bulk actions, inline notices, etc.).
 *
 * @param {import('./surfaces').Placement} [placement='tenant']
 * @param {import('./surfaces').PlacementTone} [tone='default']
 * @param {string} [title]
 * @param {React.ReactNode} children
 * @param {React.ReactNode} [footer]
 * @param {string} [className]
 * @param {string} [contentClassName]
 */
export default function PlacementNotice({
    placement = 'tenant',
    tone = 'default',
    title,
    children,
    footer,
    className = '',
    contentClassName = '',
}) {
    const s = PLACEMENT_SURFACES[placement] ?? PLACEMENT_SURFACES.tenant
    const ring = PLACEMENT_TONE_RING[tone] ?? ''

    return (
        <div className={`${s.panel} p-4 ${ring} ${className}`.trim()}>
            {title ? <h3 className={`text-sm font-semibold mb-2 ${s.title}`}>{title}</h3> : null}
            <div className={`text-sm space-y-3 ${s.body} ${contentClassName}`.trim()}>{children}</div>
            {footer ? <div className={`mt-3 text-xs ${s.hint}`}>{footer}</div> : null}
        </div>
    )
}

/**
 * Checkbox acknowledgement row matching {@link PlacementNotice} surfaces.
 */
export function PlacementNoticeAckRow({
    placement = 'tenant',
    tone = 'default',
    checked,
    onChange,
    label,
    className = '',
    disabled = false,
}) {
    const s = PLACEMENT_SURFACES[placement] ?? PLACEMENT_SURFACES.tenant
    const ring = PLACEMENT_TONE_RING[tone] ?? ''

    return (
        <label
            className={`mb-4 flex cursor-pointer items-start gap-3 ${s.panel} p-4 ${ring} ${
                disabled ? 'pointer-events-none opacity-60' : ''
            } ${className}`.trim()}
        >
            <input
                type="checkbox"
                className={`mt-0.5 h-4 w-4 rounded border-gray-300 ${s.checkbox}`}
                checked={checked}
                onChange={onChange}
                disabled={disabled}
            />
            <span className={`text-sm ${s.body}`}>{label}</span>
        </label>
    )
}

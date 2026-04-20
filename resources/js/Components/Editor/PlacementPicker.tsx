import type { Placement } from '../../utils/snapEngine'
import { PLACEMENTS } from '../../utils/snapEngine'

type PlacementPickerProps = {
    value?: Placement | null
    onChange: (p: Placement) => void
    size?: 'sm' | 'md'
    disabled?: boolean
    /** Optional label rendered above the grid. */
    label?: string
    className?: string
}

const LABELS: Record<Placement, string> = {
    top_left: 'Top left',
    top_center: 'Top center',
    top_right: 'Top right',
    middle_left: 'Middle left',
    middle_center: 'Middle center',
    middle_right: 'Middle right',
    bottom_left: 'Bottom left',
    bottom_center: 'Bottom center',
    bottom_right: 'Bottom right',
}

/**
 * 3x3 placement picker. Clicking a cell writes its 9-slot token to `onChange`.
 * Used by the properties panel Basic-mode quadrant control and the template
 * wizard's per-layer placement control. Pure presentational — callers are
 * responsible for translating the Placement token to actual (x,y) coords via
 * `placementToXY()` from the snap engine.
 */
export default function PlacementPicker({
    value,
    onChange,
    size = 'md',
    disabled = false,
    label,
    className,
}: PlacementPickerProps) {
    const cellSize = size === 'sm' ? 'h-6 w-6' : 'h-10 w-10'
    const gap = size === 'sm' ? 'gap-0.5' : 'gap-1'

    return (
        <div className={className}>
            {label && (
                <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-400">{label}</p>
            )}
            <div
                role="radiogroup"
                aria-label={label ?? 'Placement'}
                className={`inline-grid grid-cols-3 ${gap} rounded-md border border-gray-800 bg-gray-900/60 p-1`}
            >
                {PLACEMENTS.map((p) => {
                    const selected = value === p
                    return (
                        <button
                            key={p}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            aria-label={`Place ${LABELS[p].toLowerCase()}`}
                            title={LABELS[p]}
                            disabled={disabled}
                            onClick={() => onChange(p)}
                            // Unselected cells read cleanly as "empty slots" without
                            // per-cell borders — the container border provides enough
                            // frame. The selected cell still gets a ring to stand out.
                            className={`${cellSize} rounded transition-colors ${
                                selected
                                    ? 'bg-indigo-500 shadow-[0_0_0_2px_rgba(99,102,241,0.35)]'
                                    : 'bg-gray-800/70 hover:bg-gray-700'
                            } ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}`}
                        />
                    )
                })}
            </div>
        </div>
    )
}

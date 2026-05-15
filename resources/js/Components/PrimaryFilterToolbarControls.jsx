/**
 * Shared toolbar chrome for primary metadata filters (Assets / Executions):
 * segmented toggles when option count is small, Collections-style native select when larger.
 *
 * Selected segment fill matches {@link ../Components/AddAssetButton.jsx} — {@link getWorkspacePrimaryActionButtonColors}
 * + {@link getSolidFillButtonForegroundHex} — not {@link ensureAccentContrastOnWhite} on raw primary (which over-darkens
 * saturated oranges on white).
 */
import { useId } from 'react'
import { usePage } from '@inertiajs/react'
import { hexToRgba, getSolidFillButtonForegroundHex, getWorkspacePrimaryActionButtonColors } from '../utils/colorUtils'

/** Max number of distinct option values to show as segmented buttons (excluding "Any"). */
export const PRIMARY_FILTER_SEGMENT_MAX = 6

function normalizeHex(c) {
    if (!c || typeof c !== 'string') return '#6366f1'
    const t = c.trim()
    return t.startsWith('#') ? t : `#${t}`
}

function valuesMatchOption(optionValue, current) {
    if (current === null || current === undefined || current === '') return false
    if (optionValue === true || optionValue === false) {
        if (current === true || current === false) return optionValue === current
        if (optionValue === true) return current === 'true' || current === 1 || current === '1'
        if (optionValue === false) return current === 'false' || current === 0 || current === '0'
        return false
    }
    return String(optionValue) === String(current)
}

/**
 * @param {Object} props
 * @param {string|null} props.label — field label (uppercase row, e.g. "Photo Type")
 * @param {string} props.accentColor
 * @param {{ value: *, label: string }[]} props.options
 * @param {*} props.value — null/undefined/'' = "Any"
 * @param {(v: *) => void} props.onChange
 * @param {string} [props.anyLabel]
 */
export function SegmentedPrimaryFilter({ label, accentColor, options, value, onChange, anyLabel = 'Any' }) {
    const { auth } = usePage().props
    const { resting: selectedBg } = getWorkspacePrimaryActionButtonColors(auth?.activeBrand)
    const ringHex = normalizeHex(accentColor)
    const isAny = value === null || value === undefined || value === ''

    const selectedSolidFg = getSolidFillButtonForegroundHex(selectedBg)
    const activeStyle = {
        backgroundColor: selectedBg,
        color: selectedSolidFg,
        boxShadow: `0 1px 2px ${hexToRgba('#000000', 0.06)}`,
    }

    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            {label ? (
                <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{label}</span>
            ) : null}
            <div
                style={{ ['--pf-accent']: ringHex }}
                className="inline-flex flex-wrap items-center gap-0.5 rounded-lg border border-slate-200 bg-slate-100/90 p-0.5 shadow-inner"
                role="group"
                aria-label={label || 'Filter options'}
            >
                <button
                    type="button"
                    aria-pressed={isAny}
                    onClick={() => onChange(null)}
                    style={isAny ? activeStyle : undefined}
                    className={`rounded-md px-2.5 py-1 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pf-accent)] focus-visible:ring-offset-2 ${
                        isAny ? 'font-semibold' : 'text-slate-600 hover:bg-white/80 hover:text-slate-800'
                    }`}
                >
                    {anyLabel}
                </button>
                {options.map((opt) => {
                    const active = !isAny && valuesMatchOption(opt.value, value)
                    return (
                        <button
                            key={String(opt.value)}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(opt.value)}
                            style={active ? activeStyle : undefined}
                            className={`rounded-md px-2.5 py-1 text-xs font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--pf-accent)] focus-visible:ring-offset-2 ${
                                active ? 'font-semibold' : 'text-slate-600 hover:bg-white/80 hover:text-slate-800'
                            }`}
                        >
                            {opt.label}
                        </button>
                    )
                })}
            </div>
        </div>
    )
}

/**
 * Native select styled like Collections category control.
 */
export function CollectionStyleSelect({ label, accentColor, value, onChange, disabled, children }) {
    const id = useId()
    const { auth } = usePage().props
    const { resting: restingFill } = getWorkspacePrimaryActionButtonColors(auth?.activeBrand)
    const fallback = normalizeHex(accentColor)
    const chromeAccent = restingFill || fallback

    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            {label ? (
                <label htmlFor={id} className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    {label}
                </label>
            ) : null}
            <select
                id={id}
                value={value === null || value === undefined ? '' : value}
                onChange={onChange}
                disabled={disabled}
                style={{ accentColor: chromeAccent, ['--pf-accent']: fallback }}
                className="max-w-[14rem] min-w-[8.5rem] rounded-md border border-slate-200 bg-white py-1.5 pl-2 pr-8 text-xs font-medium text-slate-800 shadow-sm focus:border-slate-300 focus:outline-none focus:ring-2 focus:ring-[var(--pf-accent)] focus:ring-offset-0 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
            >
                {children}
            </select>
        </div>
    )
}

import { ArrowPathIcon } from '@heroicons/react/24/outline'
import type { ChangeEvent } from 'react'
import { studioPanelInputs, studioPanelText } from './studioPanelUi'

function overlayFillPct(value: number, min: number, max: number): number {
    if (max <= min) return 0
    const clamped = Math.min(max, Math.max(min, value))
    return ((clamped - min) / (max - min)) * 100
}

/**
 * Native range with transparent track; DOM renders muted track + branded fill.
 * Use inside Studio properties (and anywhere else white platform tracks must not show).
 */
export function StudioOverlayRange({
    id,
    'aria-label': ariaLabel,
    min,
    max,
    step,
    value,
    disabled,
    onChange,
    className = '',
    inputClassName = '',
}: {
    id?: string
    'aria-label': string
    min: number
    max: number
    step?: number
    value: number
    disabled?: boolean
    onChange: (e: ChangeEvent<HTMLInputElement>) => void
    className?: string
    /** Extra classes on the native range (e.g. cursor-ew-resize). */
    inputClassName?: string
}) {
    const clamped = Math.min(max, Math.max(min, value))
    const pct = overlayFillPct(clamped, min, max)
    return (
        <div className={`relative flex h-7 items-center ${className}`}>
            <div
                className="pointer-events-none absolute inset-x-0 top-1/2 h-7 -translate-y-1/2 px-0.5"
                aria-hidden
            >
                <div className="flex h-full items-center">
                    <div className="relative h-1.5 w-full rounded-full bg-gray-700/95 shadow-[inset_0_0_0_1px_rgba(0,0,0,0.35)]">
                        <div
                            className="absolute inset-y-0 left-0 min-w-0 rounded-full bg-gradient-to-r from-indigo-500 via-indigo-400 to-violet-500"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                </div>
            </div>
            <input
                id={id}
                type="range"
                min={min}
                max={max}
                step={step}
                disabled={disabled}
                value={Number.isFinite(clamped) ? clamped : min}
                onChange={onChange}
                aria-label={ariaLabel}
                className={`jp-studio-range-overlay-input relative z-10 h-full w-full min-h-[28px] cursor-pointer ${inputClassName}`}
            />
        </div>
    )
}

export function StudioSliderField({
    id,
    label,
    value,
    onChange,
    min,
    max,
    step = 1,
    unit = '',
    disabled,
    showReset,
    onReset,
    inputClassName,
}: {
    id: string
    label: string
    value: number
    onChange: (next: number) => void
    min: number
    max: number
    step?: number
    unit?: string
    disabled?: boolean
    showReset?: boolean
    onReset?: () => void
    inputClassName?: string
}) {
    const clamped = Math.min(max, Math.max(min, value))
    const onSlider = (e: ChangeEvent<HTMLInputElement>) => {
        const n = Number(e.target.value)
        if (Number.isNaN(n)) return
        onChange(n)
    }
    const onInput = (e: ChangeEvent<HTMLInputElement>) => {
        const n = Number(e.target.value)
        if (Number.isNaN(n)) return
        onChange(Math.min(max, Math.max(min, n)))
    }
    const numClass = inputClassName ?? studioPanelInputs.compactNumber
    return (
        <div className={disabled ? 'opacity-45' : ''}>
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <label htmlFor={`${id}-slider`} className={studioPanelText.fieldLabel}>
                    {label}
                </label>
                <div className="flex items-center gap-1">
                    {showReset && onReset ? (
                        <button
                            type="button"
                            title={`Reset ${label}`}
                            aria-label={`Reset ${label} to default`}
                            disabled={disabled}
                            onClick={onReset}
                            className="rounded-md p-1 text-gray-500 transition-colors hover:bg-white/[0.06] hover:text-gray-300 disabled:opacity-40"
                        >
                            <ArrowPathIcon className="h-3 w-3" aria-hidden />
                        </button>
                    ) : null}
                    <input
                        id={`${id}-num`}
                        type="number"
                        min={min}
                        max={max}
                        step={step}
                        value={Number.isFinite(clamped) ? clamped : min}
                        disabled={disabled}
                        onChange={onInput}
                        className={numClass}
                    />
                    {unit ? <span className={studioPanelText.micro}>{unit}</span> : null}
                </div>
            </div>
            <StudioOverlayRange
                id={`${id}-slider`}
                aria-label={label}
                min={min}
                max={max}
                step={step}
                value={Number.isFinite(clamped) ? clamped : min}
                disabled={disabled}
                onChange={onSlider}
            />
        </div>
    )
}

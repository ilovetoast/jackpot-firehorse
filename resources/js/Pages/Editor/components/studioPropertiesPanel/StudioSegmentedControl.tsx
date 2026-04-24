import type { ReactNode } from 'react'

export type StudioSegment<T extends string> = { value: T; label: ReactNode; title?: string }

export function StudioSegmentedControl<T extends string>({
    value,
    onChange,
    segments,
    disabled,
    'aria-label': ariaLabel,
}: {
    value: T
    onChange: (v: T) => void
    segments: StudioSegment<T>[]
    disabled?: boolean
    'aria-label': string
}) {
    return (
        <div
            className={`flex rounded-md border border-gray-800/90 bg-gray-900/50 p-px shadow-inner ${disabled ? 'opacity-45' : ''}`}
            role="group"
            aria-label={ariaLabel}
        >
            {segments.map((s) => {
                const on = s.value === value
                return (
                    <button
                        key={s.value}
                        type="button"
                        title={s.title}
                        disabled={disabled}
                        onClick={() => onChange(s.value)}
                        className={`min-w-0 flex-1 rounded-[5px] px-1.5 py-1 text-[10px] font-semibold transition-colors sm:text-[11px] ${
                            on
                                ? 'bg-indigo-600/88 text-white shadow-sm ring-1 ring-indigo-400/25'
                                : 'text-gray-400 hover:bg-gray-800/60 hover:text-gray-200'
                        }`}
                    >
                        {s.label}
                    </button>
                )
            })}
        </div>
    )
}

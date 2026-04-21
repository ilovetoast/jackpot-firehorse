import type { BrandContext } from './documentModel'
import { labeledBrandPalette } from './documentModel'

function normalizeHexLoose(hex: string): string {
    const s = hex.trim()
    if (s.startsWith('#') && s.length === 4 && /^#[0-9a-fA-F]{4}$/i.test(s)) {
        return `#${s[1]}${s[1]}${s[2]}${s[2]}${s[3]}${s[3]}`.toLowerCase()
    }
    return s.toLowerCase()
}

function isTransparentCss(v: string): boolean {
    const s = v.trim().toLowerCase().replace(/\s/g, '')
    return s === 'transparent' || s === 'rgba(0,0,0,0)'
}

function stopMatchesSelected(selected: string, candidate: string): boolean {
    if (isTransparentCss(selected) && isTransparentCss(candidate)) {
        return true
    }
    if (isTransparentCss(selected) || isTransparentCss(candidate)) {
        return false
    }
    return normalizeHexLoose(selected) === normalizeHexLoose(candidate)
}

export type BrandColorSwatchStripProps = {
    brandContext: BrandContext | null | undefined
    value: string
    onPick: (hex: string) => void
    disabled?: boolean
}

/** Labeled brand palette chips — shared by gradient stops and CTA solid fills. */
export function BrandColorSwatchStrip({
    brandContext,
    value,
    onPick,
    disabled = false,
}: BrandColorSwatchStripProps) {
    const labeled = labeledBrandPalette(brandContext)
    if (labeled.length === 0) {
        return null
    }
    const v = value.trim() || 'transparent'

    return (
        <div className="mb-1.5">
            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">Brand colors</p>
            <div className="flex flex-wrap items-end gap-2">
                {labeled.map(({ label: lbl, color: c }) => {
                    const active = stopMatchesSelected(v, c)
                    return (
                        <div key={`${lbl}-${c}`} className="flex flex-col items-center gap-0.5">
                            <button
                                type="button"
                                disabled={disabled}
                                title={`${lbl} brand color`}
                                className={`h-7 w-7 rounded border-2 shadow-sm ${
                                    active
                                        ? 'border-indigo-400 ring-2 ring-indigo-700'
                                        : 'border-gray-700'
                                }`}
                                style={{ backgroundColor: c }}
                                onClick={() => onPick(c)}
                            />
                            <span className="max-w-[4.5rem] truncate text-center text-[9px] font-medium text-gray-400">
                                {lbl}
                            </span>
                        </div>
                    )
                })}
            </div>
        </div>
    )
}

type Props = {
    label: string
    value: string
    onChange: (v: string) => void
    disabled?: boolean
    /** When false, hide the Transparent quick control (e.g. gradient end stop). */
    allowTransparent?: boolean
    brandContext: BrandContext | null | undefined
}

export default function FillGradientStopField({
    label,
    value,
    onChange,
    disabled = false,
    allowTransparent = true,
    brandContext,
}: Props) {
    const v = value.trim() || 'transparent'
    const hexForNative = /^#[0-9a-fA-F]{6}$/i.test(v) ? v : '#6366f1'

    return (
        <div className="space-y-2">
            <label className="mb-1 block text-[10px] font-medium uppercase tracking-wide text-gray-500">
                {label}
            </label>
            <BrandColorSwatchStrip brandContext={brandContext} value={v} onPick={onChange} disabled={disabled} />
            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">Quick</p>
            <div className="mb-2 flex flex-wrap items-center gap-1.5">
                {allowTransparent && (
                    <button
                        type="button"
                        disabled={disabled}
                        className={`rounded border px-2 py-1 text-[10px] font-medium ${
                            isTransparentCss(v)
                                ? 'border-indigo-400 bg-indigo-950/50 text-indigo-100'
                                : 'border-gray-700 text-gray-200 hover:bg-gray-800'
                        }`}
                        onClick={() => onChange('transparent')}
                    >
                        Transparent
                    </button>
                )}
                {(['#ffffff', '#000000'] as const).map((c) => {
                    const active = stopMatchesSelected(v, c)
                    return (
                        <button
                            key={c}
                            type="button"
                            disabled={disabled}
                            className={`h-7 w-7 rounded border-2 shadow-sm ${
                                active
                                    ? 'border-indigo-400 ring-2 ring-indigo-700'
                                    : 'border-gray-700'
                            }`}
                            style={{ backgroundColor: c }}
                            aria-label={c === '#ffffff' ? 'White' : 'Black'}
                            onClick={() => onChange(c)}
                        />
                    )
                })}
            </div>
            <div className="flex gap-2">
                <div
                    className="relative h-9 w-12 shrink-0 overflow-hidden rounded border border-gray-700"
                    style={
                        isTransparentCss(v)
                            ? {
                                  backgroundImage:
                                      'linear-gradient(45deg, #d1d5db 25%, transparent 25%), linear-gradient(-45deg, #d1d5db 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #d1d5db 75%), linear-gradient(-45deg, transparent 75%, #d1d5db 75%)',
                                  backgroundSize: '8px 8px',
                                  backgroundPosition: '0 0, 0 4px, 4px -4px, -4px 0',
                              }
                            : { backgroundColor: v }
                    }
                >
                    <input
                        type="color"
                        value={hexForNative}
                        disabled={disabled}
                        onChange={(e) => onChange(e.target.value)}
                        className="absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                        aria-label={`${label} picker`}
                    />
                </div>
                <input
                    type="text"
                    value={value}
                    disabled={disabled}
                    onChange={(e) => onChange(e.target.value)}
                    className="min-w-0 flex-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 font-mono text-[11px] text-gray-200"
                    placeholder={allowTransparent ? 'transparent or #RRGGBB' : '#RRGGBB'}
                />
            </div>
        </div>
    )
}

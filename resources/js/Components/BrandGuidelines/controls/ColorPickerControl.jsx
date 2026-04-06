import { useState, useRef, useEffect } from 'react'

export default function ColorPickerControl({
    label,
    value,
    onChange,
    onReset,
    showReset = false,
    /** When true, only the swatch + hex controls render (use with an external field label). */
    hideLabel = false,
}) {
    const [editing, setEditing] = useState(false)
    const [hexInput, setHexInput] = useState(value || '')
    const inputRef = useRef(null)

    useEffect(() => {
        setHexInput(value || '')
    }, [value])

    const commitHex = (hex) => {
        const cleaned = hex.startsWith('#') ? hex : `#${hex}`
        if (/^#[0-9a-fA-F]{6}$/.test(cleaned)) {
            onChange(cleaned)
        }
    }

    return (
        <div
            className={`flex items-center gap-2 ${hideLabel ? 'justify-start' : 'justify-between'}`}
        >
            {!hideLabel && (
                <span className="text-xs text-gray-500 font-medium shrink-0">{label}</span>
            )}
            <div className="flex items-center gap-1.5">
                <button
                    type="button"
                    className="w-7 h-7 rounded-md border border-gray-200 shadow-sm cursor-pointer relative overflow-hidden"
                    style={{ backgroundColor: value || '#ffffff' }}
                    onClick={() => inputRef.current?.click()}
                >
                    <input
                        ref={inputRef}
                        type="color"
                        value={value || '#ffffff'}
                        onChange={(e) => {
                            onChange(e.target.value)
                            setHexInput(e.target.value)
                        }}
                        className="absolute inset-0 opacity-0 cursor-pointer"
                    />
                </button>
                {editing ? (
                    <input
                        type="text"
                        value={hexInput}
                        onChange={(e) => setHexInput(e.target.value)}
                        onBlur={() => { commitHex(hexInput); setEditing(false) }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') { commitHex(hexInput); setEditing(false) }
                            if (e.key === 'Escape') { setHexInput(value || ''); setEditing(false) }
                        }}
                        className="w-[5.5rem] px-1.5 py-0.5 text-xs font-mono border border-gray-300 rounded text-gray-700 focus:outline-none focus:border-indigo-400"
                        autoFocus
                    />
                ) : (
                    <button
                        type="button"
                        onClick={() => setEditing(true)}
                        className="text-xs font-mono text-gray-600 hover:text-gray-900 px-1"
                    >
                        {value || '—'}
                    </button>
                )}
                {showReset && onReset && (
                    <button type="button" onClick={onReset} className="text-[10px] text-gray-400 hover:text-red-500" title="Reset">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                    </button>
                )}
            </div>
        </div>
    )
}

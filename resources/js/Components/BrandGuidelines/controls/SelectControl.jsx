import { useState, useRef, useEffect } from 'react'

export default function SelectControl({ label, value, onChange, options = [] }) {
    const [open, setOpen] = useState(false)
    const ref = useRef(null)

    useEffect(() => {
        if (!open) return
        const handleClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false)
        }
        document.addEventListener('mousedown', handleClick)
        return () => document.removeEventListener('mousedown', handleClick)
    }, [open])

    const selected = options.find((o) => o.value === value)

    return (
        <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-gray-500 font-medium shrink-0">{label}</span>
            <div className="relative" ref={ref}>
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="flex items-center gap-1 px-2 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-200 rounded-md hover:bg-gray-50 min-w-[5rem] justify-between"
                >
                    <span className="truncate">{selected?.label || value || '—'}</span>
                    <svg className="w-3 h-3 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </button>
                {open && (
                    <div className="absolute right-0 z-50 mt-1 w-full min-w-[8rem] bg-white border border-gray-200 rounded-lg shadow-lg py-1 max-h-48 overflow-y-auto">
                        {options.map((opt) => (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => { onChange(opt.value); setOpen(false) }}
                                className={`w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 ${opt.value === value ? 'text-indigo-600 font-semibold bg-indigo-50/50' : 'text-gray-700'}`}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    )
}

import { useMemo, useState, useRef, useEffect } from 'react'
import {
    LOGO_SOURCE_PRESETS,
    logoSourceToSelectValue,
    parseLogoSourceValue,
    getLogoSourceDisplayLabel,
} from './brandGuidelinesPresentationModel'

/**
 * Grouped logo source control with optional modal browse for large libraries.
 * @param {{ value: string, onChange: (v: string) => void, logoAssets: Array, label?: string }} props
 */
export default function LogoSourceSelect({ value, onChange, logoAssets = [], label = 'Logo source' }) {
    const [open, setOpen] = useState(false)
    const [modal, setModal] = useState(false)
    const [q, setQ] = useState('')
    const rootRef = useRef(null)

    const selectVal = value || logoSourceToSelectValue()

    const display = useMemo(
        () => getLogoSourceDisplayLabel(parseLogoSourceValue(selectVal), null, logoAssets),
        [selectVal, logoAssets],
    )

    const filteredLibrary = useMemo(() => {
        const assets = Array.isArray(logoAssets) ? logoAssets : []
        if (!q.trim()) return assets
        const s = q.trim().toLowerCase()
        return assets.filter((a) => (a.title && String(a.title).toLowerCase().includes(s)) || String(a.id).includes(s))
    }, [logoAssets, q])

    useEffect(() => {
        if (!open && !modal) return
        const h = (e) => {
            if (rootRef.current && !rootRef.current.contains(e.target)) {
                setOpen(false)
            }
        }
        document.addEventListener('mousedown', h)
        return () => document.removeEventListener('mousedown', h)
    }, [open, modal])

    const setSource = (val) => {
        onChange(val)
        setOpen(false)
    }

    const currentThumb = useMemo(() => {
        const src = parseLogoSourceValue(selectVal)
        if (src.type === 'brand_asset' && src.asset_id) {
            return logoAssets.find((a) => String(a.id) === String(src.asset_id))?.url
        }
        return null
    }, [selectVal, logoAssets])

    return (
        <div className="space-y-1.5" ref={rootRef}>
            <div className="text-xs text-gray-600 font-medium">{label}</div>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="w-full flex items-center gap-2 rounded-md border border-gray-200 bg-white px-2 py-1.5 text-left hover:bg-gray-50 min-h-[2.5rem]"
            >
                {currentThumb && <img src={currentThumb} alt="" className="h-7 w-7 object-contain rounded border border-gray-100 flex-shrink-0" />}
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold text-gray-900 truncate">{display.line}</p>
                    {display.sub && <p className="text-[10px] text-gray-500 truncate">{display.sub}</p>}
                </div>
                <span className="text-[10px] text-violet-600 font-medium flex-shrink-0">Change</span>
            </button>
            {open && (
                <div className="border border-gray-200 rounded-lg bg-white shadow-lg max-h-64 overflow-y-auto z-50">
                    <div className="px-2 py-1.5 text-[9px] font-semibold text-gray-400 uppercase tracking-wider border-b border-gray-100">Identity (Brand Settings)</div>
                    {LOGO_SOURCE_PRESETS.map((p) => {
                        const selected = selectVal === p.value
                        return (
                            <button
                                key={p.value}
                                type="button"
                                onClick={() => setSource(p.value)}
                                className={`w-full text-left px-2 py-1.5 text-xs flex items-center gap-2 ${selected ? 'bg-violet-50 text-violet-900' : 'text-gray-800 hover:bg-gray-50'}`}
                            >
                                <span className="h-5 w-5 flex-shrink-0 rounded bg-slate-100" aria-hidden />
                                {p.label}
                            </button>
                        )
                    })}
                    {logoAssets.length > 0 && (
                        <>
                            <div className="px-2 py-1.5 text-[9px] font-semibold text-gray-400 uppercase tracking-wider border-t border-b border-gray-100">Brand library</div>
                            {logoAssets.slice(0, 8).map((a) => {
                                const v = `brand_asset:${a.id}`
                                return (
                                    <button
                                        key={a.id}
                                        type="button"
                                        onClick={() => setSource(v)}
                                        className={`w-full text-left px-2 py-1.5 text-xs flex items-center gap-2 ${selectVal === v ? 'bg-violet-50 text-violet-900' : 'text-gray-800 hover:bg-gray-50'}`}
                                    >
                                        {a.url ? <img src={a.url} alt="" className="h-5 w-5 object-contain rounded border border-gray-100 flex-shrink-0" /> : <span className="h-5 w-5 rounded bg-slate-100" />}
                                        <span className="truncate">{a.title || `Asset #${a.id}`}</span>
                                    </button>
                                )
                            })}
                        </>
                    )}
                    <div className="px-2 py-1.5 text-[9px] font-semibold text-gray-400 uppercase tracking-wider border-t border-gray-100">Advanced</div>
                    <button
                        type="button"
                        onClick={() => {
                            const url = typeof window !== 'undefined' ? window.prompt('Image URL (https://…)', '') : ''
                            if (url && String(url).trim()) onChange(`custom_url:${String(url).trim()}`)
                        }}
                        className="w-full text-left px-2 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                    >
                        Custom image URL…
                    </button>
                    {logoAssets.length > 0 && (
                        <button
                            type="button"
                            onClick={() => { setOpen(false); setModal(true) }}
                            className="w-full text-left px-2 py-1.5 text-xs text-violet-700 font-medium border-t border-gray-100"
                        >
                            Browse all library assets…
                        </button>
                    )}
                </div>
            )}
            {modal && (
                <div className="fixed inset-0 z-[70] flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/40" role="dialog" aria-label="Choose logo asset">
                    <div className="w-full sm:max-w-md max-h-[85vh] sm:max-h-[80vh] flex flex-col bg-white rounded-t-xl sm:rounded-xl shadow-2xl border border-gray-200">
                        <div className="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                            <h4 className="text-sm font-semibold text-gray-900">Library assets</h4>
                            <button type="button" className="text-gray-500 p-1" onClick={() => { setModal(false); setQ('') }} aria-label="Close">✕</button>
                        </div>
                        <div className="p-2 border-b border-gray-100">
                            <input
                                type="search"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search by name…"
                                className="w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                            />
                        </div>
                        <div className="flex-1 overflow-y-auto p-2 space-y-1">
                            {filteredLibrary.length === 0 && <p className="text-xs text-gray-500 px-2">No matches.</p>}
                            {filteredLibrary.map((a) => {
                                const v = `brand_asset:${a.id}`
                                return (
                                    <button
                                        key={a.id}
                                        type="button"
                                        onClick={() => { onChange(v); setModal(false); setQ('') }}
                                        className="w-full flex items-center gap-2 rounded-lg border border-transparent px-2 py-2 text-left hover:bg-violet-50 hover:border-violet-100"
                                    >
                                        {a.url && <img src={a.url} alt="" className="h-10 w-10 object-contain flex-shrink-0" />}
                                        <div className="min-w-0">
                                            <p className="text-xs font-medium text-gray-900 truncate">{a.title || 'Untitled'}</p>
                                            <p className="text-[10px] text-gray-500">ID {a.id}</p>
                                        </div>
                                    </button>
                                )
                            })}
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}

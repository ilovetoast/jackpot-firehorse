/**
 * FontManager — Rich font management for Brand Guidelines builder.
 *
 * Supports:
 * - Multiple fonts with roles (primary, secondary, accent, display, body)
 * - Google/system fonts via searchable dropdown
 * - Custom/commercial fonts as name placeholders
 * - Font file URLs (WOFF2, OTF, TTF)
 * - Purchase links for commercial fonts
 * - Usage notes and style details
 * - AI-extracted font suggestions
 */
import { useState, useCallback, Fragment } from 'react'
import { Transition, Dialog } from '@headlessui/react'
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    ChevronUpDownIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
    DocumentArrowUpIcon,
} from '@heroicons/react/24/outline'

const GOOGLE_FONTS = [
    'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins',
    'Playfair Display', 'Merriweather', 'Source Sans 3', 'Raleway',
    'Nunito', 'Work Sans', 'DM Sans', 'Space Grotesk', 'Outfit',
    'Manrope', 'Plus Jakarta Sans', 'Cabin', 'Karla', 'Bitter',
    'Libre Baskerville', 'Crimson Text', 'EB Garamond', 'Cormorant Garamond',
    'Josefin Sans', 'Quicksand', 'Archivo', 'Barlow', 'Figtree', 'Sora',
]

const SYSTEM_FONTS = ['Georgia', 'Helvetica', 'Arial', 'Times New Roman', 'Courier New', 'Verdana']

const ROLES = [
    { value: 'primary', label: 'Primary / Display' },
    { value: 'secondary', label: 'Secondary / Body' },
    { value: 'accent', label: 'Accent' },
    { value: 'display', label: 'Display Only' },
    { value: 'body', label: 'Body Only' },
    { value: 'other', label: 'Other' },
]

const SOURCES = [
    { value: 'google', label: 'Google Fonts' },
    { value: 'system', label: 'System Font' },
    { value: 'custom', label: 'Custom / Licensed' },
    { value: 'unknown', label: 'Unknown / Placeholder' },
]

const SOURCE_BADGES = {
    google: { label: 'Google', className: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' },
    system: { label: 'System', className: 'bg-blue-500/15 text-blue-300 border-blue-500/30' },
    custom: { label: 'Licensed', className: 'bg-amber-500/15 text-amber-300 border-amber-500/30' },
    unknown: { label: 'Placeholder', className: 'bg-white/10 text-white/50 border-white/20' },
}

function emptyFont() {
    return {
        name: '',
        role: 'primary',
        source: 'unknown',
        styles: [],
        heading_use: null,
        body_use: null,
        usage_notes: null,
        purchase_url: null,
        file_urls: [],
    }
}

function FontEditModal({ open, onClose, font, onSave }) {
    const [draft, setDraft] = useState(font || emptyFont())
    const [styleInput, setStyleInput] = useState('')
    const [fileUrlInput, setFileUrlInput] = useState('')
    const [fontSearch, setFontSearch] = useState('')
    const [showFontPicker, setShowFontPicker] = useState(false)

    const update = (key, val) => setDraft((d) => ({ ...d, [key]: val }))

    const addStyle = () => {
        const trimmed = styleInput.trim()
        if (trimmed && !draft.styles.includes(trimmed)) {
            update('styles', [...draft.styles, trimmed])
        }
        setStyleInput('')
    }

    const removeStyle = (s) => update('styles', draft.styles.filter((x) => x !== s))

    const addFileUrl = () => {
        const trimmed = fileUrlInput.trim()
        if (trimmed && !draft.file_urls.includes(trimmed)) {
            update('file_urls', [...draft.file_urls, trimmed])
        }
        setFileUrlInput('')
    }

    const removeFileUrl = (u) => update('file_urls', draft.file_urls.filter((x) => x !== u))

    const handleSave = () => {
        if (!draft.name.trim()) return
        const detected = GOOGLE_FONTS.find((f) => f.toLowerCase() === draft.name.trim().toLowerCase())
        const detectedSystem = SYSTEM_FONTS.find((f) => f.toLowerCase() === draft.name.trim().toLowerCase())
        const source = draft.source === 'unknown'
            ? (detected ? 'google' : detectedSystem ? 'system' : 'unknown')
            : draft.source
        onSave({ ...draft, name: draft.name.trim(), source })
        onClose()
    }

    const allKnownFonts = [...GOOGLE_FONTS, ...SYSTEM_FONTS]
    const filtered = fontSearch
        ? allKnownFonts.filter((f) => f.toLowerCase().includes(fontSearch.toLowerCase()))
        : allKnownFonts

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-black/70 backdrop-blur-sm" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="mx-auto w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl border border-white/15 bg-[#1a1920] shadow-2xl">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-white/10">
                        <Dialog.Title className="text-lg font-semibold text-white">
                            {font ? 'Edit Font' : 'Add Font'}
                        </Dialog.Title>
                        <button type="button" onClick={onClose} className="text-white/50 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition-colors">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>

                    <div className="p-5 space-y-5">
                        {/* Font Name */}
                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Font Family Name</label>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={draft.name}
                                    onChange={(e) => {
                                        update('name', e.target.value)
                                        setShowFontPicker(true)
                                        setFontSearch(e.target.value)
                                    }}
                                    onFocus={() => setShowFontPicker(true)}
                                    placeholder="e.g. RBNo3.1, Montserrat, Gotham..."
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                                {showFontPicker && draft.name.length > 0 && filtered.length > 0 && (
                                    <div className="absolute z-20 mt-1 w-full rounded-lg border border-white/20 bg-[#1a1920] shadow-xl max-h-40 overflow-y-auto">
                                        {filtered.slice(0, 10).map((f) => (
                                            <button
                                                key={f}
                                                type="button"
                                                onClick={() => {
                                                    update('name', f)
                                                    update('source', GOOGLE_FONTS.includes(f) ? 'google' : 'system')
                                                    setShowFontPicker(false)
                                                }}
                                                className="w-full text-left px-3 py-2 text-sm text-white/80 hover:bg-white/10 transition-colors"
                                                style={{ fontFamily: `${f}, system-ui, sans-serif` }}
                                            >
                                                {f}
                                                <span className="ml-2 text-[10px] text-white/40">
                                                    {GOOGLE_FONTS.includes(f) ? '(Google)' : '(System)'}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <p className="mt-1 text-[11px] text-white/40">
                                Type a Google/system font name, or enter any commercial font name as a placeholder.
                            </p>
                        </div>

                        {/* Role + Source */}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Role</label>
                                <select
                                    value={draft.role}
                                    onChange={(e) => update('role', e.target.value)}
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 appearance-none"
                                >
                                    {ROLES.map((r) => (
                                        <option key={r.value} value={r.value} className="bg-[#1a1920]">{r.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Source</label>
                                <select
                                    value={draft.source}
                                    onChange={(e) => update('source', e.target.value)}
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 appearance-none"
                                >
                                    {SOURCES.map((s) => (
                                        <option key={s.value} value={s.value} className="bg-[#1a1920]">{s.label}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Available Styles/Weights */}
                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Available Styles / Weights</label>
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={styleInput}
                                    onChange={(e) => setStyleInput(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addStyle() } }}
                                    placeholder="e.g. Bold, Light Italic..."
                                    className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                                <button type="button" onClick={addStyle} className="px-3 py-2 rounded-lg border border-white/20 bg-white/5 text-white/60 hover:text-white hover:bg-white/10 text-sm transition-colors">
                                    Add
                                </button>
                            </div>
                            {draft.styles.length > 0 && (
                                <div className="flex flex-wrap gap-1.5 mt-2">
                                    {draft.styles.map((s) => (
                                        <span key={s} className="inline-flex items-center gap-1 rounded-md bg-white/10 border border-white/15 px-2 py-0.5 text-xs text-white/70">
                                            {s}
                                            <button type="button" onClick={() => removeStyle(s)} className="text-white/40 hover:text-white/80">
                                                <XMarkIcon className="w-3 h-3" />
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Usage Notes */}
                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Usage Notes</label>
                            <textarea
                                value={draft.usage_notes || ''}
                                onChange={(e) => update('usage_notes', e.target.value || null)}
                                rows={2}
                                placeholder="e.g. Bold/Extra Bold for headlines, Light for body copy"
                                className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30 resize-none"
                            />
                        </div>

                        {/* Heading / Body Usage */}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Heading Usage</label>
                                <input
                                    type="text"
                                    value={draft.heading_use || ''}
                                    onChange={(e) => update('heading_use', e.target.value || null)}
                                    placeholder="e.g. ALL CAPS, heavy weight"
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                            </div>
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Body Usage</label>
                                <input
                                    type="text"
                                    value={draft.body_use || ''}
                                    onChange={(e) => update('body_use', e.target.value || null)}
                                    placeholder="e.g. Regular weight, 16px"
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                            </div>
                        </div>

                        {/* Purchase URL (for custom fonts) */}
                        {(draft.source === 'custom' || draft.source === 'unknown') && (
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Purchase / License URL</label>
                                <input
                                    type="url"
                                    value={draft.purchase_url || ''}
                                    onChange={(e) => update('purchase_url', e.target.value || null)}
                                    placeholder="https://fonts.adobe.com/..."
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                            </div>
                        )}

                        {/* Font File URLs */}
                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">
                                Font Files
                                <span className="text-white/30 ml-1">(WOFF2, OTF, TTF)</span>
                            </label>
                            <div className="flex gap-2">
                                <input
                                    type="url"
                                    value={fileUrlInput}
                                    onChange={(e) => setFileUrlInput(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addFileUrl() } }}
                                    placeholder="https://... .woff2"
                                    className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                                <button type="button" onClick={addFileUrl} className="px-3 py-2 rounded-lg border border-white/20 bg-white/5 text-white/60 hover:text-white hover:bg-white/10 text-sm transition-colors">
                                    <DocumentArrowUpIcon className="w-4 h-4" />
                                </button>
                            </div>
                            {draft.file_urls.length > 0 && (
                                <div className="mt-2 space-y-1">
                                    {draft.file_urls.map((u) => (
                                        <div key={u} className="flex items-center gap-2 text-xs text-white/50 bg-white/5 rounded-md px-2 py-1.5 border border-white/10">
                                            <DocumentArrowUpIcon className="w-3.5 h-3.5 flex-shrink-0" />
                                            <span className="truncate flex-1">{u.split('/').pop()}</span>
                                            <button type="button" onClick={() => removeFileUrl(u)} className="text-white/30 hover:text-white/70">
                                                <XMarkIcon className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <p className="mt-1 text-[11px] text-white/30">
                                For web use, WOFF2 is recommended. For generative assets, OTF or TTF.
                            </p>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 px-5 py-4 border-t border-white/10">
                        <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm text-white/60 hover:text-white hover:bg-white/10 transition-colors">
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={!draft.name.trim()}
                            className="px-5 py-2 rounded-lg text-sm font-medium bg-indigo-500 text-white hover:bg-indigo-400 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            {font ? 'Save Changes' : 'Add Font'}
                        </button>
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    )
}

export default function FontManager({ fonts = [], onChange, suggestions = [], onApplySuggestion }) {
    const [editingIdx, setEditingIdx] = useState(null)
    const [isAdding, setIsAdding] = useState(false)

    const fontsList = Array.isArray(fonts) ? fonts : []

    const handleSave = useCallback((font) => {
        if (editingIdx !== null) {
            const updated = [...fontsList]
            updated[editingIdx] = font
            onChange(updated)
            setEditingIdx(null)
        } else {
            onChange([...fontsList, font])
            setIsAdding(false)
        }
    }, [fontsList, editingIdx, onChange])

    const handleDelete = useCallback((idx) => {
        onChange(fontsList.filter((_, i) => i !== idx))
    }, [fontsList, onChange])

    const handleApplySuggestion = useCallback(() => {
        if (suggestions.length > 0 && onApplySuggestion) {
            onApplySuggestion(suggestions)
        }
    }, [suggestions, onApplySuggestion])

    const roleLabel = (role) => ROLES.find((r) => r.value === role)?.label || role
    const sourceBadge = (source) => SOURCE_BADGES[source] || SOURCE_BADGES.unknown

    return (
        <div>
            {/* Existing fonts */}
            {fontsList.length > 0 && (
                <div className="space-y-3">
                    {fontsList.map((font, idx) => {
                        const badge = sourceBadge(font.source)
                        const fontName = typeof font === 'string' ? font : (font?.name || 'Unnamed')
                        const fontObj = typeof font === 'string' ? { name: font, role: idx === 0 ? 'primary' : 'secondary', source: 'unknown', styles: [], heading_use: null, body_use: null, usage_notes: null, purchase_url: null, file_urls: [] } : font
                        return (
                            <div key={idx} className="group rounded-xl border border-white/15 bg-white/[0.03] hover:bg-white/[0.06] transition-colors">
                                <div className="flex items-start gap-3 p-4">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 mb-1">
                                            <span
                                                className="text-base font-semibold text-white truncate"
                                                style={{ fontFamily: `${fontName}, system-ui, sans-serif` }}
                                            >
                                                {fontName}
                                            </span>
                                            <span className={`inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-medium ${badge.className}`}>
                                                {badge.label}
                                            </span>
                                        </div>
                                        <p className="text-xs text-white/40">{roleLabel(fontObj.role)}</p>
                                        {fontObj.styles?.length > 0 && (
                                            <div className="flex flex-wrap gap-1 mt-1.5">
                                                {fontObj.styles.map((s) => (
                                                    <span key={s} className="text-[10px] text-white/40 bg-white/5 rounded px-1.5 py-0.5 border border-white/10">
                                                        {s}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                        {fontObj.usage_notes && (
                                            <p className="text-xs text-white/35 mt-1.5 leading-relaxed">{fontObj.usage_notes}</p>
                                        )}
                                        {fontObj.purchase_url && (
                                            <a href={fontObj.purchase_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[11px] text-indigo-400/80 hover:text-indigo-300 mt-1.5">
                                                <ArrowTopRightOnSquareIcon className="w-3 h-3" />
                                                License / Purchase
                                            </a>
                                        )}
                                        {fontObj.file_urls?.length > 0 && (
                                            <div className="flex items-center gap-1 mt-1">
                                                <DocumentArrowUpIcon className="w-3 h-3 text-white/30" />
                                                <span className="text-[10px] text-white/30">{fontObj.file_urls.length} font file{fontObj.file_urls.length !== 1 ? 's' : ''}</span>
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button
                                            type="button"
                                            onClick={() => setEditingIdx(idx)}
                                            className="p-1.5 rounded-lg text-white/40 hover:text-white hover:bg-white/10 transition-colors"
                                        >
                                            <PencilSquareIcon className="w-4 h-4" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleDelete(idx)}
                                            className="p-1.5 rounded-lg text-white/40 hover:text-red-400 hover:bg-red-500/10 transition-colors"
                                        >
                                            <TrashIcon className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )
                    })}
                </div>
            )}

            {/* Suggestion banner */}
            {suggestions.length > 0 && fontsList.length === 0 && (
                <div className="mt-3 rounded-xl border border-indigo-500/30 bg-indigo-500/10 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-medium text-indigo-300">
                                {suggestions.length} font{suggestions.length !== 1 ? 's' : ''} detected from Brand Guidelines
                            </p>
                            <div className="mt-2 space-y-1">
                                {suggestions.map((f, i) => (
                                    <p key={i} className="text-xs text-white/50">
                                        <span className="text-white/70 font-medium">{f.name}</span>
                                        {' — '}
                                        {f.role}
                                        {f.styles?.length > 0 && ` (${f.styles.slice(0, 3).join(', ')}${f.styles.length > 3 ? '...' : ''})`}
                                    </p>
                                ))}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={handleApplySuggestion}
                            className="flex-shrink-0 px-3 py-1.5 rounded-lg bg-indigo-500/30 border border-indigo-500/40 text-indigo-300 hover:bg-indigo-500/40 text-xs font-medium transition-colors"
                        >
                            Apply
                        </button>
                    </div>
                </div>
            )}

            {/* Add button */}
            <button
                type="button"
                onClick={() => setIsAdding(true)}
                className="mt-3 w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-white/15 hover:border-white/30 py-3 text-sm text-white/40 hover:text-white/60 hover:bg-white/[0.02] transition-all"
            >
                <PlusIcon className="w-4 h-4" />
                Add Font
            </button>

            {/* Empty state hint */}
            {fontsList.length === 0 && suggestions.length === 0 && (
                <p className="mt-2 text-[11px] text-white/30 text-center">
                    Add fonts from Google Fonts, system fonts, or enter commercial font names as placeholders.
                </p>
            )}

            {/* Edit modal */}
            {editingIdx !== null && (
                <FontEditModal
                    open={true}
                    onClose={() => setEditingIdx(null)}
                    font={typeof fontsList[editingIdx] === 'string' ? { ...emptyFont(), name: fontsList[editingIdx] } : fontsList[editingIdx]}
                    onSave={handleSave}
                />
            )}

            {/* Add modal */}
            {isAdding && (
                <FontEditModal
                    open={true}
                    onClose={() => setIsAdding(false)}
                    font={null}
                    onSave={handleSave}
                />
            )}
        </div>
    )
}

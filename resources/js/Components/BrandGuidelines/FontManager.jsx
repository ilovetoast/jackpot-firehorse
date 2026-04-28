/**
 * FontManager — Rich font management for Brand Guidelines builder.
 *
 * Three ways to add fonts:
 * 1. Google Fonts picker — searchable dropdown, free & open-source
 * 2. Custom / commercial — manual name entry with license URL, file uploads
 * 3. External CSS URL — paste a Google Fonts or self-hosted stylesheet link
 */
import { useState, useCallback, useEffect, useRef } from 'react'
import { extractFontMetadataFromFile, mergeFontMetadataIntoDraft } from '@/utils/fontFileMetadata'
import { Dialog } from '@headlessui/react'
import {
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    XMarkIcon,
    ArrowTopRightOnSquareIcon,
    DocumentArrowUpIcon,
    MagnifyingGlassIcon,
} from '@heroicons/react/24/outline'

const GOOGLE_FONTS = [
    'ABeeZee','Abel','Abril Fatface','Acme','Alegreya','Alegreya Sans','Alfa Slab One',
    'Alice','Amiri','Antic Slab','Anton','Archivo','Archivo Black','Archivo Narrow',
    'Arimo','Arsenal','Arvo','Asap','Asap Condensed','Assistant',
    'Barlow','Barlow Condensed','Barlow Semi Condensed','Be Vietnam Pro','Bebas Neue',
    'Bitter','Black Ops One','Bodoni Moda','Bree Serif','Bricolage Grotesque',
    'Cabin','Cairo','Cantarell','Cardo','Catamaran','Caveat','Chakra Petch',
    'Chivo','Cinzel','Comfortaa','Commissioner','Cormorant','Cormorant Garamond',
    'Crimson Pro','Crimson Text','Cuprum',
    'DM Mono','DM Sans','DM Serif Display','DM Serif Text','Dancing Script',
    'Domine','Dosis','EB Garamond','El Messiri','Encode Sans','Exo 2',
    'Fira Code','Fira Sans','Fira Sans Condensed','Figtree','Fjalla One',
    'Francois One','Frank Ruhl Libre','Fraunces',
    'Gelasio','Gloria Hallelujah','Gothic A1','Great Vibes',
    'Hanken Grotesk','Heebo','Hind','Hind Madurai','Hind Siliguri',
    'IBM Plex Mono','IBM Plex Sans','IBM Plex Sans Condensed','IBM Plex Serif',
    'Inconsolata','Indie Flower','Inter','Inter Tight',
    'JetBrains Mono','Josefin Sans','Josefin Slab','Jost',
    'Kalam','Kanit','Karla','Kaushan Script','Khand',
    'Lato','League Spartan','Lexend','Lexend Deca','Libre Baskerville','Libre Franklin',
    'Lilita One','Literata','Lobster','Lobster Two','Lora','Lusitana',
    'Manrope','Marcellus','Maven Pro','Merriweather','Merriweather Sans',
    'Montserrat','Montserrat Alternates','Mukta','Mulish','Myriad Pro',
    'Nanum Gothic','Nanum Myeongjo','Neuton','News Cycle','Newsreader',
    'Noto Sans','Noto Sans JP','Noto Sans KR','Noto Serif','Noto Serif JP',
    'Nunito','Nunito Sans',
    'Old Standard TT','Onest','Open Sans','Orbitron','Oswald','Outfit','Overpass',
    'Oxygen',
    'PT Sans','PT Sans Narrow','PT Serif','Pacifico','Pathway Extreme',
    'Patrick Hand','Permanent Marker','Philosopher','Play','Playfair Display',
    'Plus Jakarta Sans','Poppins','Prata','Prompt','Proza Libre','Public Sans',
    'Quattrocento','Quattrocento Sans','Questrial','Quicksand',
    'Rajdhani','Raleway','Readex Pro','Red Hat Display','Red Hat Text',
    'Righteous','Roboto','Roboto Condensed','Roboto Flex','Roboto Mono',
    'Roboto Serif','Roboto Slab','Rokkitt','Rubik','Russo One',
    'Sacramento','Saira','Saira Condensed','Sarabun','Satisfy','Sawarabi Gothic',
    'Schibsted Grotesk','Secular One','Shadows Into Light','Signika','Signika Negative',
    'Slabo 27px','Sora','Source Code Pro','Source Sans 3','Source Serif 4',
    'Space Grotesk','Space Mono','Spectral','Stint Ultra Expanded',
    'Teko','Tenor Sans','Titillium Web','Tomorrow',
    'Ubuntu','Ubuntu Condensed','Ubuntu Mono','Unbounded','Urbanist',
    'Varela Round','Vollkorn','Work Sans','Yanone Kaffeesatz','Yantramanav',
    'Zen Kaku Gothic New','Zilla Slab',
]

const FONT_CATEGORIES = {
    'Sans Serif': ['Inter','Roboto','Open Sans','Lato','Montserrat','Poppins','DM Sans','Nunito','Work Sans','Manrope','Plus Jakarta Sans','Outfit','Figtree','Sora','Barlow','Raleway','Source Sans 3','Mulish','Urbanist','Public Sans','Hanken Grotesk','Red Hat Display','Bricolage Grotesque','Schibsted Grotesk','Lexend'],
    'Serif': ['Playfair Display','Merriweather','Lora','EB Garamond','Cormorant Garamond','Libre Baskerville','Crimson Text','DM Serif Display','Fraunces','Newsreader','Literata','Source Serif 4','Noto Serif','Bodoni Moda','Spectral','Frank Ruhl Libre'],
    'Display': ['Bebas Neue','Anton','Alfa Slab One','Archivo Black','Oswald','Teko','Fjalla One','Righteous','Orbitron','Lilita One','Russo One','Cinzel'],
    'Monospace': ['Fira Code','JetBrains Mono','IBM Plex Mono','Source Code Pro','Roboto Mono','DM Mono','Space Mono','Inconsolata','Ubuntu Mono'],
    'Handwriting': ['Caveat','Dancing Script','Pacifico','Satisfy','Great Vibes','Kaushan Script','Sacramento','Patrick Hand','Shadows Into Light'],
}

const SYSTEM_FONTS = ['Georgia','Helvetica','Arial','Times New Roman','Courier New','Verdana','Tahoma','Trebuchet MS','Garamond','Palatino']

const ROLES = [
    { value: 'primary', label: 'Primary / Display' },
    { value: 'secondary', label: 'Secondary / Body' },
    { value: 'accent', label: 'Accent' },
    { value: 'display', label: 'Display Only' },
    { value: 'body', label: 'Body Only' },
    { value: 'other', label: 'Other' },
]

const SOURCE_BADGES = {
    google: { label: 'Google', className: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' },
    system: { label: 'System', className: 'bg-slate-500/15 text-slate-200 border-slate-500/30' },
    custom: { label: 'Licensed', className: 'bg-amber-500/15 text-amber-300 border-amber-500/30' },
    unknown: { label: 'Placeholder', className: 'bg-white/10 text-white/50 border-white/20' },
}

const SOURCE_BADGES_WORKBENCH = {
    google: { label: 'Google', className: 'bg-emerald-50 text-emerald-800 border border-emerald-200' },
    system: { label: 'System', className: 'bg-slate-100 text-slate-700 border border-slate-200' },
    custom: { label: 'Licensed', className: 'bg-amber-50 text-amber-900 border border-amber-200' },
    unknown: { label: 'Placeholder', className: 'bg-slate-100 text-slate-600 border border-slate-200' },
}

function emptyFont() {
    return { name: '', role: 'primary', source: 'unknown', styles: [], heading_use: null, body_use: null, usage_notes: null, purchase_url: null, file_urls: [] }
}

function googleFontCssUrl(name) {
    return `https://fonts.googleapis.com/css2?family=${encodeURIComponent(name)}:wght@300;400;500;600;700&display=swap`
}

function useLoadGoogleFont(fontName) {
    useEffect(() => {
        if (!fontName || !GOOGLE_FONTS.includes(fontName)) return
        const id = `gf-preview-${fontName.replace(/\s+/g, '-').toLowerCase()}`
        if (document.getElementById(id)) return
        const link = document.createElement('link')
        link.id = id
        link.rel = 'stylesheet'
        link.href = googleFontCssUrl(fontName)
        document.head.appendChild(link)
    }, [fontName])
}

// ——— Google Fonts Quick Picker ———
function GoogleFontsPicker({ onSelect, existingFonts = [] }) {
    const [search, setSearch] = useState('')
    const [category, setCategory] = useState('all')
    const [previewFont, setPreviewFont] = useState(null)
    const inputRef = useRef(null)

    useLoadGoogleFont(previewFont)

    const existingNames = existingFonts.map((f) => typeof f === 'string' ? f : f?.name).filter(Boolean).map((n) => n.toLowerCase())

    const filtered = (() => {
        let list = category === 'all'
            ? GOOGLE_FONTS
            : (FONT_CATEGORIES[category] || [])
        if (search.trim()) {
            const q = search.toLowerCase()
            list = list.filter((f) => f.toLowerCase().includes(q))
        }
        return list
    })()

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <div className="relative flex-1">
                    <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-white/30" />
                    <input
                        ref={inputRef}
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search Google Fonts…"
                        className="w-full rounded-lg border border-white/15 bg-white/[0.04] pl-9 pr-3 py-2 text-sm text-white placeholder-white/30 focus:ring-1 focus:ring-white/30 focus:border-white/30"
                    />
                </div>
            </div>
            <div className="flex gap-1.5 flex-wrap">
                {['all', ...Object.keys(FONT_CATEGORIES)].map((cat) => (
                    <button
                        key={cat}
                        type="button"
                        onClick={() => setCategory(cat)}
                        className={`px-2.5 py-1 rounded-md text-[11px] font-medium transition-colors ${
                            category === cat
                                ? 'bg-white/15 text-white border border-white/20'
                                : 'text-white/40 hover:text-white/60 hover:bg-white/[0.06] border border-transparent'
                        }`}
                    >
                        {cat === 'all' ? 'All' : cat}
                    </button>
                ))}
            </div>
            <div className="max-h-56 overflow-y-auto rounded-lg border border-white/10 bg-white/[0.02] scrollbar-cinematic">
                {filtered.length === 0 ? (
                    <p className="text-xs text-white/30 text-center py-6">No fonts match "{search}"</p>
                ) : (
                    <div className="divide-y divide-white/[0.04]">
                        {filtered.map((font) => {
                            const alreadyAdded = existingNames.includes(font.toLowerCase())
                            return (
                                <div
                                    key={font}
                                    className={`flex items-center justify-between px-3 py-2 group transition-colors ${alreadyAdded ? 'opacity-40' : 'hover:bg-white/[0.05]'}`}
                                    onMouseEnter={() => setPreviewFont(font)}
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <span
                                            className="text-sm text-white/90 truncate"
                                            style={{ fontFamily: `'${font}', system-ui, sans-serif` }}
                                        >
                                            {font}
                                        </span>
                                        <span className="text-[10px] text-white/25 hidden sm:inline">
                                            {Object.entries(FONT_CATEGORIES).find(([, fonts]) => fonts.includes(font))?.[0] || ''}
                                        </span>
                                    </div>
                                    {alreadyAdded ? (
                                        <span className="text-[10px] text-white/30">Added</span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => onSelect(font)}
                                            className="opacity-0 group-hover:opacity-100 px-2.5 py-1 rounded-md text-[11px] font-medium bg-emerald-500/20 text-emerald-300 border border-emerald-500/25 hover:bg-emerald-500/30 transition-all"
                                        >
                                            Add
                                        </button>
                                    )}
                                </div>
                            )
                        })}
                    </div>
                )}
            </div>
            <p className="text-[10px] text-white/25">
                {GOOGLE_FONTS.length} fonts available — all free &amp; open-source via Google Fonts
            </p>
        </div>
    )
}

const FONT_FILE_ACCEPT = '.woff2,.woff,.otf,.ttf,font/woff2,font/woff,font/ttf,font/otf,application/font-woff,application/font-woff2,application/x-font-otf,application/x-font-ttf,application/octet-stream'

// ——— Font Edit Modal ———
function FontEditModal({ open, onClose, font, onSave, brandId = null }) {
    const [draft, setDraft] = useState(font || emptyFont())
    const [styleInput, setStyleInput] = useState('')
    const [fileUrlInput, setFileUrlInput] = useState('')
    const [fontSearch, setFontSearch] = useState('')
    const [showFontPicker, setShowFontPicker] = useState(false)
    const [uploadingFile, setUploadingFile] = useState(false)
    const [uploadBatchLabel, setUploadBatchLabel] = useState(null)
    const [uploadError, setUploadError] = useState(null)
    const [dragActive, setDragActive] = useState(false)
    const fontFileInputRef = useRef(null)
    const wasOpenRef = useRef(false)

    useEffect(() => {
        if (open && !wasOpenRef.current) {
            setDraft(font || emptyFont())
            setUploadError(null)
            setStyleInput('')
            setFileUrlInput('')
            setDragActive(false)
        }
        wasOpenRef.current = open
    }, [open, font])

    const update = (key, val) => setDraft((d) => ({ ...d, [key]: val }))
    const addStyle = () => { const t = styleInput.trim(); if (t && !draft.styles.includes(t)) update('styles', [...draft.styles, t]); setStyleInput('') }
    const removeStyle = (s) => update('styles', draft.styles.filter((x) => x !== s))
    const addFileUrl = () => { const t = fileUrlInput.trim(); if (t && !draft.file_urls.includes(t)) update('file_urls', [...draft.file_urls, t]); setFileUrlInput('') }
    const removeFileUrl = (u) => update('file_urls', draft.file_urls.filter((x) => x !== u))

    const appendFileUrl = (url) => {
        const t = url.trim()
        if (!t) return
        setDraft((d) => (d.file_urls.includes(t) ? d : { ...d, file_urls: [...d.file_urls, t] }))
    }

    const isFontLikeFile = (f) => /\.(woff2?|ttf|otf|eot)$/i.test(f.name)

    const uploadOneFontFile = async (file) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        const meta = await extractFontMetadataFromFile(file)
        setDraft((d) => mergeFontMetadataIntoDraft(d, meta))

        const initRes = await fetch('/app/uploads/initiate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                file_name: file.name,
                file_size: file.size,
                mime_type: file.type || 'application/octet-stream',
                brand_id: brandId,
                builder_staged: true,
                builder_context: 'typography_reference',
            }),
        })
        if (!initRes.ok) {
            const err = await initRes.json().catch(() => ({}))
            throw new Error(err.message || err.error || `Could not start upload (${initRes.status})`)
        }
        const initData = await initRes.json()
        const { upload_url, upload_session_id, upload_key } = initData
        if (!upload_url) throw new Error('No upload URL returned')

        const putRes = await fetch(upload_url, {
            method: 'PUT',
            headers: { 'Content-Type': file.type || 'application/octet-stream' },
            body: file,
        })
        if (!putRes.ok) throw new Error(`Upload failed (${putRes.status})`)

        const finalRes = await fetch('/app/assets/upload/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                manifest: [{
                    upload_key: upload_key ?? `temp/uploads/${upload_session_id}/original`,
                    expected_size: file.size,
                    resolved_filename: file.name,
                }],
            }),
        })
        if (!finalRes.ok) throw new Error(`Finalize failed (${finalRes.status})`)
        const finalData = await finalRes.json()
        const result = finalData.results?.[0]
        const assetId = result?.asset_id ?? result?.id
        if (!assetId) throw new Error('No asset created')

        let downloadPath = `/app/assets/${assetId}/download`
        if (typeof route === 'function') {
            try {
                downloadPath = route('assets.download', { asset: assetId })
            } catch {
                /* ziggy optional */
            }
        }
        const absolute = downloadPath.startsWith('http') ? downloadPath : `${window.location.origin}${downloadPath}`
        appendFileUrl(absolute)
    }

    const handleFontFiles = async (fileList) => {
        if (!brandId) return
        const files = Array.from(fileList || []).filter(isFontLikeFile)
        if (!files.length) return
        setUploadingFile(true)
        setUploadError(null)
        setUploadBatchLabel(files.length > 1 ? `1/${files.length}` : null)
        try {
            for (let i = 0; i < files.length; i++) {
                if (files.length > 1) setUploadBatchLabel(`${i + 1}/${files.length}`)
                await uploadOneFontFile(files[i])
            }
        } catch (err) {
            setUploadError(err instanceof Error ? err.message : 'Upload failed')
        } finally {
            setUploadingFile(false)
            setUploadBatchLabel(null)
        }
    }

    const handleFontFileSelected = async (e) => {
        const list = e.target.files
        e.target.value = ''
        if (!list?.length || !brandId) return
        await handleFontFiles(list)
    }

    const onDropZoneDragOver = (e) => {
        e.preventDefault()
        e.stopPropagation()
        if (!brandId) return
        setDragActive(true)
    }
    const onDropZoneDragLeave = (e) => {
        e.preventDefault()
        e.stopPropagation()
        setDragActive(false)
    }
    const onDropZoneDrop = (e) => {
        e.preventDefault()
        e.stopPropagation()
        setDragActive(false)
        if (!brandId) return
        handleFontFiles(e.dataTransfer?.files)
    }

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
                <Dialog.Panel className="mx-auto w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl border border-white/15 bg-[#1a1920] shadow-2xl scrollbar-cinematic">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-white/10">
                        <Dialog.Title className="text-lg font-semibold text-white">
                            {font ? 'Edit Font' : 'Add Custom Font'}
                        </Dialog.Title>
                        <button type="button" onClick={onClose} className="text-white/50 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition-colors">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>

                    <div className="p-5 space-y-5">
                        <div
                            onDragOver={onDropZoneDragOver}
                            onDragLeave={onDropZoneDragLeave}
                            onDrop={onDropZoneDrop}
                            className={`rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors ${
                                !brandId
                                    ? 'border-white/10 bg-white/[0.02] opacity-60'
                                    : dragActive
                                      ? 'border-violet-400/70 bg-violet-500/10'
                                      : 'border-white/20 bg-white/[0.03] hover:border-white/30'
                            }`}
                        >
                            <DocumentArrowUpIcon className="w-8 h-8 mx-auto text-white/35 mb-2" />
                            <p className="text-sm text-white/80 font-medium">Drop font files here</p>
                            <p className="text-[11px] text-white/40 mt-1 mb-3">WOFF2, WOFF, OTF, TTF — multiple files for different weights</p>
                            {brandId ? (
                                <button
                                    type="button"
                                    disabled={uploadingFile}
                                    onClick={() => fontFileInputRef.current?.click()}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/25 bg-white/10 px-3 py-2 text-xs font-medium text-white/90 hover:bg-white/15 disabled:opacity-50"
                                >
                                    <DocumentArrowUpIcon className="w-4 h-4" />
                                    {uploadingFile
                                        ? uploadBatchLabel
                                            ? `Uploading ${uploadBatchLabel}…`
                                            : 'Uploading…'
                                        : 'Choose files'}
                                </button>
                            ) : (
                                <span className="text-[11px] text-amber-400/90">Save the brand first to upload files.</span>
                            )}
                            <p className="text-[10px] text-white/30 mt-3 leading-relaxed">
                                We read the font’s name table when possible and fill family &amp; styles from the file (and filename).
                            </p>
                        </div>

                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Font Family Name</label>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={draft.name}
                                    onChange={(e) => { update('name', e.target.value); setShowFontPicker(true); setFontSearch(e.target.value) }}
                                    onFocus={() => setShowFontPicker(true)}
                                    placeholder="e.g. RBNo3.1, Montserrat, Gotham..."
                                    className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30"
                                />
                                {showFontPicker && draft.name.length > 0 && filtered.length > 0 && (
                                    <div className="absolute z-20 mt-1 w-full rounded-lg border border-white/20 bg-[#1a1920] shadow-xl max-h-40 overflow-y-auto scrollbar-cinematic">
                                        {filtered.slice(0, 10).map((f) => (
                                            <button
                                                key={f}
                                                type="button"
                                                onClick={() => { update('name', f); update('source', GOOGLE_FONTS.includes(f) ? 'google' : 'system'); setShowFontPicker(false) }}
                                                className="w-full text-left px-3 py-2 text-sm text-white/80 hover:bg-white/10 transition-colors"
                                                style={{ fontFamily: `'${f}', system-ui, sans-serif` }}
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
                            <p className="mt-1 text-[11px] text-white/40">Type any font name — Google Fonts will auto-detect, or enter a commercial font as placeholder.</p>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Role</label>
                                <select value={draft.role} onChange={(e) => update('role', e.target.value)} className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 appearance-none">
                                    {ROLES.map((r) => <option key={r.value} value={r.value} className="bg-[#1a1920]">{r.label}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Source</label>
                                <select value={draft.source} onChange={(e) => update('source', e.target.value)} className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30 appearance-none">
                                    {[{ value: 'google', label: 'Google Fonts' }, { value: 'system', label: 'System Font' }, { value: 'custom', label: 'Custom / Licensed' }, { value: 'unknown', label: 'Unknown / Placeholder' }].map((s) => <option key={s.value} value={s.value} className="bg-[#1a1920]">{s.label}</option>)}
                                </select>
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Available Styles / Weights</label>
                            <div className="flex gap-2">
                                <input type="text" value={styleInput} onChange={(e) => setStyleInput(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addStyle() } }} placeholder="e.g. Bold, Light Italic..." className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30" />
                                <button type="button" onClick={addStyle} className="px-3 py-2 rounded-lg border border-white/20 bg-white/5 text-white/60 hover:text-white hover:bg-white/10 text-sm transition-colors">Add</button>
                            </div>
                            {draft.styles.length > 0 && (
                                <div className="flex flex-wrap gap-1.5 mt-2">
                                    {draft.styles.map((s) => (
                                        <span key={s} className="inline-flex items-center gap-1 rounded-md bg-white/10 border border-white/15 px-2 py-0.5 text-xs text-white/70">
                                            {s}
                                            <button type="button" onClick={() => removeStyle(s)} className="text-white/40 hover:text-white/80"><XMarkIcon className="w-3 h-3" /></button>
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Usage Notes</label>
                            <textarea value={draft.usage_notes || ''} onChange={(e) => update('usage_notes', e.target.value || null)} rows={2} placeholder="e.g. Bold/Extra Bold for headlines, Light for body copy" className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30 resize-none" />
                        </div>

                        {(draft.source === 'custom' || draft.source === 'unknown') && (
                            <div>
                                <label className="block text-xs text-white/60 mb-1.5">Purchase / License URL</label>
                                <input type="url" value={draft.purchase_url || ''} onChange={(e) => update('purchase_url', e.target.value || null)} placeholder="https://fonts.adobe.com/..." className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2.5 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30" />
                            </div>
                        )}

                        <div>
                            <label className="block text-xs text-white/60 mb-1.5">Font Files <span className="text-white/30">(WOFF2, OTF, TTF)</span></label>
                            <p className="mb-2 text-[11px] text-white/35 leading-relaxed">
                                One file per weight is normal for licensed families. <strong className="text-white/50">Upload</strong> each file from your computer, or paste a public HTTPS URL, then <span className="text-white/50">Add</span> — you can attach many files to this font. Use clear filenames (e.g. RBNo3.1-Book.otf) so they match the weights listed above.
                            </p>
                            <input
                                ref={fontFileInputRef}
                                type="file"
                                accept={FONT_FILE_ACCEPT}
                                multiple
                                className="hidden"
                                onChange={handleFontFileSelected}
                            />
                            <div className="flex flex-wrap gap-2">
                                {brandId ? (
                                    <button
                                        type="button"
                                        disabled={uploadingFile}
                                        onClick={() => fontFileInputRef.current?.click()}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-white/25 bg-white/10 px-3 py-2 text-xs font-medium text-white/90 hover:bg-white/15 disabled:opacity-50"
                                    >
                                        <DocumentArrowUpIcon className="w-4 h-4" />
                                        {uploadingFile
                                            ? uploadBatchLabel
                                                ? `Uploading ${uploadBatchLabel}…`
                                                : 'Uploading…'
                                            : 'Upload file(s)'}
                                    </button>
                                ) : (
                                    <span className="text-[11px] text-amber-400/90">Upload requires a saved brand context.</span>
                                )}
                            </div>
                            {uploadError && <p className="text-[11px] text-red-400/90">{uploadError}</p>}
                            <div className="flex gap-2 mt-2">
                                <input type="url" value={fileUrlInput} onChange={(e) => setFileUrlInput(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addFileUrl() } }} placeholder="Or paste https://.../RBNo3.1-Book.otf" className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:outline-none focus:ring-2 focus:ring-white/30" />
                                <button type="button" title="Add file URL" onClick={addFileUrl} className="px-3 py-2 rounded-lg border border-white/20 bg-white/5 text-white/60 hover:text-white hover:bg-white/10 text-sm transition-colors">Add URL</button>
                            </div>
                            {draft.file_urls.length > 0 && (
                                <div className="mt-2 space-y-1">
                                    {draft.file_urls.map((u) => (
                                        <div key={u} className="flex items-center gap-2 text-xs text-white/50 bg-white/5 rounded-md px-2 py-1.5 border border-white/10">
                                            <DocumentArrowUpIcon className="w-3.5 h-3.5 flex-shrink-0" />
                                            <span className="truncate flex-1">{u.split('/').pop()}</span>
                                            <button type="button" onClick={() => removeFileUrl(u)} className="text-white/30 hover:text-white/70"><XMarkIcon className="w-3.5 h-3.5" /></button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 px-5 py-4 border-t border-white/10">
                        <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm text-white/60 hover:text-white hover:bg-white/10 transition-colors">Cancel</button>
                        <button type="button" onClick={handleSave} disabled={!draft.name.trim()} className="px-5 py-2 rounded-lg text-sm font-medium bg-violet-600 text-white hover:bg-violet-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            {font ? 'Save Changes' : 'Add Font'}
                        </button>
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    )
}

// ——— Role Picker Popover ———
function RolePickerInline({ value, onChange, workbenchSurface = false }) {
    const [open, setOpen] = useState(false)
    const label = ROLES.find((r) => r.value === value)?.label || value
    const btn = workbenchSurface
        ? 'text-[11px] text-slate-500 hover:text-violet-800 underline decoration-dotted underline-offset-2'
        : 'text-[11px] text-white/40 hover:text-white/60 underline decoration-dotted underline-offset-2'
    const menu = workbenchSurface
        ? 'absolute z-20 mt-1 left-0 rounded-lg border border-slate-200 bg-white shadow-xl py-1 min-w-[160px]'
        : 'absolute z-20 mt-1 left-0 rounded-lg border border-white/20 bg-[#1a1920] shadow-xl py-1 min-w-[160px]'
    const item = (active) =>
        workbenchSurface
            ? `w-full text-left px-3 py-1.5 text-xs transition-colors ${active ? 'text-violet-900 bg-violet-50' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'}`
            : `w-full text-left px-3 py-1.5 text-xs transition-colors ${active ? 'text-white bg-white/10' : 'text-white/60 hover:bg-white/5 hover:text-white/80'}`

    return (
        <div className="relative">
            <button type="button" onClick={() => setOpen(!open)} className={btn}>
                {label}
            </button>
            {open && (
                <div className={menu}>
                    {ROLES.map((r) => (
                        <button key={r.value} type="button" onClick={() => { onChange(r.value); setOpen(false) }} className={item(value === r.value)}>
                            {r.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    )
}

// ——— Main FontManager ———
export default function FontManager({
    fonts = [],
    onChange,
    suggestions = [],
    onApplySuggestion,
    brandId = null,
    /** Light, workbench-aligned surface for Brand Settings (vs dark builder preview) */
    workbenchSurface = false,
}) {
    const [editingIdx, setEditingIdx] = useState(null)
    const [isAdding, setIsAdding] = useState(false)
    const [showGooglePicker, setShowGooglePicker] = useState(false)

    const fontsList = Array.isArray(fonts) ? fonts : []

    useEffect(() => {
        fontsList.forEach((f) => {
            const name = typeof f === 'string' ? f : f?.name
            if (!name || !GOOGLE_FONTS.includes(name)) return
            const id = `gf-live-${name.replace(/\s+/g, '-').toLowerCase()}`
            if (document.getElementById(id)) return
            const link = document.createElement('link')
            link.id = id
            link.rel = 'stylesheet'
            link.href = googleFontCssUrl(name)
            document.head.appendChild(link)
        })
    }, [fontsList])

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

    const handleGoogleFontSelect = useCallback((fontName) => {
        const nextRole = fontsList.length === 0 ? 'primary' : fontsList.length === 1 ? 'secondary' : 'accent'
        const newFont = {
            name: fontName,
            role: nextRole,
            source: 'google',
            styles: ['Regular', 'Medium', 'Semi Bold', 'Bold'],
            heading_use: null,
            body_use: null,
            usage_notes: null,
            purchase_url: null,
            file_urls: [],
        }
        onChange([...fontsList, newFont])
    }, [fontsList, onChange])

    const handleApplySuggestion = useCallback(() => {
        if (suggestions.length > 0 && onApplySuggestion) onApplySuggestion(suggestions)
    }, [suggestions, onApplySuggestion])

    const handleRoleChange = useCallback((idx, newRole) => {
        const updated = [...fontsList]
        if (typeof updated[idx] === 'string') {
            updated[idx] = { ...emptyFont(), name: updated[idx], role: newRole }
        } else {
            updated[idx] = { ...updated[idx], role: newRole }
        }
        onChange(updated)
    }, [fontsList, onChange])

    const roleLabel = (role) => ROLES.find((r) => r.value === role)?.label || role
    const badgeMap = workbenchSurface ? SOURCE_BADGES_WORKBENCH : SOURCE_BADGES
    const sourceBadge = (source) => badgeMap[source] || badgeMap.unknown
    const rowShell = workbenchSurface
        ? 'group rounded-xl border border-slate-200 bg-white shadow-sm hover:border-slate-300'
        : 'group rounded-xl border border-white/15 bg-white/[0.03] hover:bg-white/[0.06]'
    const nameCls = workbenchSurface ? 'text-sm font-semibold text-slate-900 truncate' : 'text-sm font-semibold text-white truncate'
    const styleChip = workbenchSurface
        ? 'text-[10px] text-slate-600 bg-slate-100 rounded px-1.5 py-0.5 border border-slate-200'
        : 'text-[10px] text-white/40 bg-white/5 rounded px-1.5 py-0.5 border border-white/10'
    const noteCls = workbenchSurface ? 'text-xs text-slate-500 mt-1.5 leading-relaxed' : 'text-xs text-white/35 mt-1.5 leading-relaxed'
    const licCls = workbenchSurface
        ? 'inline-flex items-center gap-1 text-[11px] text-violet-700 hover:text-violet-900 mt-1'
        : 'inline-flex items-center gap-1 text-[11px] text-violet-400/90 hover:text-violet-300 mt-1'
    const iconBtn = workbenchSurface
        ? 'p-1.5 rounded-lg text-slate-400 hover:text-slate-900 hover:bg-slate-100'
        : 'p-1.5 rounded-lg text-white/40 hover:text-white hover:bg-white/10'
    const delBtn = workbenchSurface
        ? 'p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50'
        : 'p-1.5 rounded-lg text-white/40 hover:text-red-400 hover:bg-red-500/10'

    return (
        <div>
            {/* Existing fonts */}
            {fontsList.length > 0 && (
                <div className="space-y-2.5 mb-4">
                    {fontsList.map((font, idx) => {
                        const badge = sourceBadge(typeof font === 'string' ? 'unknown' : font.source)
                        const fontName = typeof font === 'string' ? font : (font?.name || 'Unnamed')
                        const fontObj = typeof font === 'string' ? { ...emptyFont(), name: font, role: idx === 0 ? 'primary' : 'secondary' } : font
                        return (
                            <div key={idx} className={`${rowShell} transition-colors`}>
                                <div className="flex items-start gap-3 p-3.5">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 mb-0.5">
                                            <span className={nameCls} style={{ fontFamily: `'${fontName}', system-ui, sans-serif` }}>{fontName}</span>
                                            <span className={`inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium ${badge.className}`}>{badge.label}</span>
                                        </div>
                                        <RolePickerInline workbenchSurface={workbenchSurface} value={fontObj.role} onChange={(newRole) => handleRoleChange(idx, newRole)} />
                                        {fontObj.styles?.length > 0 && (
                                            <div className="flex flex-wrap gap-1 mt-1.5">
                                                {fontObj.styles.map((s) => <span key={s} className={styleChip}>{s}</span>)}
                                            </div>
                                        )}
                                        {fontObj.usage_notes && <p className={noteCls}>{fontObj.usage_notes}</p>}
                                        {fontObj.purchase_url && (
                                            <a href={fontObj.purchase_url} target="_blank" rel="noopener noreferrer" className={licCls}>
                                                <ArrowTopRightOnSquareIcon className="w-3 h-3" />License
                                            </a>
                                        )}
                                    </div>
                                    <div className={`flex gap-1 transition-opacity ${workbenchSurface ? 'opacity-100 sm:opacity-0 sm:group-hover:opacity-100' : 'opacity-0 group-hover:opacity-100'}`}>
                                        <button type="button" onClick={() => setEditingIdx(idx)} className={iconBtn}><PencilSquareIcon className="w-4 h-4" /></button>
                                        <button type="button" onClick={() => handleDelete(idx)} className={delBtn}><TrashIcon className="w-4 h-4" /></button>
                                    </div>
                                </div>
                            </div>
                        )
                    })}
                </div>
            )}

            {/* Suggestion banner */}
            {suggestions.length > 0 && fontsList.length === 0 && (
                <div
                    className={
                        workbenchSurface
                            ? 'mb-4 rounded-xl border border-violet-200 bg-violet-50/80 p-4'
                            : 'mb-4 rounded-xl border border-violet-500/30 bg-violet-500/10 p-4'
                    }
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className={workbenchSurface ? 'text-sm font-medium text-violet-900' : 'text-sm font-medium text-violet-200'}>
                                {suggestions.length} font{suggestions.length !== 1 ? 's' : ''} detected from Brand Guidelines
                            </p>
                            <div className="mt-2 space-y-1">
                                {suggestions.map((f, i) => (
                                    <p key={i} className={workbenchSurface ? 'text-xs text-slate-600' : 'text-xs text-white/50'}>
                                        <span className={workbenchSurface ? 'font-medium text-slate-800' : 'text-white/70 font-medium'}>{f.name}</span> — {f.role}
                                        {f.styles?.length > 0 && ` (${f.styles.slice(0, 3).join(', ')}${f.styles.length > 3 ? '...' : ''})`}
                                    </p>
                                ))}
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={handleApplySuggestion}
                            className={
                                workbenchSurface
                                    ? 'flex-shrink-0 px-3 py-1.5 rounded-lg border border-violet-300 bg-white text-violet-800 hover:bg-violet-50 text-xs font-medium'
                                    : 'flex-shrink-0 px-3 py-1.5 rounded-lg bg-violet-500/30 border border-violet-500/40 text-violet-200 hover:bg-violet-500/40 text-xs font-medium'
                            }
                        >
                            Apply
                        </button>
                    </div>
                </div>
            )}

            {/* Google Fonts picker toggle */}
            <div className={`border-t pt-4 mt-2 ${workbenchSurface ? 'border-slate-200' : 'border-white/10'}`}>
                <button
                    type="button"
                    onClick={() => setShowGooglePicker(!showGooglePicker)}
                    className={
                        workbenchSurface
                            ? `w-full flex items-center justify-between rounded-xl border px-4 py-3 text-sm transition-all ${
                                  showGooglePicker
                                      ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                                      : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900'
                              }`
                            : `w-full flex items-center justify-between rounded-xl border px-4 py-3 text-sm transition-all ${
                                  showGooglePicker
                                      ? 'border-emerald-500/30 bg-emerald-500/[0.06] text-emerald-300'
                                      : 'border-white/15 bg-white/[0.02] text-white/60 hover:bg-white/[0.05] hover:text-white/80'
                              }`
                    }
                >
                    <span className="flex items-center gap-2">
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" fill="currentColor" fillOpacity="0.15"/>
                            <text x="6" y="17" fontSize="12" fontWeight="bold" fill="currentColor" fontFamily="serif">G</text>
                        </svg>
                        Browse Google Fonts
                    </span>
                    <svg className={`w-4 h-4 transition-transform ${showGooglePicker ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </button>
                {showGooglePicker && (
                    <div className="mt-3">
                        <GoogleFontsPicker onSelect={handleGoogleFontSelect} existingFonts={fontsList} />
                    </div>
                )}
            </div>

            {/* Add custom font */}
            <button
                type="button"
                onClick={() => setIsAdding(true)}
                className={
                    workbenchSurface
                        ? 'mt-3 w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 hover:border-violet-300 py-3 text-sm text-slate-500 hover:text-violet-800 hover:bg-slate-50/80 transition-all'
                        : 'mt-3 w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-white/15 hover:border-white/30 py-3 text-sm text-white/40 hover:text-white/60 hover:bg-white/[0.02] transition-all'
                }
            >
                <PlusIcon className="w-4 h-4" />
                Add custom font
            </button>

            {fontsList.length === 0 && suggestions.length === 0 && !showGooglePicker && (
                <p className={workbenchSurface ? 'mt-2 text-[11px] text-slate-500 text-center' : 'mt-2 text-[11px] text-white/30 text-center'}>
                    Pick from Google Fonts, add custom/licensed fonts, or paste a font CSS URL below.
                </p>
            )}

            {editingIdx !== null && (
                <FontEditModal
                    open={true}
                    onClose={() => setEditingIdx(null)}
                    font={typeof fontsList[editingIdx] === 'string' ? { ...emptyFont(), name: fontsList[editingIdx] } : fontsList[editingIdx]}
                    onSave={handleSave}
                    brandId={brandId}
                />
            )}

            {isAdding && (
                <FontEditModal open={true} onClose={() => setIsAdding(false)} font={null} onSave={handleSave} brandId={brandId} />
            )}
        </div>
    )
}

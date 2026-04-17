import { useState, useRef, useCallback, useEffect, useMemo } from 'react'
import { motion, AnimatePresence } from 'framer-motion'
import {
    PhotoIcon,
    CheckCircleIcon,
    GlobeAltIcon,
    ArrowUpTrayIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

function DarkColorPicker({ label, value, onChange, required = false }) {
    const inputRef = useRef(null)
    const [hex, setHex] = useState(value || '')

    useEffect(() => { setHex(value || '') }, [value])

    const normalize = (raw) => {
        const s = raw.startsWith('#') ? raw : `#${raw}`
        return /^#[0-9a-fA-F]{6}$/i.test(s) ? s : null
    }

    const commitHex = (raw) => {
        const valid = normalize(raw)
        if (valid) onChange(valid)
    }

    const previewColor = (() => {
        const s = hex.startsWith('#') ? hex : `#${hex}`
        if (/^#[0-9a-fA-F]{6}$/i.test(s)) return s
        if (/^#[0-9a-fA-F]{3}$/i.test(s)) {
            const [, r, g, b] = s
            return `#${r}${r}${g}${g}${b}${b}`
        }
        return value || '#333'
    })()

    return (
        <div className="flex items-center justify-between gap-3">
            <div className="flex items-center gap-2 min-w-0">
                <span className="text-sm text-white/60 font-medium">{label}</span>
                {required && <span className="text-[10px] text-white/25 uppercase tracking-wider">Required</span>}
            </div>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    className="w-8 h-8 rounded-lg border border-white/10 cursor-pointer relative overflow-hidden transition-all hover:border-white/20"
                    style={{ backgroundColor: previewColor }}
                    onClick={() => inputRef.current?.click()}
                >
                    <input
                        ref={inputRef}
                        type="color"
                        value={previewColor}
                        onChange={e => {
                            onChange(e.target.value)
                            setHex(e.target.value)
                        }}
                        className="absolute inset-0 opacity-0 cursor-pointer"
                    />
                </button>
                <input
                    type="text"
                    value={hex}
                    onChange={e => {
                        setHex(e.target.value)
                        commitHex(e.target.value)
                    }}
                    onBlur={() => commitHex(hex)}
                    placeholder="#000000"
                    className="w-24 px-2.5 py-1.5 bg-white/[0.04] border border-white/[0.08] rounded-lg text-sm text-white/70 font-mono placeholder-white/20 focus:outline-none focus:border-white/20 transition-colors"
                />
            </div>
        </div>
    )
}

function BrandPreviewTile({ name, logoPreview, primaryColor, markType, monogramBg }) {
    const initial = name ? name.charAt(0).toUpperCase() : 'B'
    const showMonogram = markType === 'monogram' || !logoPreview

    return (
        <div
            className="relative w-full max-w-xs mx-auto rounded-2xl overflow-hidden border border-white/[0.06]"
            style={{
                background: `linear-gradient(135deg, ${primaryColor || '#6366f1'}15, rgba(12,12,14,0.8))`,
                boxShadow: `0 0 48px ${primaryColor || '#6366f1'}10`,
            }}
        >
            <div className="p-6 flex flex-col items-center gap-4">
                <div
                    className="h-16 w-16 rounded-xl flex items-center justify-center overflow-hidden"
                    style={{
                        backgroundColor: showMonogram
                            ? (monogramBg || primaryColor || '#6366f1')
                            : (primaryColor ? `${primaryColor}20` : 'rgba(255,255,255,0.06)'),
                    }}
                >
                    {!showMonogram && logoPreview ? (
                        <img src={logoPreview} alt="" className="h-10 w-10 object-contain" />
                    ) : (
                        <span
                            className="text-2xl font-bold"
                            style={{ color: showMonogram ? '#fff' : (primaryColor || '#6366f1') }}
                        >
                            {initial}
                        </span>
                    )}
                </div>
                <p className="text-sm font-semibold text-white/80 text-center truncate max-w-full">
                    {name || 'Your Brand'}
                </p>
                {primaryColor && (
                    <div className="flex items-center gap-2">
                        <div className="h-6 w-6 rounded-full border border-white/10" style={{ backgroundColor: primaryColor }} />
                    </div>
                )}
                {markType === 'monogram' && (
                    <span className="text-[10px] text-white/25 uppercase tracking-wider">
                        Temporary monogram — replaceable anytime
                    </span>
                )}
            </div>
        </div>
    )
}

function LogoUploadZone({ onFileSelected, displayColor, uploading }) {
    const fileRef = useRef(null)
    const [dragOver, setDragOver] = useState(false)

    const handleDrop = useCallback((e) => {
        e.preventDefault()
        setDragOver(false)
        const file = e.dataTransfer?.files?.[0]
        if (file && file.type.startsWith('image/')) onFileSelected(file)
    }, [onFileSelected])

    return (
        <div
            onDragOver={e => { e.preventDefault(); setDragOver(true) }}
            onDragLeave={() => setDragOver(false)}
            onDrop={handleDrop}
            onClick={() => !uploading && fileRef.current?.click()}
            className="relative flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-5 cursor-pointer transition-all duration-200"
            style={{
                borderColor: dragOver ? displayColor : 'rgba(255,255,255,0.08)',
                backgroundColor: dragOver ? `${displayColor}08` : 'rgba(255,255,255,0.02)',
            }}
        >
            <input
                ref={fileRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={e => {
                    const file = e.target.files?.[0]
                    if (file) onFileSelected(file)
                    e.target.value = ''
                }}
            />
            {uploading ? (
                <div className="flex items-center gap-2">
                    <div className="h-4 w-4 border-2 border-white/30 border-t-white/80 rounded-full animate-spin" />
                    <span className="text-sm text-white/50">Uploading…</span>
                </div>
            ) : (
                <>
                    <ArrowUpTrayIcon className="h-6 w-6 text-white/30" />
                    <p className="text-xs text-white/40 text-center">
                        Drop an image here or <span style={{ color: displayColor }} className="font-medium">browse</span>
                    </p>
                    <p className="text-[10px] text-white/20">PNG, JPG, SVG, WebP · Max 10 MB</p>
                </>
            )}
        </div>
    )
}

function WebsiteFetchPanel({ displayColor, onLogoConfirmed, onUrlUsed }) {
    const [url, setUrl] = useState('')
    const [fetching, setFetching] = useState(false)
    const [candidates, setCandidates] = useState(null)
    const [error, setError] = useState(null)
    const [confirming, setConfirming] = useState(false)

    const csrfToken = useMemo(
        () => document.querySelector('meta[name="csrf-token"]')?.content || '',
        []
    )

    const handleFetch = useCallback(async () => {
        if (!url.trim() || fetching) return
        setFetching(true)
        setError(null)
        setCandidates(null)

        try {
            let fetchUrl = url.trim()
            if (!/^https?:\/\//i.test(fetchUrl)) fetchUrl = 'https://' + fetchUrl

            const res = await fetch('/app/onboarding/fetch-logo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ url: fetchUrl }),
            })

            const data = await res.json()
            if (!res.ok || !data.found) {
                setError(data.message || 'No logo found on that website.')
            } else {
                setCandidates(data.candidates || [])
                onUrlUsed?.(fetchUrl)
            }
        } catch {
            setError('Something went wrong. Check the URL and try again.')
        } finally {
            setFetching(false)
        }
    }, [url, fetching, csrfToken])

    const handleConfirm = useCallback(async (candidate) => {
        setConfirming(true)
        try {
            const payload = candidate.type === 'svg'
                ? { type: 'svg', data: candidate.preview }
                : { type: 'url', data: candidate.url }

            const res = await fetch('/app/onboarding/confirm-fetched-logo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            })

            const data = await res.json()
            if (res.ok && data.asset_id) {
                onLogoConfirmed(data)
            } else {
                setError(data.error || 'Failed to save logo.')
            }
        } catch {
            setError('Failed to save logo. Try uploading directly instead.')
        } finally {
            setConfirming(false)
        }
    }, [csrfToken, onLogoConfirmed])

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <input
                    type="text"
                    value={url}
                    onChange={e => setUrl(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleFetch()}
                    placeholder="https://yourbrand.com"
                    className="flex-1 px-3 py-2 bg-white/[0.04] border border-white/[0.08] rounded-lg text-sm text-white placeholder-white/25 focus:outline-none focus:border-white/20 transition-colors"
                />
                <button
                    type="button"
                    onClick={handleFetch}
                    disabled={!url.trim() || fetching}
                    className="px-4 py-2 rounded-lg text-xs font-semibold text-white transition-all disabled:opacity-30"
                    style={{ backgroundColor: `${displayColor}80` }}
                >
                    {fetching ? 'Searching…' : 'Find logo'}
                </button>
            </div>
            <p className="text-[10px] text-white/25">
                We'll check the website header, structured data, and common logo locations
            </p>

            {error && (
                <p className="text-xs text-amber-300/70">{error}</p>
            )}

            {candidates && candidates.length > 0 && (
                <div className="space-y-2">
                    <p className="text-xs text-white/40">
                        {candidates.length === 1 ? 'Found a logo — click to use it:' : `Found ${candidates.length} options — pick one:`}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {candidates.map((c, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => handleConfirm(c)}
                                disabled={confirming}
                                className="group relative h-16 w-16 rounded-lg border border-white/10 bg-white/[0.03] overflow-hidden hover:border-white/25 transition-all disabled:opacity-50"
                            >
                                <img
                                    src={c.preview}
                                    alt={`Logo option ${i + 1}`}
                                    className="h-full w-full object-contain p-1"
                                    onError={e => { e.target.style.display = 'none' }}
                                />
                                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                    <CheckCircleIcon className="h-5 w-5 text-white" />
                                </div>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {candidates && candidates.length === 0 && !error && (
                <p className="text-xs text-white/30">No logos detected. Try uploading directly instead.</p>
            )}
        </div>
    )
}

const MARK_OPTIONS = [
    { key: 'upload', label: 'Upload', icon: ArrowUpTrayIcon },
    { key: 'website', label: 'From website', icon: GlobeAltIcon },
    { key: 'monogram', label: 'Monogram', icon: null },
]

export default function BrandShellStep({ brand, brandColor = '#6366f1', progress, onSave, onBack, onColorsChange }) {
    const [name, setName] = useState(brand?.name || '')
    const [primaryColor, setPrimaryColor] = useState(brand?.primary_color || '')
    const [secondaryColor, setSecondaryColor] = useState(brand?.secondary_color || '')
    const [accentColor, setAccentColor] = useState(brand?.accent_color || '')
    const [markMode, setMarkMode] = useState(
        brand?.logo_path || brand?.logo_id ? 'upload' :
        progress?.steps?.brand_mark_type === 'monogram' ? 'monogram' : null
    )
    const [saving, setSaving] = useState(false)
    const [uploading, setUploading] = useState(false)
    const [logoPreview, setLogoPreview] = useState(brand?.logo_path || null)
    const [logoAssetId, setLogoAssetId] = useState(brand?.logo_id || null)
    const [logoFetchUrl, setLogoFetchUrl] = useState(null)

    const hasRealLogo = !!logoAssetId
    const displayColor = primaryColor || brandColor
    const hasValidColor = /^#[0-9a-fA-F]{6}$/i.test(primaryColor)

    // Notify parent of color changes so backdrop/progress rail update live
    const handlePrimaryChange = useCallback((val) => {
        setPrimaryColor(val)
        onColorsChange?.({ primary: val })
    }, [onColorsChange])

    const handleSecondaryChange = useCallback((val) => {
        setSecondaryColor(val)
        onColorsChange?.({ secondary: val })
    }, [onColorsChange])

    const handleAccentChange = useCallback((val) => {
        setAccentColor(val)
        onColorsChange?.({ accent: val })
    }, [onColorsChange])

    const markType = markMode === 'monogram' ? 'monogram' : (hasRealLogo ? 'logo' : null)
    const canContinue = name.trim() !== '' && hasValidColor && (markMode === 'monogram' || hasRealLogo)

    const csrfToken = useMemo(
        () => document.querySelector('meta[name="csrf-token"]')?.content || '',
        []
    )

    const handleFileUpload = useCallback(async (file) => {
        setUploading(true)
        try {
            const formData = new FormData()
            formData.append('logo', file)

            const res = await fetch('/app/onboarding/upload-logo', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            })

            const data = await res.json()
            if (res.ok && data.asset_id) {
                setLogoAssetId(data.asset_id)
                setLogoPreview(data.logo_path || URL.createObjectURL(file))
            }
        } catch (e) {
            console.warn('Logo upload failed:', e)
        } finally {
            setUploading(false)
        }
    }, [csrfToken])

    const handleFetchedLogoConfirmed = useCallback((data) => {
        setLogoAssetId(data.asset_id)
        setLogoPreview(data.logo_path || logoPreview)
        setMarkMode('upload')
    }, [logoPreview])

    const handleLogoFetchUrlUsed = useCallback((url) => {
        setLogoFetchUrl(url)
    }, [])

    const clearLogo = useCallback(() => {
        setLogoAssetId(null)
        setLogoPreview(null)
    }, [])

    const handleSave = useCallback(async () => {
        if (saving) return
        setSaving(true)
        try {
            const payload = {
                name: name.trim(),
                primary_color: primaryColor,
                secondary_color: secondaryColor || null,
                accent_color: accentColor || null,
            }
            if (markMode === 'monogram') {
                payload.use_monogram = true
                payload.icon_bg_color = primaryColor || brandColor
            } else if (hasRealLogo) {
                payload.mark_type = 'logo'
                payload.logo_id = logoAssetId
            }
            if (logoFetchUrl) {
                payload.logo_fetch_url = logoFetchUrl
            }
            await onSave(payload)
        } finally {
            setSaving(false)
        }
    }, [name, primaryColor, secondaryColor, accentColor, markMode, saving, onSave, brandColor, hasRealLogo, logoAssetId])

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -12 }}
            transition={{ duration: 0.4 }}
            className="w-full max-w-3xl mx-auto"
        >
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-start">
                <div>
                    <h2 className="text-2xl sm:text-3xl font-semibold tracking-tight text-white/95">
                        Brand essentials
                    </h2>
                    <p className="mt-2 text-sm text-white/40 leading-relaxed">
                        Set the foundation for how your brand appears across the workspace.
                    </p>

                    <div className="mt-8 space-y-6">
                        {/* Brand name */}
                        <div>
                            <label className="block text-sm font-medium text-white/60 mb-2">
                                Brand name <span className="text-white/25 text-[10px] uppercase tracking-wider ml-1">Required</span>
                            </label>
                            <input
                                type="text"
                                value={name}
                                onChange={e => setName(e.target.value)}
                                placeholder="Your brand name"
                                className="w-full px-4 py-3 bg-white/[0.04] border border-white/[0.08] rounded-xl text-white placeholder-white/25 focus:outline-none focus:border-white/20 transition-colors"
                            />
                        </div>

                        {/* Colors */}
                        <div className="space-y-4">
                            <DarkColorPicker label="Primary color" value={primaryColor} onChange={handlePrimaryChange} required />
                            <DarkColorPicker label="Secondary color" value={secondaryColor} onChange={handleSecondaryChange} />
                            <DarkColorPicker label="Accent color" value={accentColor} onChange={handleAccentChange} />
                        </div>

                        {/* Brand mark / Logo */}
                        <div>
                            <label className="block text-sm font-medium text-white/60 mb-3">
                                Brand mark <span className="text-white/25 text-[10px] uppercase tracking-wider ml-1">Required</span>
                            </label>

                            {/* Current logo preview (if set) */}
                            {hasRealLogo && logoPreview && (
                                <div className="mb-3 space-y-2">
                                    <div className="flex items-center gap-3 rounded-xl px-3 py-2.5 bg-emerald-500/[0.06] border border-emerald-500/[0.12]">
                                        <div className="flex gap-1.5 shrink-0">
                                            <div className="h-10 w-10 rounded-lg bg-[#1a1a1e] border border-white/10 overflow-hidden flex items-center justify-center" title="On dark">
                                                <img src={logoPreview} alt="" className="h-8 w-8 object-contain" />
                                            </div>
                                            <div className="h-10 w-10 rounded-lg bg-white border border-white/10 overflow-hidden flex items-center justify-center" title="On light">
                                                <img src={logoPreview} alt="" className="h-8 w-8 object-contain" />
                                            </div>
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-medium text-emerald-300/80">Logo set</p>
                                            <p className="text-[11px] text-white/35 leading-relaxed">
                                                Check both previews — if it's hard to see on light backgrounds you can add a dark version later in Brand Settings.
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={clearLogo}
                                            className="p-1 rounded-md text-white/25 hover:text-white/50 hover:bg-white/5 transition-colors"
                                        >
                                            <XMarkIcon className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {!hasRealLogo && (
                                <>
                                    {/* Mode selector tabs */}
                                    <div className="grid grid-cols-3 gap-1.5 mb-3">
                                        {MARK_OPTIONS.map(opt => {
                                            const isActive = markMode === opt.key
                                            return (
                                                <button
                                                    key={opt.key}
                                                    type="button"
                                                    onClick={() => setMarkMode(opt.key)}
                                                    className="flex items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-xs font-medium transition-all duration-200 border"
                                                    style={{
                                                        backgroundColor: isActive ? `${displayColor}12` : 'rgba(255,255,255,0.02)',
                                                        borderColor: isActive ? `${displayColor}40` : 'rgba(255,255,255,0.06)',
                                                        color: isActive ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.35)',
                                                    }}
                                                >
                                                    {opt.icon && <opt.icon className="h-4 w-4 shrink-0" />}
                                                    {!opt.icon && (
                                                        <span className="text-xs font-bold leading-none shrink-0">
                                                            {name ? name.charAt(0).toUpperCase() : 'A'}
                                                        </span>
                                                    )}
                                                    {opt.label}
                                                </button>
                                            )
                                        })}
                                    </div>

                                    {/* Panel content based on mode */}
                                    <AnimatePresence mode="wait">
                                        {markMode === 'upload' && (
                                            <motion.div
                                                key="upload"
                                                initial={{ opacity: 0, y: 6 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -6 }}
                                                transition={{ duration: 0.2 }}
                                            >
                                                <LogoUploadZone
                                                    onFileSelected={handleFileUpload}
                                                    displayColor={displayColor}
                                                    uploading={uploading}
                                                />
                                            </motion.div>
                                        )}

                                        {markMode === 'website' && (
                                            <motion.div
                                                key="website"
                                                initial={{ opacity: 0, y: 6 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -6 }}
                                                transition={{ duration: 0.2 }}
                                            >
                                                <WebsiteFetchPanel
                                                    displayColor={displayColor}
                                                    onLogoConfirmed={handleFetchedLogoConfirmed}
                                                    onUrlUsed={handleLogoFetchUrlUsed}
                                                />
                                            </motion.div>
                                        )}

                                        {markMode === 'monogram' && (
                                            <motion.div
                                                key="monogram"
                                                initial={{ opacity: 0, y: 6 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -6 }}
                                                transition={{ duration: 0.2 }}
                                                className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-4"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div
                                                        className="h-11 w-11 rounded-xl flex items-center justify-center shrink-0"
                                                        style={{ backgroundColor: displayColor }}
                                                    >
                                                        <span className="text-lg font-bold text-white">
                                                            {name ? name.charAt(0).toUpperCase() : 'A'}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <p className="text-sm text-white/60 font-medium">Temporary monogram</p>
                                                        <p className="text-[11px] text-white/30">
                                                            A styled initial will represent your brand. You can add a logo any time from Brand Settings.
                                                        </p>
                                                    </div>
                                                </div>
                                            </motion.div>
                                        )}
                                    </AnimatePresence>
                                </>
                            )}
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="mt-8 flex items-center gap-3">
                        <button
                            type="button"
                            onClick={onBack}
                            className="px-5 py-2.5 rounded-xl text-sm font-medium text-white/40 hover:text-white/60 transition-colors"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={!canContinue || saving}
                            className="px-6 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-300 hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed"
                            style={{
                                background: `linear-gradient(135deg, ${displayColor}, ${displayColor}dd)`,
                                boxShadow: canContinue ? `0 4px 16px ${displayColor}30` : 'none',
                            }}
                        >
                            {saving ? 'Saving…' : 'Continue'}
                        </button>
                    </div>
                </div>

                {/* Preview */}
                <div className="hidden lg:flex items-start justify-center pt-12">
                    <BrandPreviewTile
                        name={name}
                        logoPreview={logoPreview}
                        primaryColor={primaryColor || brandColor}
                        markType={markMode === 'monogram' ? 'monogram' : (hasRealLogo ? 'logo' : null)}
                        monogramBg={primaryColor || brandColor}
                    />
                </div>
            </div>
        </motion.div>
    )
}

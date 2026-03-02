/**
 * Brand Guidelines Builder — Cinematic full-screen UX
 *
 * WHAT CHANGED (Cursor Prompt #3):
 * - Full-screen immersive layout with dark cinema backdrop (gradient + noise)
 * - Large typography, generous spacing, minimal form chrome
 * - Framer Motion step transitions (AnimatePresence, crossfade + slide)
 * - Premium ProgressRail (top bar)
 * - Sticky footer with Next/Back/Skip; URL step= query param
 * - Autosave on Next/Skip; "Saved" indicator; inline error handling
 * - New Review step after Standards: summary, Publish CTA, scoring toggle, missing-fields checklist
 * - Step-specific UI: ChipInput, FieldCard, BuilderUploadDropzone
 * - Brand-forward styling (primary/secondary/accent from props)
 * - No backend changes; same patch/publish endpoints
 */

import { useState, useCallback, useRef, useEffect } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import FontListbox from '../../Components/BrandGuidelines/FontListbox'
import BuilderAssetSelectorModal from '../../Components/BrandGuidelines/BuilderAssetSelectorModal'
import ResearchInsightsPanel from '../../Components/BrandGuidelines/ResearchInsightsPanel'
import InlineSuggestionBlock from '../../Components/BrandGuidelines/InlineSuggestionBlock'
import axios from 'axios'

// Archetype → recommended traits (mirrors BrandAlignmentEngine)
const ARCHETYPE_RECOMMENDED_TRAITS = {
    Ruler: ['decisive', 'authoritative', 'precise', 'commanding', 'confident'],
    Creator: ['imaginative', 'innovative', 'expressive', 'artistic', 'original'],
    Caregiver: ['warm', 'supportive', 'nurturing', 'compassionate', 'gentle'],
    Jester: ['playful', 'humorous', 'witty', 'fun', 'lighthearted'],
    Everyman: ['friendly', 'relatable', 'down-to-earth', 'approachable', 'honest'],
    Lover: ['passionate', 'sensual', 'romantic', 'intimate', 'devoted'],
    Hero: ['courageous', 'determined', 'inspiring', 'bold', 'strong'],
    Outlaw: ['rebellious', 'edgy', 'disruptive', 'bold', 'unconventional'],
    Magician: ['transformative', 'visionary', 'charismatic', 'mysterious', 'innovative'],
    Innocent: ['pure', 'optimistic', 'simple', 'trustworthy', 'hopeful'],
    Sage: ['wise', 'knowledgeable', 'thoughtful', 'analytical', 'insightful'],
    Explorer: ['adventurous', 'independent', 'pioneering', 'curious', 'free'],
}

const ARCHETYPES = [
    { id: 'Creator', desc: 'Innovation, imagination, self-expression' },
    { id: 'Caregiver', desc: 'Compassion, nurturing, protection' },
    { id: 'Ruler', desc: 'Leadership, control, responsibility' },
    { id: 'Jester', desc: 'Joy, humor, playfulness' },
    { id: 'Everyman', desc: 'Belonging, realism, connection' },
    { id: 'Lover', desc: 'Passion, intimacy, appreciation' },
    { id: 'Hero', desc: 'Courage, mastery, triumph' },
    { id: 'Outlaw', desc: 'Rebellion, liberation, disruption' },
    { id: 'Magician', desc: 'Transformation, vision, catalyst' },
    { id: 'Innocent', desc: 'Purity, optimism, simplicity' },
    { id: 'Sage', desc: 'Wisdom, truth, clarity' },
    { id: 'Explorer', desc: 'Freedom, discovery, authenticity' },
]

// ——— ProgressRail ———
function ProgressRail({ steps, stepKeys, currentStep, accentColor }) {
    const idx = stepKeys.indexOf(currentStep)
    const progress = idx >= 0 ? ((idx + 1) / stepKeys.length) * 100 : 0

    return (
        <div className="h-1 w-full bg-white/10 rounded-full overflow-hidden">
            <motion.div
                className="h-full rounded-full"
                style={{ backgroundColor: accentColor || '#6366f1' }}
                initial={{ width: 0 }}
                animate={{ width: `${progress}%` }}
                transition={{ duration: 0.4, ease: 'easeOut' }}
            />
        </div>
    )
}

// ——— ChipInput ———
function ChipInput({ value = [], onChange, placeholder = 'Type and press Enter', onKeyDown }) {
    const [input, setInput] = useState('')
    const inputRef = useRef(null)

    const add = (v) => {
        const trimmed = (typeof v === 'string' ? v : input).trim()
        if (!trimmed) return
        const next = Array.isArray(value) ? [...value] : []
        if (next.includes(trimmed)) return
        next.push(trimmed)
        onChange(next)
        setInput('')
    }

    const remove = (i) => {
        const next = [...(value || [])]
        next.splice(i, 1)
        onChange(next)
    }

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            add(input)
        } else if (e.key === 'Backspace' && !input && value?.length) {
            remove(value.length - 1)
        }
        onKeyDown?.(e)
    }

    return (
        <div className="flex flex-wrap gap-2 p-3 rounded-xl border border-white/20 bg-white/5 min-h-[52px]">
            {(value || []).map((item, i) => (
                <span
                    key={i}
                    className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-white/15 text-sm text-white"
                >
                    {item}
                    <button
                        type="button"
                        onClick={() => remove(i)}
                        className="hover:bg-white/20 rounded p-0.5 -mr-1"
                        aria-label="Remove"
                    >
                        ×
                    </button>
                </span>
            ))}
            <input
                ref={inputRef}
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={placeholder}
                className="flex-1 min-w-[120px] bg-transparent border-0 text-white placeholder-white/50 focus:ring-0 focus:outline-none text-sm"
            />
        </div>
    )
}

// ——— ColorPaletteChipInput ———
// Like ChipInput but each chip shows a color swatch.
function ColorPaletteChipInput({ value = [], onChange, placeholder = '#hex and press Enter' }) {
    const [input, setInput] = useState('')
    const inputRef = useRef(null)

    const add = (v) => {
        const trimmed = (typeof v === 'string' ? v : input).trim()
        if (!trimmed) return
        const hex = trimmed.startsWith('#') ? trimmed : '#' + trimmed
        const next = Array.isArray(value) ? [...value] : []
        if (next.includes(hex)) return
        next.push(hex)
        onChange(next)
        setInput('')
    }

    const remove = (i) => {
        const next = [...(value || [])]
        next.splice(i, 1)
        onChange(next)
    }

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            add(input)
        } else if (e.key === 'Backspace' && !input && value?.length) {
            remove(value.length - 1)
        }
    }

    return (
        <div className="flex flex-wrap gap-2 p-3 rounded-xl border border-white/20 bg-white/5 min-h-[52px]">
            {(value || []).map((item, i) => {
                const hex = (typeof item === 'object' && item?.hex) || (typeof item === 'string' ? item : '')
                const displayHex = hex.startsWith('#') ? hex : '#' + hex
                const isValidHex = /^#[0-9A-Fa-f]{6}$/.test(displayHex)
                return (
                    <span
                        key={i}
                        className="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white/15 text-sm text-white border border-white/10"
                    >
                        <span
                            className="w-5 h-5 rounded border border-white/20 flex-shrink-0"
                            style={{ backgroundColor: isValidHex ? displayHex : 'transparent' }}
                        />
                                                        {displayHex}
                                                        <button
                                                            type="button"
                                                            onClick={() => remove(i)}
                                                            className="hover:bg-white/20 rounded p-0.5 -mr-1"
                                                            aria-label="Remove"
                                                        >
                                                            ×
                                                        </button>
                                                    </span>
                )
            })}
            <input
                ref={inputRef}
                type="text"
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={placeholder}
                className="flex-1 min-w-[120px] bg-transparent border-0 text-white placeholder-white/50 focus:ring-0 focus:outline-none text-sm"
            />
        </div>
    )
}

// ——— FieldCard ———
function FieldCard({ title, children, className = '' }) {
    return (
        <div className={`rounded-2xl border border-white/20 bg-white/5 p-6 ${className}`}>
            {title && <h3 className="text-sm font-medium text-white/70 mb-3">{title}</h3>}
            {children}
        </div>
    )
}

// ——— PdfGuidelinesUploadCard ———
// Upload PDF → trigger extraction → poll → prefill draft. Additive by default (fill_empty).
function PdfGuidelinesUploadCard({ brandId, accentColor, payload, setPayload, setBrandColors, saving, setErrors }) {
    const [pdfAssetId, setPdfAssetId] = useState(null)
    const [extractionStatus, setExtractionStatus] = useState(null)
    const [extractionPolling, setExtractionPolling] = useState(false)
    const [prefillLoading, setPrefillLoading] = useState(false)
    const [prefilled, setPrefilled] = useState(false)
    const [manualPaste, setManualPaste] = useState('')
    const [showManualPaste, setShowManualPaste] = useState(false)
    const pollRef = useRef(null)
    const pollStartRef = useRef(null)

    const triggerExtraction = useCallback(async (assetId) => {
        try {
            const res = await axios.post(route('assets.pdf-text-extraction.store', { asset: assetId }))
            if (res.status === 202) {
                setExtractionPolling(true)
                pollStartRef.current = Date.now()
            }
        } catch (e) {
            setErrors([e.response?.data?.message || 'Failed to start extraction'])
        }
    }, [setErrors])

    const pollExtraction = useCallback(async () => {
        if (!pdfAssetId || !extractionPolling) return
        if (Date.now() - (pollStartRef.current || 0) > 45000) {
            setExtractionPolling(false)
            setExtractionStatus({ status: 'timeout' })
            return
        }
        try {
            const res = await axios.get(route('assets.pdf-text-extraction.show', { asset: pdfAssetId }))
            const ext = res.data?.extraction
            if (!ext) {
                setExtractionStatus(null)
                return
            }
            if (ext.status === 'complete') {
                setExtractionPolling(false)
                setExtractionStatus(ext)
                return
            }
            if (ext.status === 'failed') {
                setExtractionPolling(false)
                setExtractionStatus({ status: 'failed', error: ext.error_message })
                return
            }
        } catch {
            // keep polling
        }
    }, [pdfAssetId, extractionPolling])

    useEffect(() => {
        if (!extractionPolling) return
        pollRef.current = setInterval(pollExtraction, 2000)
        return () => {
            if (pollRef.current) clearInterval(pollRef.current)
        }
    }, [extractionPolling, pollExtraction])

    const handlePdfUploadComplete = useCallback((assetId) => {
        setPdfAssetId(assetId)
        setExtractionStatus(null)
        triggerExtraction(assetId)
    }, [triggerExtraction])

    const handlePrefill = useCallback(async (mode) => {
        if (!pdfAssetId) return
        setPrefillLoading(true)
        setErrors([])
        try {
            const res = await axios.post(route('brands.brand-dna.builder.prefill-from-guidelines-pdf', { brand: brandId }), {
                asset_id: pdfAssetId,
                mode: mode || 'fill_empty',
            })
            if (res.data?.status === 'applied' && res.data?.applied) {
                const applied = res.data.applied
                setPayload((prev) => {
                    const next = { ...prev }
                    Object.keys(applied || {}).forEach((section) => {
                        if (section === 'brand_colors') return
                        const val = applied[section]
                        next[section] = typeof val === 'object' && val !== null && !Array.isArray(val)
                            ? { ...(next[section] || {}), ...val }
                            : val
                    })
                    return next
                })
                if (applied?.brand_colors) {
                    setBrandColors((c) => ({ ...c, ...applied.brand_colors }))
                }
                setPrefilled(true)
            }
        } catch (e) {
            const d = e.response?.data
            if (d?.status === 'pending') {
                setErrors(['Extraction still running. Please wait.'])
            } else if (d?.status === 'empty') {
                setExtractionStatus({ status: 'empty' })
            } else {
                setErrors([d?.message || 'Prefill failed'])
            }
        } finally {
            setPrefillLoading(false)
        }
    }, [pdfAssetId, brandId, setPayload, setBrandColors, setErrors])

    const handleManualPasteApply = useCallback(() => {
        const text = manualPaste.trim()
        if (!text) return
        setPayload((prev) => ({
            ...prev,
            sources: {
                ...(prev.sources || {}),
                notes: (prev.sources?.notes ? prev.sources.notes + '\n\n' : '') + text,
            },
        }))
        setShowManualPaste(false)
        setManualPaste('')
    }, [manualPaste, setPayload])

    const isReady = extractionStatus?.status === 'complete' && extractionStatus?.extracted_text
    const isEmpty = extractionStatus?.status === 'empty' || (extractionStatus?.status === 'complete' && !extractionStatus?.extracted_text)
    const isTimeout = extractionStatus?.status === 'timeout'

    return (
        <FieldCard title="Import Official Brand Guidelines (PDF)">
            <p className="text-white/60 text-sm mb-4">We&apos;ll extract text and prefill your Brand DNA. Optional.</p>
            {!pdfAssetId ? (
                <BuilderUploadDropzone
                    brandId={brandId}
                    builderContext="guidelines_pdf"
                    onUploadComplete={handlePdfUploadComplete}
                    label="Upload PDF guidelines"
                    accept=".pdf,application/pdf"
                />
            ) : (
                <div className="space-y-4">
                    {extractionPolling && (
                        <p className="text-white/80 text-sm">Extracting text…</p>
                    )}
                    {isTimeout && (
                        <p className="text-amber-200/90 text-sm">Still working — continue and we&apos;ll notify when ready.</p>
                    )}
                    {isEmpty && (
                        <div className="space-y-2">
                            <p className="text-amber-200/90 text-sm">This PDF appears to be scanned or has no selectable text.</p>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => setPdfAssetId(null)}
                                    className="text-sm text-white/70 hover:text-white underline"
                                >
                                    Upload a text-based PDF
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowManualPaste(true)}
                                    className="text-sm text-white/70 hover:text-white underline"
                                >
                                    Paste text manually
                                </button>
                            </div>
                        </div>
                    )}
                    {isReady && !prefilled && (
                        <div className="space-y-2">
                            <p className="text-white/80 text-sm">Text extracted. Prefill only fills empty fields.</p>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => handlePrefill('fill_empty')}
                                    disabled={prefillLoading || saving}
                                    className="px-4 py-2 rounded-xl text-sm font-medium text-white"
                                    style={{ backgroundColor: accentColor || '#6366f1' }}
                                >
                                    {prefillLoading ? 'Prefilling…' : 'Prefill from PDF'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handlePrefill('replace')}
                                    disabled={prefillLoading || saving}
                                    className="px-4 py-2 rounded-xl border border-white/30 text-white/80 text-sm hover:bg-white/10"
                                >
                                    Replace step answers
                                </button>
                            </div>
                        </div>
                    )}
                    {prefilled && (
                        <p className="text-emerald-400 text-sm">Prefilled — review each step.</p>
                    )}
                </div>
            )}
            {showManualPaste && (
                <div className="mt-4 space-y-2">
                    <textarea
                        value={manualPaste}
                        onChange={(e) => setManualPaste(e.target.value)}
                        placeholder="Paste extracted text here…"
                        rows={4}
                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 text-sm"
                    />
                    <button
                        type="button"
                        onClick={handleManualPasteApply}
                        className="px-3 py-1.5 rounded-lg bg-white/15 text-white text-sm"
                    >
                        Add to notes
                    </button>
                </div>
            )}
        </FieldCard>
    )
}

// ——— BuilderUploadDropzone ———
// Uses server-returned upload_key from initiate response; never reconstructs path.
// Supports both click-to-upload and drag-and-drop.
function BuilderUploadDropzone({ brandId, builderContext, onUploadComplete, label, count = 0, accept }) {
    const [uploadingCount, setUploadingCount] = useState(0)
    const [pendingNames, setPendingNames] = useState([])
    const [error, setError] = useState(null)
    const [isDragging, setIsDragging] = useState(false)
    const inputRef = useRef(null)
    const uploading = uploadingCount > 0

    const doUpload = useCallback(async (file) => {
        if (!file) return
        setError(null)
        setUploadingCount((c) => c + 1)
        setPendingNames((prev) => [...prev, file.name])
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        try {
            const initRes = await fetch('/app/uploads/initiate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    file_name: file.name,
                    file_size: file.size,
                    mime_type: file.type || null,
                    brand_id: brandId,
                    builder_staged: true,
                    builder_context: builderContext,
                }),
            })
            if (!initRes.ok) {
                const err = await initRes.json().catch(() => ({}))
                throw new Error(err.message || `Initiate failed: ${initRes.status}`)
            }
            const initData = await initRes.json()
            const { upload_session_id, upload_key, upload_type, upload_url } = initData
            const finalizeUploadKey = upload_key ?? (upload_session_id ? `temp/uploads/${upload_session_id}/original` : null)
            if (!finalizeUploadKey) throw new Error('Initiate response missing upload_key or upload_session_id')
            if (upload_type === 'direct' && upload_url) {
                const putRes = await fetch(upload_url, {
                    method: 'PUT',
                    headers: { 'Content-Type': file.type || 'application/octet-stream' },
                    body: file,
                })
                if (!putRes.ok) throw new Error(`Upload failed: ${putRes.status}`)
            } else {
                throw new Error('Direct upload required; file may be too large')
            }
            const finalRes = await fetch('/app/assets/upload/finalize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    manifest: [{
                        upload_key: finalizeUploadKey,
                        expected_size: file.size,
                        resolved_filename: file.name,
                    }],
                }),
            })
            if (!finalRes.ok) {
                const err = await finalRes.json().catch(() => ({}))
                throw new Error(err.message || `Finalize failed: ${finalRes.status}`)
            }
            const finalData = await finalRes.json()
            const result = finalData.results?.[0]
            const assetId = result?.asset_id ?? result?.id
            if (assetId) onUploadComplete?.(assetId)
        } catch (e) {
            setError(e.message || 'Upload failed')
        } finally {
            setUploadingCount((c) => Math.max(0, c - 1))
            setPendingNames((prev) => {
                const idx = prev.indexOf(file.name)
                if (idx === -1) return prev
                return [...prev.slice(0, idx), ...prev.slice(idx + 1)]
            })
        }
    }, [brandId, builderContext, onUploadComplete])

    const handleChange = (e) => {
        const files = e.target.files
        if (!files?.length) return
        const fileList = Array.from(files)
        e.target.value = ''
        fileList.forEach((f) => doUpload(f))
    }

    const handleDragOver = (e) => {
        e.preventDefault()
        e.stopPropagation()
        if (!uploading) setIsDragging(true)
    }

    const handleDragLeave = (e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(false)
    }

    const handleDrop = (e) => {
        e.preventDefault()
        e.stopPropagation()
        setIsDragging(false)
        if (uploading) return
        const files = e.dataTransfer?.files
        if (!files?.length) return
        Array.from(files).forEach((f) => doUpload(f))
    }

    return (
        <div>
            <div
                role="button"
                tabIndex={0}
                onClick={() => !uploading && inputRef.current?.click()}
                onKeyDown={(e) => e.key === 'Enter' && !uploading && inputRef.current?.click()}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                className={`w-full py-8 px-6 rounded-2xl border-2 border-dashed transition-colors text-white/80 flex flex-col items-center gap-2 cursor-pointer select-none ${
                    uploading
                        ? 'border-white/20 bg-white/5 opacity-50 cursor-not-allowed'
                        : isDragging
                        ? 'border-white/60 bg-white/15'
                        : 'border-white/30 bg-white/5 hover:bg-white/10 hover:border-white/40'
                }`}
            >
                {uploading ? (
                    <div className="flex flex-col items-center gap-1 text-white/90">
                        <span className="animate-pulse">
                            {uploadingCount} file{uploadingCount !== 1 ? 's' : ''} uploading…
                        </span>
                        {pendingNames.length > 0 && (
                            <span className="text-sm text-white/70">
                                {pendingNames.length <= 2 ? pendingNames.join(', ') : `${pendingNames[0]} + ${pendingNames.length - 1} more`}
                            </span>
                        )}
                    </div>
                ) : (
                    <>
                        <svg className="w-10 h-10 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <span>{label}</span>
                        {count > 0 && <span className="text-sm text-white/60">{count} uploaded</span>}
                        {isDragging && <span className="text-sm text-white/70">Drop files here</span>}
                    </>
                )}
            </div>
            <input ref={inputRef} type="file" className="hidden" onChange={handleChange} accept={accept ?? 'image/*,.pdf,.doc,.docx'} multiple />
            {error && <p className="mt-2 text-sm text-red-400">{error}</p>}
        </div>
    )
}

// Canonical format: [{ asset_id, kind }, ...]. Handles legacy int[] for count.
function approvedRefsCount(refs) {
    const arr = Array.isArray(refs) ? refs : []
    return arr.length
}

// ——— ReviewPanel ———
function ReviewPanel({ payload, brand }) {
    const sources = payload.sources || {}
    const identity = payload.identity || {}
    const personality = payload.personality || {}
    const typography = payload.typography || {}
    const visual = payload.visual || {}
    const scoringRules = payload.scoring_rules || {}
    const brandColors = brand?.primary_color ? { primary: brand.primary_color, secondary: brand.secondary_color, accent: brand.accent_color } : null

    const truncate = (s, len = 80) => {
        const t = String(s || '').trim()
        return t.length > len ? t.slice(0, len) + '…' : t
    }

    const items = [
        { label: 'Website & Social', value: sources.website_url ? `Website: ${sources.website_url}` : `Social URLs: ${(sources.social_urls || []).length}` },
        { label: 'Archetype', value: personality.primary_archetype || (personality.candidate_archetypes || []).join(', ') || '—' },
        { label: 'Why', value: truncate(identity.mission) },
        { label: 'What', value: truncate(identity.positioning) },
        { label: 'Brand Look', value: truncate(personality.brand_look) },
        { label: 'Brand Voice', value: truncate(personality.voice_description) },
        { label: 'Tone & Traits', value: ((scoringRules.tone_keywords || []).length + (personality.traits || []).length) > 0 ? `${(scoringRules.tone_keywords || []).length} tone keywords, ${(personality.traits || []).length} traits` : '—' },
        { label: 'Positioning', value: identity.industry || identity.target_audience ? `${identity.industry || '—'} / ${identity.target_audience || '—'}` : '—' },
        { label: 'Beliefs & Values', value: [...(identity.beliefs || []), ...(identity.values || [])].length ? `${(identity.beliefs || []).length} beliefs, ${(identity.values || []).length} values` : '—' },
        { label: 'Typography', value: typography.primary_font ? `${typography.primary_font} / ${typography.secondary_font || '—'}` : '—' },
        { label: 'Color Palette', value: (scoringRules.allowed_color_palette || []).length ? `${(scoringRules.allowed_color_palette || []).length} colors` : (brandColors?.primary ? 'Brand colors set' : '—') },
        { label: 'Visual References', value: approvedRefsCount(visual.approved_references) ? `${approvedRefsCount(visual.approved_references)} references` : '—' },
    ]

    return (
        <div className="space-y-6">
            <h3 className="text-xl font-semibold text-white">Review your brand guidelines</h3>
            <div className="grid gap-4 sm:grid-cols-2">
                {items.map(({ label, value }, i) => (
                    <FieldCard key={i} title={label}>
                        <p className="text-white/90 text-sm">{value || '—'}</p>
                    </FieldCard>
                ))}
            </div>
        </div>
    )
}

// ——— StepShell ———
function StepShell({ title, description, children }) {
    return (
        <div className="space-y-8">
            <div>
                <h2 className="text-2xl sm:text-3xl font-bold text-white">{title}</h2>
                {description && <p className="mt-2 text-white/70">{description}</p>}
            </div>
            {children}
        </div>
    )
}

export default function BrandGuidelinesBuilder({
    brand,
    draft,
    modelPayload,
    steps,
    stepKeys,
    currentStep,
    anchor: initialAnchor = null,
    crawlerRunning = false,
    latestSnapshot = null,
    latestSuggestions = {},
    latestSnapshotLite = null,
    latestCoherence = null,
    latestAlignment = null,
    insightState = { dismissed: [], accepted: [] },
    brandMaterialCount: initialBrandMaterialCount = 0,
    brandMaterials: initialBrandMaterials = [],
    visualReferences: initialVisualReferences = [],
}) {
    const { auth } = usePage().props
    const [payload, setPayload] = useState(() => modelPayload || {})
    const [brandColors, setBrandColors] = useState({
        primary_color: brand.primary_color || null,
        secondary_color: brand.secondary_color || null,
        accent_color: brand.accent_color || null,
    })
    const [errors, setErrors] = useState([])
    const [saving, setSaving] = useState(false)
    const [savedAt, setSavedAt] = useState(null)
    const [enableScoring, setEnableScoring] = useState(true)
    const [missingFields, setMissingFields] = useState([])
    const [brandMaterialCount, setBrandMaterialCount] = useState(initialBrandMaterialCount)
    const [brandMaterials, setBrandMaterials] = useState(initialBrandMaterials ?? [])
    const [visualReferences, setVisualReferences] = useState(initialVisualReferences ?? [])
    const [assetSelectorOpen, setAssetSelectorOpen] = useState(null)
    const [dismissedInlineSuggestions, setDismissedInlineSuggestions] = useState([])

    useEffect(() => {
        setBrandMaterials(initialBrandMaterials ?? [])
        setBrandMaterialCount(initialBrandMaterialCount ?? 0)
        setVisualReferences(initialVisualReferences ?? [])
    }, [initialBrandMaterialCount, initialBrandMaterials, initialVisualReferences])

    useEffect(() => {
        const links = (payload.typography?.external_font_links || []).filter((url) => typeof url === 'string' && url.startsWith('https://'))
        const prefix = 'builder-external-font-'
        document.querySelectorAll(`link[id^="${prefix}"]`).forEach((el) => el.remove())
        links.forEach((url, i) => {
            const link = document.createElement('link')
            link.id = `${prefix}${i}`
            link.rel = 'stylesheet'
            link.href = url
            document.head.appendChild(link)
        })
        return () => document.querySelectorAll(`link[id^="${prefix}"]`).forEach((el) => el.remove())
    }, [payload.typography?.external_font_links])

    const REVIEW_STEP = 'review'
    const [viewingReview, setViewingReview] = useState(false)
    const allStepKeys = [...stepKeys, REVIEW_STEP]
    const effectiveStep = viewingReview ? REVIEW_STEP : currentStep
    const stepIndex = allStepKeys.indexOf(effectiveStep)
    const currentStepConfig = steps.find((s) => s.key === currentStep)
    const isReviewStep = viewingReview
    const isLastDataStep = currentStep === stepKeys[stepKeys.length - 1] && !viewingReview

    const primaryColor = brand.primary_color || '#6366f1'
    const secondaryColor = brand.secondary_color || '#8b5cf6'
    const accentColor = brand.accent_color || '#06b6d4'

    const updatePayload = useCallback((path, key, value) => {
        setPayload((prev) => ({
            ...prev,
            [path]: {
                ...(prev[path] || {}),
                [key]: value,
            },
        }))
    }, [])

    // allowed_paths are section names (identity, personality, visual, etc.), not leaf paths.
    // Patch endpoint expects full section objects; BrandDnaDraftService deep-merges by path.
    const buildPayloadForStep = useCallback((stepKey) => {
        if (stepKey === REVIEW_STEP) return {}
        const config = steps.find((s) => s.key === stepKey)
        if (!config) return {}
        const out = {}
        for (const path of config.allowed_paths) {
            if (path === 'brand_colors') {
                out[path] = brandColors
            } else if (payload[path] !== undefined) {
                out[path] = payload[path]
            }
        }
        return out
    }, [steps, payload, brandColors, REVIEW_STEP])

    const patchAndNavigate = useCallback(async (nextStepKey) => {
        setErrors([])
        setSaving(true)
        try {
            const stepToPatch = isReviewStep ? null : currentStep
            if (stepToPatch) {
                const patchPayload = buildPayloadForStep(stepToPatch)
                await axios.post(route('brands.brand-dna.builder.patch', { brand: brand.id }), {
                    step_key: stepToPatch,
                    payload: patchPayload,
                })
                setSavedAt(Date.now())
            }
            if (nextStepKey === REVIEW_STEP) {
                setViewingReview(true)
            } else {
                setViewingReview(false)
                router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: nextStepKey }), {
                    preserveState: false,
                })
            }
        } catch (e) {
            if (e.response?.data?.errors) {
                setErrors(Object.values(e.response.data.errors).flat())
            } else {
                setErrors([e.response?.data?.message || 'Failed to save'])
            }
        } finally {
            setSaving(false)
        }
    }, [currentStep, isReviewStep, buildPayloadForStep, brand.id, REVIEW_STEP])

    const handleNext = useCallback(() => {
        if (isReviewStep) return
        if (isLastDataStep) {
            patchAndNavigate(REVIEW_STEP)
        } else {
            patchAndNavigate(stepKeys[stepKeys.indexOf(currentStep) + 1])
        }
    }, [isReviewStep, isLastDataStep, currentStep, stepKeys, patchAndNavigate, REVIEW_STEP])

    const handleBack = useCallback(() => {
        if (viewingReview) {
            setViewingReview(false)
            return
        }
        const idx = stepKeys.indexOf(currentStep)
        if (idx <= 0) return
        router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: stepKeys[idx - 1] }))
    }, [viewingReview, currentStep, stepKeys, brand.id])

    const handleSkip = useCallback(() => {
        if (!currentStepConfig?.skippable) return
        const idx = stepKeys.indexOf(currentStep)
        const nextKey = idx < stepKeys.length - 1 ? stepKeys[idx + 1] : REVIEW_STEP
        patchAndNavigate(nextKey)
    }, [currentStepConfig, currentStep, stepKeys, patchAndNavigate, REVIEW_STEP])

    const handlePublish = useCallback(async () => {
        setErrors([])
        setMissingFields([])
        setSaving(true)
        try {
            const res = await axios.post(route('brands.brand-dna.versions.publish', { brand: brand.id, version: draft.id }), {
                enable_scoring: enableScoring,
            })
            if (res.status === 200) {
                router.visit(route('brands.guidelines.index', { brand: brand.id }))
            }
        } catch (e) {
            const data = e.response?.data
            if (data?.missing_fields?.length) {
                setMissingFields(data.missing_fields)
                setErrors([])
            } else {
                setErrors([data?.message || 'Publish failed'])
            }
        } finally {
            setSaving(false)
        }
    }, [brand.id, draft.id, enableScoring])

    const [researchPolling, setResearchPolling] = useState(false)
    const [polledResearch, setPolledResearch] = useState(null)

    const handleWebsiteBlur = useCallback(async () => {
        const url = (payload.sources?.website_url || '').trim()
        if (!url || !url.startsWith('http')) return
        try {
            await axios.post(route('brands.brand-dna.builder.trigger-research', { brand: brand.id }), { url })
            setResearchPolling(true)
        } catch {}
    }, [payload.sources?.website_url, brand.id])

    const [brandMaterialFeedback, setBrandMaterialFeedback] = useState(null)
    useEffect(() => {
        if (!brandMaterialFeedback) return
        const t = setTimeout(() => setBrandMaterialFeedback(null), 4000)
        return () => clearTimeout(t)
    }, [brandMaterialFeedback])

    const handleBrandMaterialUploadComplete = useCallback(async (assetId) => {
        if (!assetId) return
        try {
            const res = await axios.post(route('brands.brand-dna.builder.attach-asset', { brand: brand.id }), {
                asset_id: assetId,
                builder_context: 'brand_material',
            })
            const count = res.data?.count ?? 0
            setBrandMaterialCount(count)
            setBrandMaterials((prev) => [...prev, { id: assetId, title: 'Asset', original_filename: 'Uploaded', thumbnail_url: null, signed_url: null }])
            setBrandMaterialFeedback(`Uploaded successfully. ${count} material${count !== 1 ? 's' : ''} added.`)
        } catch {}
    }, [brand.id])

    const handleAssetAttach = useCallback(async (asset, context) => {
        const assetId = asset?.id ?? asset
        const item = typeof asset === 'object' ? asset : { id: assetId, title: 'Asset', original_filename: 'file', thumbnail_url: null, signed_url: null }
        try {
            const res = await axios.post(route('brands.brand-dna.builder.attach-asset', { brand: brand.id }), {
                asset_id: assetId,
                builder_context: context,
            })
            if (context === 'brand_material') {
                const count = res.data?.count ?? 0
                setBrandMaterialCount(count)
                setBrandMaterials((prev) => [...prev, { id: assetId, title: item.title, original_filename: item.original_filename, thumbnail_url: item.thumbnail_url, signed_url: item.signed_url }])
                setBrandMaterialFeedback(`Added successfully. ${count} material${count !== 1 ? 's' : ''} total.`)
            } else if (context === 'visual_reference') {
                setVisualReferences((prev) => [...prev, { id: assetId, title: item.title, original_filename: item.original_filename, thumbnail_url: item.thumbnail_url, signed_url: item.signed_url }])
                setPayload((prev) => ({
                    ...prev,
                    visual: {
                        ...(prev.visual || {}),
                        approved_references: [...(prev.visual?.approved_references || []), { asset_id: assetId, kind: 'photo_reference' }],
                    },
                }))
            }
        } catch {}
    }, [brand.id])

    const handleDetachVisualRef = useCallback(async (assetId) => {
        try {
            await axios.post(route('brands.brand-dna.builder.detach-asset', { brand: brand.id }), {
                asset_id: assetId,
                builder_context: 'visual_reference',
            })
            setVisualReferences((prev) => prev.filter((a) => a.id !== assetId))
            setPayload((prev) => ({
                ...prev,
                visual: {
                    ...(prev.visual || {}),
                    approved_references: (prev.visual?.approved_references || []).filter((r) => ((typeof r === 'object' && r?.asset_id) ? r.asset_id : r) !== assetId),
                },
            }))
        } catch {}
    }, [brand.id])

    const handleDetachBrandMaterial = useCallback(async (assetId) => {
        try {
            const res = await axios.post(route('brands.brand-dna.builder.detach-asset', { brand: brand.id }), {
                asset_id: assetId,
                builder_context: 'brand_material',
            })
            setBrandMaterialCount(res.data?.count ?? 0)
            setBrandMaterials((prev) => prev.filter((a) => a.id !== assetId))
        } catch {}
    }, [brand.id])

    const [insightStateLocal, setInsightStateLocal] = useState(insightState)
    useEffect(() => { setInsightStateLocal(insightState) }, [insightState])

    // Poll research status when user triggers Analyze & Prefill
    useEffect(() => {
        if (!researchPolling || !brand?.id) return
        const poll = async () => {
            try {
                const res = await axios.get(route('brands.brand-dna.builder.research-insights', { brand: brand.id }))
                const data = res.data
                setPolledResearch(data)
                if (!data?.crawlerRunning && data?.latestSnapshotLite) {
                    setResearchPolling(false)
                }
            } catch {
                setResearchPolling(false)
            }
        }
        poll()
        const id = setInterval(poll, 3000)
        return () => clearInterval(id)
    }, [researchPolling, brand?.id])

    const handleDismissInsight = useCallback(async (key) => {
        try {
            const res = await axios.post(route('brands.brand-dna.builder.insights.dismiss', { brand: brand.id }), { key })
            setInsightStateLocal((prev) => ({ ...prev, dismissed: res.data?.dismissed ?? [...(prev.dismissed || []), key] }))
        } catch {}
    }, [brand.id])

    const handleAcceptInsight = useCallback(async (key) => {
        try {
            const res = await axios.post(route('brands.brand-dna.builder.insights.accept', { brand: brand.id }), { key })
            setInsightStateLocal((prev) => ({ ...prev, accepted: res.data?.accepted ?? [...(prev.accepted || []), key] }))
        } catch {}
    }, [brand.id])

    const handleApplySuggestion = useCallback((finding) => {
        if (!finding?.suggestion?.path || finding.suggestion.value === undefined) return
        const parts = finding.suggestion.path.split('.')
        const path = parts[0]
        const key = parts.slice(1).join('.') || parts[0]
        if (path && key) {
            setPayload((prev) => ({
                ...prev,
                [path]: { ...(prev[path] || {}), [key]: finding.suggestion.value },
            }))
        }
    }, [])

    const effectiveCrawlerRunning = researchPolling || (polledResearch?.crawlerRunning ?? crawlerRunning)
    const effectiveSnapshotLite = polledResearch?.latestSnapshotLite ?? latestSnapshotLite
    const effectiveCoherence = polledResearch?.latestCoherence ?? latestCoherence
    const effectiveAlignment = polledResearch?.latestAlignment ?? latestAlignment
    const effectiveSuggestions = polledResearch?.latestSuggestions ?? latestSuggestions
    const hasResearchData = researchPolling || effectiveCrawlerRunning || effectiveSnapshotLite || effectiveCoherence || effectiveAlignment || Object.keys(effectiveSuggestions || {}).length > 0

    const sources = payload.sources || {}
    const identity = payload.identity || {}
    const personality = payload.personality || {}
    const typography = payload.typography || {}
    const scoringRules = payload.scoring_rules || {}
    const visual = payload.visual || {}

    return (
        <div className="h-screen flex flex-col bg-[#0f0e14] relative overflow-hidden">
            {/* Cinema backdrop */}
            <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    background: `linear-gradient(160deg, #0f0e14 0%, ${primaryColor}15 40%, #0f0e14 100%)`,
                }}
            />
            <div
                className="absolute inset-0 opacity-[0.03] pointer-events-none"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                }}
            />

            <AppHead title="Brand Guidelines Builder" />
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="relative z-10 flex-1 flex flex-col min-h-0">
                <header className="flex-shrink-0 px-4 sm:px-8 pt-6 pb-4">
                    <Link
                        href={route('brands.guidelines.index', { brand: brand.id })}
                        className="text-sm font-medium text-white/60 hover:text-white/90"
                    >
                        ← Back to Guidelines
                    </Link>
                    <div className="mt-4">
                        <ProgressRail steps={steps} stepKeys={allStepKeys} currentStep={effectiveStep} accentColor={accentColor} />
                    </div>
                    <div className="mt-3 flex items-center justify-between">
                        <span className="text-sm text-white/50">
                            {stepIndex + 1} of {allStepKeys.length}
                        </span>
                        {savedAt && (
                            <motion.span
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                className="text-sm text-emerald-400"
                            >
                                Saved
                            </motion.span>
                        )}
                    </div>
                </header>

                <div className="flex-1 flex min-h-0 overflow-y-auto">
                    <main className="flex-1 min-w-0 px-4 sm:px-8 py-8 max-w-4xl mx-auto w-full">
                    {errors.length > 0 && (
                        <motion.div
                            initial={{ opacity: 0, y: -10 }}
                            animate={{ opacity: 1, y: 0 }}
                            className="mb-6 rounded-xl bg-red-500/20 border border-red-500/40 p-4 text-red-200 text-sm"
                        >
                            <ul className="list-disc list-inside">
                                {errors.map((err, i) => (
                                    <li key={i}>{err}</li>
                                ))}
                            </ul>
                        </motion.div>
                    )}

                    {missingFields.length > 0 && (
                        <motion.div
                            initial={{ opacity: 0, y: -10 }}
                            animate={{ opacity: 1, y: 0 }}
                            className="mb-6 rounded-xl bg-amber-500/20 border border-amber-500/40 p-4"
                        >
                            <h4 className="font-medium text-amber-200 mb-2">Complete these before publishing:</h4>
                            <ul className="space-y-1 text-amber-100/90 text-sm">
                                {missingFields.map((msg, i) => {
                                    const stepKey = msg.includes('Background') ? 'background' : msg.includes('Purpose') ? 'purpose_promise' : msg.includes('Positioning') ? 'positioning' : msg.includes('Archetype') ? 'archetype' : msg.includes('Standards') ? 'standards' : null
                                    return (
                                        <li key={i} className="flex items-center gap-2 flex-wrap">
                                            <span>{msg}</span>
                                            {stepKey && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setViewingReview(false)
                                                        router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: stepKey }))
                                                    }}
                                                    className="text-amber-200 underline hover:text-amber-100 text-xs"
                                                >
                                                    Jump to step
                                                </button>
                                            )}
                                        </li>
                                    )
                                })}
                            </ul>
                        </motion.div>
                    )}

                    <AnimatePresence mode="wait">
                        {isReviewStep ? (
                            <motion.div
                                key="review"
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -12 }}
                                transition={{ duration: 0.3 }}
                            >
                                <ReviewPanel payload={payload} brand={brand} />
                                <div className="mt-8 space-y-4">
                                    <label className="flex items-center gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={enableScoring}
                                            onChange={(e) => setEnableScoring(e.target.checked)}
                                            className="rounded border-white/30 bg-white/10 text-indigo-500 focus:ring-indigo-500"
                                        />
                                        <span className="text-white/90">Enable On-Brand Scoring (can change later)</span>
                                    </label>
                                    <button
                                        type="button"
                                        onClick={handlePublish}
                                        disabled={saving}
                                        className="w-full sm:w-auto px-8 py-3 rounded-xl font-semibold text-white transition-colors disabled:opacity-50"
                                        style={{ backgroundColor: primaryColor }}
                                    >
                                        {saving ? 'Publishing…' : 'Publish Brand Guidelines'}
                                    </button>
                                </div>
                            </motion.div>
                        ) : (
                            <motion.div
                                key={currentStep}
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -12 }}
                                transition={{ duration: 0.3 }}
                            >
                                <StepShell title={currentStepConfig?.title} description={currentStepConfig?.description}>
                                    {currentStep === 'background' && (
                                        <div className="space-y-8">
                                            {/* 1. Import Official Brand Guidelines (PDF) */}
                                            <PdfGuidelinesUploadCard
                                                brandId={brand.id}
                                                accentColor={accentColor}
                                                payload={payload}
                                                setPayload={setPayload}
                                                setBrandColors={setBrandColors}
                                                saving={saving}
                                                setErrors={setErrors}
                                            />

                                            {/* 2. Website & Social */}
                                            <FieldCard title="Website & Social">
                                                <div className="space-y-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Website URL</label>
                                                        <div>
                                                            <div className="flex gap-2">
                                                                <input
                                                                    type="url"
                                                                    value={sources.website_url || ''}
                                                                    onChange={(e) => updatePayload('sources', 'website_url', e.target.value)}
                                                                    onBlur={handleWebsiteBlur}
                                                                    placeholder="https://yoursite.com"
                                                                    className="flex-1 rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 focus:border-white/30"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    disabled={effectiveCrawlerRunning}
                                                                    onClick={() => handleWebsiteBlur()}
                                                                    className="px-4 py-3 rounded-xl border border-white/20 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed enabled:hover:bg-white/10 enabled:text-white/90"
                                                                >
                                                                    {effectiveCrawlerRunning ? 'Analyzing…' : 'Analyze & Prefill'}
                                                                </button>
                                                            </div>
                                                            {effectiveCrawlerRunning && (
                                                                <p className="mt-2 text-sm text-indigo-300">
                                                                    Analyzing website… Results will appear in Research Insights when you continue to the next step.
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Social URLs</label>
                                                        <ChipInput
                                                            value={sources.social_urls || []}
                                                            onChange={(v) => updatePayload('sources', 'social_urls', v)}
                                                            placeholder="Paste URL and press Enter"
                                                        />
                                                    </div>
                                                </div>
                                            </FieldCard>

                                            {/* 3. Brand Materials (Examples) — optional, not for scoring */}
                                            <FieldCard title="Brand Materials (Examples)">
                                                <p className="text-white/60 text-sm mb-4">Catalogs, ads, packaging, social screenshots. Not used for scoring.</p>
                                                <div className="flex flex-wrap gap-2 mb-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => setAssetSelectorOpen('brand_material')}
                                                        className="px-4 py-3 rounded-xl border border-white/20 text-white/90 hover:bg-white/10 text-sm"
                                                    >
                                                        Select from Assets
                                                    </button>
                                                </div>
                                                {brandMaterialFeedback && (
                                                    <p className="mb-4 text-sm text-emerald-400">{brandMaterialFeedback}</p>
                                                )}
                                                {brandMaterials?.length > 0 ? (
                                                    <>
                                                        <p className="text-sm text-white/70 mb-2">{brandMaterials.length} material{brandMaterials.length !== 1 ? 's' : ''} added</p>
                                                        <div className="mb-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                            {brandMaterials.map((a) => (
                                                                <div key={a.id} className="rounded-xl border border-white/20 bg-white/5 p-2 flex flex-col">
                                                                    <div className="aspect-square rounded-lg bg-white/10 overflow-hidden mb-2">
                                                                        {(a.thumbnail_url || a.signed_url) ? (
                                                                            <img src={a.thumbnail_url || a.signed_url} alt="" className="w-full h-full object-cover" />
                                                                        ) : (
                                                                            <div className="w-full h-full flex items-center justify-center text-white/40 text-2xl">◇</div>
                                                                        )}
                                                                    </div>
                                                                    <p className="text-xs text-white/80 truncate" title={a.original_filename}>{a.original_filename || a.title}</p>
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleDetachBrandMaterial(a.id)}
                                                                        className="mt-1 text-xs text-red-400 hover:text-red-300"
                                                                    >
                                                                        Remove
                                                                    </button>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </>
                                                ) : (
                                                    !brandMaterialFeedback && (
                                                        <p className="text-sm text-white/50 mb-4">No materials added yet. Select from Assets or upload files below.</p>
                                                    )
                                                )}
                                                <BuilderUploadDropzone
                                                    brandId={brand.id}
                                                    builderContext="brand_material"
                                                    onUploadComplete={handleBrandMaterialUploadComplete}
                                                    label="Or upload brand materials (optional)"
                                                    count={brandMaterialCount}
                                                    accept="image/*,.pdf"
                                                />
                                            </FieldCard>
                                        </div>
                                    )}

                                    {currentStep === 'positioning' && (
                                        <div className="space-y-8">
                                            <p className="text-white/60 text-sm">Industry, audience, beliefs, values, tagline, and competitive position. Define how your brand fits in the market.</p>
                                            <div className="grid sm:grid-cols-2 gap-6">
                                                <div>
                                                    <label className="block text-sm font-medium text-white/80 mb-2">Industry</label>
                                                    <input
                                                        type="text"
                                                        value={identity.industry || ''}
                                                        onChange={(e) => updatePayload('identity', 'industry', e.target.value)}
                                                        placeholder="e.g. SaaS, Healthcare"
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-medium text-white/80 mb-2">Target Audience</label>
                                                    <input
                                                        type="text"
                                                        value={identity.target_audience || ''}
                                                        onChange={(e) => updatePayload('identity', 'target_audience', e.target.value)}
                                                        placeholder="Who are your customers?"
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Market Category</label>
                                                <input
                                                    type="text"
                                                    value={identity.market_category || ''}
                                                    onChange={(e) => updatePayload('identity', 'market_category', e.target.value)}
                                                    placeholder="e.g. Premium consumer electronics"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Competitive Position</label>
                                                <input
                                                    type="text"
                                                    value={identity.competitive_position || ''}
                                                    onChange={(e) => updatePayload('identity', 'competitive_position', e.target.value)}
                                                    placeholder="How you differentiate from competitors"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tagline</label>
                                                <input
                                                    type="text"
                                                    value={identity.tagline || ''}
                                                    onChange={(e) => updatePayload('identity', 'tagline', e.target.value)}
                                                    placeholder="e.g. Just Do It"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Beliefs</label>
                                                <ChipInput
                                                    value={identity.beliefs || []}
                                                    onChange={(v) => updatePayload('identity', 'beliefs', v)}
                                                    placeholder="Add belief"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Values</label>
                                                <ChipInput
                                                    value={identity.values || []}
                                                    onChange={(v) => updatePayload('identity', 'values', v)}
                                                    placeholder="Add value"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'archetype' && (
                                        <div className="space-y-8">
                                            {latestSuggestions?.recommended_archetypes?.length > 0 && !dismissedInlineSuggestions.includes('archetype:recommended') && (
                                                <InlineSuggestionBlock
                                                    title="Recommended archetypes"
                                                    items={latestSuggestions.recommended_archetypes}
                                                    onApply={(archetype) => {
                                                        setPayload((prev) => ({
                                                            ...prev,
                                                            personality: {
                                                                ...(prev.personality || {}),
                                                                primary_archetype: archetype,
                                                                candidate_archetypes: (prev.personality?.candidate_archetypes || []).filter((a) => a !== archetype),
                                                            },
                                                        }))
                                                    }}
                                                    onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'archetype:recommended'])}
                                                />
                                            )}
                                            {/* Two avenues: I know / I don't know */}
                                            <div className="flex gap-4 mb-6">
                                                <button
                                                    type="button"
                                                    onClick={() => setPayload((prev) => ({ ...prev, personality: { ...(prev.personality || {}), archetype_mode: 'know' } }))}
                                                    className={`flex-1 py-3 px-4 rounded-xl border text-sm font-medium transition-colors ${(personality.archetype_mode || 'dont_know') === 'know' ? 'border-indigo-500/60 bg-indigo-500/20 text-indigo-200' : 'border-white/20 bg-white/5 text-white/70 hover:bg-white/10'}`}
                                                >
                                                    I know my archetype
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setPayload((prev) => ({ ...prev, personality: { ...(prev.personality || {}), archetype_mode: 'dont_know' } }))}
                                                    className={`flex-1 py-3 px-4 rounded-xl border text-sm font-medium transition-colors ${(personality.archetype_mode || 'dont_know') === 'dont_know' ? 'border-indigo-500/60 bg-indigo-500/20 text-indigo-200' : 'border-white/20 bg-white/5 text-white/70 hover:bg-white/10'}`}
                                                >
                                                    I don&apos;t know — help me narrow it down
                                                </button>
                                            </div>

                                            {(personality.archetype_mode || 'dont_know') === 'know' ? (
                                                /* Avenue 1: Direct selection — hero-style cards */
                                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                                                    {ARCHETYPES.map((a) => {
                                                        const selected = [personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean)
                                                        const isSelected = selected.includes(a.id)
                                                        const isSelectedFirst = personality.primary_archetype === a.id
                                                        const canSelect = selected.length < 2 && !isSelected
                                                        return (
                                                            <button
                                                                key={a.id}
                                                                type="button"
                                                                onClick={() => {
                                                                    if (isSelected) {
                                                                        const next = selected.filter((x) => x !== a.id)
                                                                        setPayload((prev) => ({
                                                                            ...prev,
                                                                            personality: {
                                                                                ...(prev.personality || {}),
                                                                                primary_archetype: next[0] || null,
                                                                                candidate_archetypes: next.slice(1),
                                                                            },
                                                                        }))
                                                                    } else if (canSelect) {
                                                                        const next = [...selected, a.id]
                                                                        setPayload((prev) => ({
                                                                            ...prev,
                                                                            personality: {
                                                                                ...(prev.personality || {}),
                                                                                primary_archetype: next[0],
                                                                                candidate_archetypes: next.slice(1),
                                                                            },
                                                                        }))
                                                                    }
                                                                }}
                                                                disabled={!canSelect && !isSelected}
                                                                className={`rounded-2xl border p-6 text-left transition-all min-h-[180px] flex flex-col items-center justify-center ${
                                                                    isSelected ? 'border-indigo-500/60 bg-indigo-500/15 ring-2 ring-indigo-400/40' : 'border-white/20 bg-white/5 hover:bg-white/10 hover:border-white/30'
                                                                }`}
                                                            >
                                                                <div className="w-20 h-20 rounded-2xl bg-white/10 flex items-center justify-center mb-4">
                                                                    <span className="text-white/50 text-3xl">◇</span>
                                                                </div>
                                                                <h4 className="font-semibold text-white text-center mb-1">{a.id}</h4>
                                                                <p className="text-xs text-white/60 text-center">{a.desc}</p>
                                                                {isSelected && <span className="mt-3 text-xs text-indigo-300">{isSelectedFirst ? 'Primary' : 'Secondary'}</span>}
                                                            </button>
                                                        )
                                                    })}
                                                </div>
                                            ) : (
                                                /* Avenue 2: Apply / Doesn't apply — two groupings + hero cards */
                                                <div className="space-y-6">
                                                    <p className="text-white/70 text-sm">Click each archetype to add it to &quot;Applies to us&quot; (up to 2) or &quot;Doesn&apos;t apply&quot;.</p>
                                                    <div className="grid md:grid-cols-2 gap-6">
                                                        <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4">
                                                            <h4 className="text-sm font-semibold text-emerald-300 mb-3">Applies to us</h4>
                                                            <div className="space-y-2 min-h-[100px]">
                                                                {[personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean).map((id) => {
                                                                    const a = ARCHETYPES.find((x) => x.id === id)
                                                                    if (!a) return null
                                                                    return (
                                                                        <div key={a.id} className="flex items-center justify-between rounded-lg bg-white/10 px-3 py-2">
                                                                            <span className="text-white font-medium">{a.id}</span>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    const selected = [personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean)
                                                                                    const next = selected.filter((x) => x !== a.id)
                                                                                    setPayload((prev) => ({
                                                                                        ...prev,
                                                                                        personality: {
                                                                                            ...(prev.personality || {}),
                                                                                            primary_archetype: next[0] || null,
                                                                                            candidate_archetypes: next.slice(1),
                                                                                        },
                                                                                    }))
                                                                                }}
                                                                                className="text-xs text-white/60 hover:text-white"
                                                                            >
                                                                                Remove
                                                                            </button>
                                                                        </div>
                                                                    )
                                                                })}
                                                            </div>
                                                        </div>
                                                        <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                                            <h4 className="text-sm font-semibold text-white/70 mb-3">Doesn&apos;t apply</h4>
                                                            <div className="flex flex-wrap gap-2 min-h-[100px]">
                                                                {(personality.rejected_archetypes || []).map((id) => {
                                                                    const a = ARCHETYPES.find((x) => x.id === id)
                                                                    if (!a) return null
                                                                    return (
                                                                        <button
                                                                            key={a.id}
                                                                            type="button"
                                                                            onClick={() => updatePayload('personality', 'rejected_archetypes', (personality.rejected_archetypes || []).filter((x) => x !== a.id))}
                                                                            className="px-3 py-2 rounded-lg bg-red-500/20 text-red-300 text-sm border border-red-400/30 hover:bg-red-500/30"
                                                                        >
                                                                            {a.id} ✕
                                                                        </button>
                                                                    )
                                                                })}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-white/80 mb-3">Choose</h4>
                                                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                                                            {ARCHETYPES.filter((x) => {
                                                                const selected = [personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean)
                                                                const rejected = personality.rejected_archetypes || []
                                                                return !selected.includes(x.id) && !rejected.includes(x.id)
                                                            }).map((a) => (
                                                                <div key={a.id} className="rounded-2xl border border-white/20 bg-white/5 p-5 text-center">
                                                                    <div className="w-16 h-16 mx-auto mb-3 rounded-xl bg-white/10 flex items-center justify-center">
                                                                        <span className="text-white/50 text-2xl">◇</span>
                                                                    </div>
                                                                    <h4 className="font-semibold text-white mb-1">{a.id}</h4>
                                                                    <p className="text-xs text-white/60 mb-3">{a.desc}</p>
                                                                    <div className="flex gap-2 justify-center">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => {
                                                                                const selected = [personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean)
                                                                                if (selected.length >= 2) return
                                                                                setPayload((prev) => ({
                                                                                    ...prev,
                                                                                    personality: {
                                                                                        ...(prev.personality || {}),
                                                                                        primary_archetype: selected[0] || a.id,
                                                                                        candidate_archetypes: selected[0] ? [...selected.slice(1), a.id].slice(0, 1) : [],
                                                                                    },
                                                                                }))
                                                                            }}
                                                                            disabled={[personality.primary_archetype, ...(personality.candidate_archetypes || [])].filter(Boolean).length >= 2}
                                                                            className="px-3 py-1.5 rounded-lg bg-emerald-500/30 text-emerald-200 text-xs font-medium hover:bg-emerald-500/50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                                        >
                                                                            Applies
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => updatePayload('personality', 'rejected_archetypes', [...(personality.rejected_archetypes || []), a.id])}
                                                                            className="px-3 py-1.5 rounded-lg bg-white/10 text-white/70 text-xs font-medium hover:bg-white/20 border border-white/20"
                                                                        >
                                                                            Not us
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {currentStep === 'purpose_promise' && (
                                        <div className="space-y-10">
                                            {/* Why */}
                                            <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
                                                <h3 className="text-lg font-semibold text-white mb-1">Why</h3>
                                                <p className="text-sm text-white/60 mb-4">This brand exists to</p>
                                                <textarea
                                                    value={identity.mission || ''}
                                                    onChange={(e) => updatePayload('identity', 'mission', e.target.value)}
                                                    rows={4}
                                                    placeholder="e.g. challenge the status quo, empower creators, make technology accessible…"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                />
                                                {latestSuggestions?.mission_suggestion && !dismissedInlineSuggestions.includes('purpose:mission') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggestion"
                                                        items={[String(latestSuggestions.mission_suggestion)]}
                                                        onApply={(val) => updatePayload('identity', 'mission', val)}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'purpose:mission'])}
                                                    />
                                                )}
                                                <p className="mt-3 text-xs text-white/50">Examples: Apple — &quot;Think different&quot; / challenge conformity. Patagonia — &quot;We&apos;re in business to save our home planet.&quot;</p>
                                            </div>
                                            {/* What */}
                                            <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
                                                <h3 className="text-lg font-semibold text-white mb-1">What</h3>
                                                <p className="text-sm text-white/60 mb-4">This brand delivers</p>
                                                <textarea
                                                    value={identity.positioning || ''}
                                                    onChange={(e) => updatePayload('identity', 'positioning', e.target.value)}
                                                    rows={4}
                                                    placeholder="e.g. premium devices that just work, outdoor gear built to last…"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                />
                                                {latestSuggestions?.positioning_suggestion && !dismissedInlineSuggestions.includes('purpose:positioning') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggestion"
                                                        items={[String(latestSuggestions.positioning_suggestion)]}
                                                        onApply={(val) => updatePayload('identity', 'positioning', val)}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'purpose:positioning'])}
                                                    />
                                                )}
                                                <p className="mt-3 text-xs text-white/50">Examples: Nike — &quot;To bring inspiration and innovation to every athlete.&quot; Tesla — &quot;Accelerate the world&apos;s transition to sustainable energy.&quot;</p>
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'expression' && (
                                        <div className="space-y-8">
                                            {personality.primary_archetype && ARCHETYPE_RECOMMENDED_TRAITS[personality.primary_archetype] && !dismissedInlineSuggestions.includes('expression:traits') && (
                                                <InlineSuggestionBlock
                                                    title={`Recommended traits for ${personality.primary_archetype}`}
                                                    items={ARCHETYPE_RECOMMENDED_TRAITS[personality.primary_archetype] || []}
                                                    onApply={(trait) => {
                                                        const current = personality.traits || []
                                                        if (current.includes(trait)) return
                                                        updatePayload('personality', 'traits', [...current, trait])
                                                    }}
                                                    onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'expression:traits'])}
                                                />
                                            )}
                                            <div className="grid md:grid-cols-2 gap-6">
                                                <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
                                                    <h3 className="text-lg font-semibold text-white mb-2">Brand Look</h3>
                                                    <p className="text-sm text-white/60 mb-4">Visual style, imagery, colors, and aesthetic.</p>
                                                    <textarea
                                                        value={personality.brand_look || ''}
                                                        onChange={(e) => updatePayload('personality', 'brand_look', e.target.value)}
                                                        rows={5}
                                                        placeholder="e.g. Minimal, clean lines, lots of white space. Photography is warm and candid. Color palette is muted earth tones."
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                    />
                                                    <p className="mt-3 text-xs text-white/50">Example: &quot;Bold, high-contrast visuals. Product shots on pure white. Typography is geometric and modern.&quot;</p>
                                                </div>
                                                <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
                                                    <h3 className="text-lg font-semibold text-white mb-2">Brand Voice</h3>
                                                    <p className="text-sm text-white/60 mb-4">How your brand sounds in copy, tone, and personality.</p>
                                                    <textarea
                                                        value={personality.voice_description || ''}
                                                        onChange={(e) => updatePayload('personality', 'voice_description', e.target.value)}
                                                        rows={5}
                                                        placeholder="e.g. Friendly but professional. We use contractions and avoid jargon. Confident without being arrogant."
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                    />
                                                    <p className="mt-3 text-xs text-white/50">Example: &quot;Warm, approachable, and slightly playful. We speak like a knowledgeable friend, not a salesperson.&quot;</p>
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tone keywords</label>
                                                <ChipInput
                                                    value={(scoringRules.tone_keywords || personality.tone_keywords || []).filter(Boolean)}
                                                    onChange={(v) => updatePayload('scoring_rules', 'tone_keywords', v)}
                                                    placeholder="Add tone keyword"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Traits</label>
                                                <ChipInput
                                                    value={personality.traits || []}
                                                    onChange={(v) => updatePayload('personality', 'traits', v)}
                                                    placeholder="Add trait"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'standards' && (
                                        <div className="space-y-8">
                                            <FieldCard title="Typography">
                                                <div className="grid sm:grid-cols-2 gap-4">
                                                    <FontListbox
                                                        label="Primary Font"
                                                        value={typography.primary_font || ''}
                                                        onChange={(v) => updatePayload('typography', 'primary_font', v)}
                                                    />
                                                    <FontListbox
                                                        label="Secondary Font"
                                                        value={typography.secondary_font || ''}
                                                        onChange={(v) => updatePayload('typography', 'secondary_font', v)}
                                                    />
                                                </div>
                                                <div className="mt-4">
                                                    <label className="block text-xs text-white/60 mb-1">Custom Font URL</label>
                                                    <ChipInput
                                                        value={(typography.external_font_links || []).filter((u) => typeof u === 'string' && u.startsWith('https://'))}
                                                        onChange={(v) => updatePayload('typography', 'external_font_links', v.filter((u) => typeof u === 'string' && u.trim().startsWith('https://')))}
                                                        placeholder="https://fonts.googleapis.com/… and press Enter"
                                                    />
                                                    <p className="mt-1 text-xs text-white/50">HTTPS only. Multiple entries allowed.</p>
                                                </div>
                                            </FieldCard>
                                            <FieldCard title="Allowed Color Palette (hex)">
                                                <p className="text-white/60 text-sm mb-3">Additional colors for scoring. Hex codes only.</p>
                                                <ColorPaletteChipInput
                                                    value={(scoringRules.allowed_color_palette || []).map((c) => (typeof c === 'object' && c?.hex) || (typeof c === 'string' ? c : '')).filter(Boolean)}
                                                    onChange={(v) => updatePayload('scoring_rules', 'allowed_color_palette', v.map((hex) => ({ hex, role: null })))}
                                                    placeholder="#hex and press Enter"
                                                />
                                                <p className="mt-2 text-xs text-white/50">Hex validation hint only; does not block save.</p>
                                            </FieldCard>
                                            <FieldCard title="Visual References">
                                                <p className="text-white/60 text-sm mb-2">Images that represent your brand look. Used for scoring.</p>
                                                <p className="text-white/50 text-xs mb-4">Select from assets you uploaded in Background (brand materials) or your Assets library.</p>
                                                {(visualReferences?.length ?? 0) === 0 && (
                                                    <p className="text-white/40 text-sm mb-3">No references yet — select or upload to add.</p>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => setAssetSelectorOpen('visual_reference')}
                                                    className="px-4 py-3 rounded-xl border border-white/20 text-white/90 hover:bg-white/10 text-sm"
                                                >
                                                    Select from Assets
                                                </button>
                                                {visualReferences?.length > 0 && (
                                                    <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                        {visualReferences.map((a) => (
                                                            <div key={a.id} className="rounded-xl border border-white/20 bg-white/5 p-2 flex flex-col">
                                                                <div className="aspect-square rounded-lg bg-white/10 overflow-hidden mb-2">
                                                                    {(a.thumbnail_url || a.signed_url) ? (
                                                                        <img src={a.thumbnail_url || a.signed_url} alt="" className="w-full h-full object-cover" />
                                                                    ) : (
                                                                        <div className="w-full h-full flex items-center justify-center text-white/40 text-2xl">◇</div>
                                                                    )}
                                                                </div>
                                                                <p className="text-xs text-white/80 truncate" title={a.original_filename}>{a.original_filename || a.title}</p>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleDetachVisualRef(a.id)}
                                                                    className="mt-1 text-xs text-red-400 hover:text-red-300"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </FieldCard>
                                            <FieldCard title="Brand Colors">
                                                <p className="text-white/60 text-sm mb-4">Define your brand&apos;s color palette. These colors will be used throughout the application.</p>
                                                <div className="grid sm:grid-cols-3 gap-6">
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Primary Color</label>
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="color"
                                                                value={(() => { const v = brandColors.primary_color || '#6366f1'; return v.startsWith('#') ? v : '#' + v; })()}
                                                                onChange={(e) => setBrandColors((c) => ({ ...c, primary_color: e.target.value || null }))}
                                                                className="h-10 w-12 rounded-lg border border-white/20 cursor-pointer bg-transparent flex-shrink-0"
                                                                style={{ padding: 2 }}
                                                            />
                                                            <input
                                                                type="text"
                                                                value={brandColors.primary_color || ''}
                                                                onChange={(e) => {
                                                                    const v = e.target.value.trim()
                                                                    setBrandColors((c) => ({ ...c, primary_color: v ? (v.startsWith('#') ? v : '#' + v) : null }))
                                                                }}
                                                                placeholder="#6366f1"
                                                                className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm placeholder-white/40"
                                                            />
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Secondary Color</label>
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="color"
                                                                value={(() => { const v = brandColors.secondary_color || '#8b5cf6'; return v.startsWith('#') ? v : '#' + v; })()}
                                                                onChange={(e) => setBrandColors((c) => ({ ...c, secondary_color: e.target.value || null }))}
                                                                className="h-10 w-12 rounded-lg border border-white/20 cursor-pointer bg-transparent flex-shrink-0"
                                                                style={{ padding: 2 }}
                                                            />
                                                            <input
                                                                type="text"
                                                                value={brandColors.secondary_color || ''}
                                                                onChange={(e) => {
                                                                    const v = e.target.value.trim()
                                                                    setBrandColors((c) => ({ ...c, secondary_color: v ? (v.startsWith('#') ? v : '#' + v) : null }))
                                                                }}
                                                                placeholder="#8b5cf6"
                                                                className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm placeholder-white/40"
                                                            />
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Accent Color</label>
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="color"
                                                                value={(() => { const v = brandColors.accent_color || '#06b6d4'; return v.startsWith('#') ? v : '#' + v; })()}
                                                                onChange={(e) => setBrandColors((c) => ({ ...c, accent_color: e.target.value || null }))}
                                                                className="h-10 w-12 rounded-lg border border-white/20 cursor-pointer bg-transparent flex-shrink-0"
                                                                style={{ padding: 2 }}
                                                            />
                                                            <input
                                                                type="text"
                                                                value={brandColors.accent_color || ''}
                                                                onChange={(e) => {
                                                                    const v = e.target.value.trim()
                                                                    setBrandColors((c) => ({ ...c, accent_color: v ? (v.startsWith('#') ? v : '#' + v) : null }))
                                                                }}
                                                                placeholder="#06b6d4"
                                                                className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm placeholder-white/40"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                                {(brandColors.primary_color || brandColors.secondary_color || brandColors.accent_color) && (
                                                    <div className="mt-6 pt-6 border-t border-white/10">
                                                        <p className="text-sm font-medium text-white/70 mb-3">Color Preview</p>
                                                        <div className="flex gap-3">
                                                            {brandColors.primary_color && (
                                                                <div className="flex-1">
                                                                    <div
                                                                        className="h-16 rounded-xl border border-white/20"
                                                                        style={{ backgroundColor: brandColors.primary_color }}
                                                                    />
                                                                    <p className="mt-2 text-xs text-center text-white/60">Primary</p>
                                                                </div>
                                                            )}
                                                            {brandColors.secondary_color && (
                                                                <div className="flex-1">
                                                                    <div
                                                                        className="h-16 rounded-xl border border-white/20"
                                                                        style={{ backgroundColor: brandColors.secondary_color }}
                                                                    />
                                                                    <p className="mt-2 text-xs text-center text-white/60">Secondary</p>
                                                                </div>
                                                            )}
                                                            {brandColors.accent_color && (
                                                                <div className="flex-1">
                                                                    <div
                                                                        className="h-16 rounded-xl border border-white/20"
                                                                        style={{ backgroundColor: brandColors.accent_color }}
                                                                    />
                                                                    <p className="mt-2 text-xs text-center text-white/60">Accent</p>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}
                                            </FieldCard>
                                        </div>
                                    )}
                                </StepShell>
                            </motion.div>
                        )}
                    </AnimatePresence>
                    </main>
                    {hasResearchData && currentStep !== 'background' && (
                        <aside className="w-80 lg:w-96 flex-shrink-0 border-l border-white/10 bg-[#0f0e14]/80 hidden lg:block">
                            <ResearchInsightsPanel
                                brandId={brand.id}
                                crawlerRunning={effectiveCrawlerRunning}
                                latestSnapshotLite={effectiveSnapshotLite}
                                latestCoherence={effectiveCoherence}
                                latestAlignment={effectiveAlignment}
                                latestSuggestions={effectiveSuggestions}
                                insightState={insightStateLocal}
                                stepKeys={stepKeys}
                                onDismiss={handleDismissInsight}
                                onAccept={handleAcceptInsight}
                                onApplySuggestion={handleApplySuggestion}
                                onJumpToStep={(step) => router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step }))}
                                defaultExpanded={!!effectiveSnapshotLite}
                            />
                        </aside>
                    )}
                </div>

                {/* Footer — always visible at bottom */}
                <footer
                    className="flex-shrink-0 px-4 sm:px-8 py-4 border-t border-white/10 bg-[#0f0e14]/95 backdrop-blur"
                    style={{ borderTopColor: 'rgba(255,255,255,0.1)' }}
                >
                    <div className="max-w-4xl mx-auto flex items-center justify-between gap-4">
                        <button
                            type="button"
                            onClick={handleBack}
                            disabled={stepIndex <= 0 || saving}
                            className="px-4 py-2.5 rounded-xl border border-white/20 text-white/90 hover:bg-white/10 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            Back
                        </button>
                        <div className="flex items-center gap-2">
                            {currentStepConfig?.skippable && !isReviewStep && (
                                <button
                                    type="button"
                                    onClick={handleSkip}
                                    disabled={saving}
                                    className="px-4 py-2.5 rounded-xl text-white/70 hover:text-white hover:bg-white/10 disabled:opacity-40 transition-colors"
                                >
                                    Skip
                                </button>
                            )}
                            {!isReviewStep && (
                                <button
                                    type="button"
                                    onClick={handleNext}
                                    disabled={saving}
                                    className="px-6 py-2.5 rounded-xl font-medium text-white transition-colors disabled:opacity-50"
                                    style={{ backgroundColor: saving ? '#4b5563' : accentColor }}
                                >
                                    {saving ? 'Saving…' : isLastDataStep ? 'Review' : 'Next'}
                                </button>
                            )}
                        </div>
                    </div>
                </footer>

                <BuilderAssetSelectorModal
                    open={assetSelectorOpen === 'visual_reference'}
                    onClose={() => setAssetSelectorOpen(null)}
                    brandId={brand.id}
                    builderContext="visual_reference"
                    onSelect={(asset) => handleAssetAttach(asset, 'visual_reference')}
                    title="Select Visual Reference"
                />
                <BuilderAssetSelectorModal
                    open={assetSelectorOpen === 'brand_material'}
                    onClose={() => setAssetSelectorOpen(null)}
                    brandId={brand.id}
                    builderContext="brand_material"
                    onSelect={(asset) => handleAssetAttach(asset, 'brand_material')}
                    title="Select Brand Materials"
                    multiSelect
                />
            </div>
        </div>
    )
}

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
import axios from 'axios'

const ARCHETYPES = [
    'Creator', 'Caregiver', 'Ruler', 'Jester', 'Everyman', 'Lover',
    'Hero', 'Outlaw', 'Magician', 'Innocent', 'Sage', 'Explorer',
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
function BuilderUploadDropzone({ brandId, builderContext, onUploadComplete, label, count = 0, accept }) {
    const [uploading, setUploading] = useState(false)
    const [error, setError] = useState(null)
    const inputRef = useRef(null)

    const doUpload = useCallback(async (file) => {
        if (!file) return
        setError(null)
        setUploading(true)
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
            setUploading(false)
        }
    }, [brandId, builderContext, onUploadComplete])

    const handleChange = (e) => {
        const f = e.target.files?.[0]
        if (f) doUpload(f)
        e.target.value = ''
    }

    return (
        <div>
            <button
                type="button"
                onClick={() => inputRef.current?.click()}
                disabled={uploading}
                className="w-full py-8 px-6 rounded-2xl border-2 border-dashed border-white/30 bg-white/5 hover:bg-white/10 hover:border-white/40 transition-colors text-white/80 disabled:opacity-50 flex flex-col items-center gap-2"
            >
                {uploading ? (
                    <span className="animate-pulse">Uploading…</span>
                ) : (
                    <>
                        <svg className="w-10 h-10 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <span>{label}</span>
                        {count > 0 && <span className="text-sm text-white/60">{count} uploaded</span>}
                    </>
                )}
            </button>
            <input ref={inputRef} type="file" className="hidden" onChange={handleChange} accept={accept ?? 'image/*,.pdf,.doc,.docx'} />
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
        { label: 'Mission', value: truncate(identity.mission) },
        { label: 'Positioning', value: truncate(identity.positioning) },
        { label: 'Archetype', value: personality.primary_archetype || (personality.candidate_archetypes || []).join(', ') || '—' },
        { label: 'Tone & Traits', value: personality.tone ? `Tone: ${personality.tone}; Traits: ${(personality.traits || []).length}` : `Traits: ${(personality.traits || []).length}` },
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

export default function BrandGuidelinesBuilder({ brand, draft, modelPayload, steps, stepKeys, currentStep }) {
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
    const [brandMaterialCount, setBrandMaterialCount] = useState(0)
    const [photoRefCount, setPhotoRefCount] = useState(() => approvedRefsCount(modelPayload?.visual?.approved_references))

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

    const handlePhotoRefUpload = useCallback((assetId) => {
        setPayload((prev) => {
            const existing = (prev.visual?.approved_references || []).map((ref) =>
                typeof ref === 'object' && ref?.asset_id ? ref : { asset_id: ref, kind: 'photo_reference' }
            )
            return {
                ...prev,
                visual: {
                    ...(prev.visual || {}),
                    approved_references: [...existing, { asset_id: assetId, kind: 'photo_reference' }],
                },
            }
        })
        setPhotoRefCount((c) => c + 1)
    }, [])

    const sources = payload.sources || {}
    const identity = payload.identity || {}
    const personality = payload.personality || {}
    const typography = payload.typography || {}
    const scoringRules = payload.scoring_rules || {}
    const visual = payload.visual || {}

    return (
        <div className="min-h-screen bg-[#0f0e14] relative overflow-hidden">
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

            <main className="relative z-10 flex flex-col min-h-screen">
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

                <div className="flex-1 px-4 sm:px-8 py-8 max-w-4xl mx-auto w-full">
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
                                    const stepKey = msg.includes('Background') ? 'background' : msg.includes('Positioning') ? 'positioning' : msg.includes('Archetype') ? 'archetype' : msg.includes('Standards') ? 'standards' : null
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
                                                        <div className="flex gap-2">
                                                            <input
                                                                type="url"
                                                                value={sources.website_url || ''}
                                                                onChange={(e) => updatePayload('sources', 'website_url', e.target.value)}
                                                                placeholder="https://yoursite.com"
                                                                className="flex-1 rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 focus:border-white/30"
                                                            />
                                                            <button
                                                                type="button"
                                                                disabled
                                                                title="Coming soon"
                                                                className="px-4 py-3 rounded-xl border border-white/20 text-white/50 text-sm cursor-not-allowed"
                                                            >
                                                                Analyze & Prefill <span className="text-white/40">(Coming soon)</span>
                                                            </button>
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
                                                <BuilderUploadDropzone
                                                    brandId={brand.id}
                                                    builderContext="brand_material_example"
                                                    onUploadComplete={() => setBrandMaterialCount((c) => c + 1)}
                                                    label="Upload brand materials (optional)"
                                                    count={brandMaterialCount}
                                                    accept="image/*,.pdf"
                                                />
                                            </FieldCard>
                                        </div>
                                    )}

                                    {currentStep === 'positioning' && (
                                        <div className="space-y-8">
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Mission (WHY)</label>
                                                <textarea
                                                    value={identity.mission || ''}
                                                    onChange={(e) => updatePayload('identity', 'mission', e.target.value)}
                                                    rows={4}
                                                    placeholder="Why does your brand exist? What problem do you solve?"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Positioning (WHAT)</label>
                                                <textarea
                                                    value={identity.positioning || ''}
                                                    onChange={(e) => updatePayload('identity', 'positioning', e.target.value)}
                                                    rows={4}
                                                    placeholder="What do you offer? How do you differentiate?"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                />
                                            </div>
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
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tagline</label>
                                                <input
                                                    type="text"
                                                    value={identity.tagline || ''}
                                                    onChange={(e) => updatePayload('identity', 'tagline', e.target.value)}
                                                    placeholder="e.g. Just Do It"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'archetype' && (
                                        <div className="space-y-8">
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Primary Archetype</label>
                                                <select
                                                    value={personality.primary_archetype || ''}
                                                    onChange={(e) => updatePayload('personality', 'primary_archetype', e.target.value || null)}
                                                    className="w-full rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-white focus:ring-2 focus:ring-white/30"
                                                >
                                                    <option value="">— Select —</option>
                                                    {ARCHETYPES.map((a) => (
                                                        <option key={a} value={a}>{a}</option>
                                                    ))}
                                                </select>
                                                <button type="button" className="mt-2 inline-block text-sm text-white/60 hover:text-white/80">Need help?</button>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Candidate Archetypes</label>
                                                <ChipInput
                                                    value={personality.candidate_archetypes || []}
                                                    onChange={(v) => updatePayload('personality', 'candidate_archetypes', v)}
                                                    placeholder="Add archetype and press Enter"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Rejected Archetypes</label>
                                                <ChipInput
                                                    value={personality.rejected_archetypes || []}
                                                    onChange={(v) => updatePayload('personality', 'rejected_archetypes', v)}
                                                    placeholder="Add rejected archetype"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'purpose_promise' && (
                                        <div className="space-y-8">
                                            <div className="grid md:grid-cols-2 gap-6">
                                                <FieldCard title="WHY (Mission)">
                                                    <textarea
                                                        value={identity.mission || ''}
                                                        onChange={(e) => updatePayload('identity', 'mission', e.target.value)}
                                                        rows={4}
                                                        placeholder="Your purpose…"
                                                        className="w-full bg-transparent border-0 text-white placeholder-white/40 focus:ring-0 resize-none"
                                                    />
                                                </FieldCard>
                                                <FieldCard title="WHAT / Promise (Positioning)">
                                                    <textarea
                                                        value={identity.positioning || ''}
                                                        onChange={(e) => updatePayload('identity', 'positioning', e.target.value)}
                                                        rows={4}
                                                        placeholder="Your promise…"
                                                        className="w-full bg-transparent border-0 text-white placeholder-white/40 focus:ring-0 resize-none"
                                                    />
                                                </FieldCard>
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

                                    {currentStep === 'expression' && (
                                        <div className="space-y-8">
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tone</label>
                                                <input
                                                    type="text"
                                                    value={personality.tone || ''}
                                                    onChange={(e) => updatePayload('personality', 'tone', e.target.value)}
                                                    placeholder="e.g. Professional, playful"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
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
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Voice Description</label>
                                                <textarea
                                                    value={personality.voice_description || ''}
                                                    onChange={(e) => updatePayload('personality', 'voice_description', e.target.value)}
                                                    rows={5}
                                                    placeholder="How does your brand sound? Describe the voice in a few sentences."
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'standards' && (
                                        <div className="space-y-8">
                                            <FieldCard title="Typography">
                                                <div className="grid sm:grid-cols-2 gap-4">
                                                    <div>
                                                        <label className="block text-xs text-white/60 mb-1">Primary Font</label>
                                                        <input
                                                            type="text"
                                                            value={typography.primary_font || ''}
                                                            onChange={(e) => updatePayload('typography', 'primary_font', e.target.value)}
                                                            placeholder="e.g. Inter"
                                                            className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="block text-xs text-white/60 mb-1">Secondary Font</label>
                                                        <input
                                                            type="text"
                                                            value={typography.secondary_font || ''}
                                                            onChange={(e) => updatePayload('typography', 'secondary_font', e.target.value)}
                                                            placeholder="e.g. Georgia"
                                                            className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm"
                                                        />
                                                    </div>
                                                </div>
                                            </FieldCard>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Allowed Color Palette (hex)</label>
                                                <ChipInput
                                                    value={(scoringRules.allowed_color_palette || []).map((c) => (typeof c === 'object' && c?.hex) || (typeof c === 'string' ? c : '')).filter(Boolean)}
                                                    onChange={(v) => updatePayload('scoring_rules', 'allowed_color_palette', v.map((hex) => ({ hex, role: null })))}
                                                    placeholder="#hex and press Enter"
                                                />
                                                <p className="mt-1 text-xs text-white/50">Hex validation hint only; does not block save.</p>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Visual References</label>
                                                <BuilderUploadDropzone
                                                    brandId={brand.id}
                                                    builderContext="photo_reference"
                                                    onUploadComplete={handlePhotoRefUpload}
                                                    label="Upload photography references"
                                                    count={photoRefCount}
                                                />
                                            </div>
                                            <div className="grid sm:grid-cols-3 gap-4">
                                                <div>
                                                    <label className="block text-xs text-white/60 mb-1">Primary Color</label>
                                                    <input
                                                        type="text"
                                                        value={brandColors.primary_color || ''}
                                                        onChange={(e) => setBrandColors((c) => ({ ...c, primary_color: e.target.value || null }))}
                                                        placeholder="#6366f1"
                                                        className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs text-white/60 mb-1">Secondary</label>
                                                    <input
                                                        type="text"
                                                        value={brandColors.secondary_color || ''}
                                                        onChange={(e) => setBrandColors((c) => ({ ...c, secondary_color: e.target.value || null }))}
                                                        placeholder="#8b5cf6"
                                                        className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs text-white/60 mb-1">Accent</label>
                                                    <input
                                                        type="text"
                                                        value={brandColors.accent_color || ''}
                                                        onChange={(e) => setBrandColors((c) => ({ ...c, accent_color: e.target.value || null }))}
                                                        placeholder="#06b6d4"
                                                        className="w-full rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </StepShell>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>

                {/* Sticky footer */}
                <footer
                    className="flex-shrink-0 sticky bottom-0 px-4 sm:px-8 py-4 border-t border-white/10 bg-[#0f0e14]/95 backdrop-blur"
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
            </main>
        </div>
    )
}

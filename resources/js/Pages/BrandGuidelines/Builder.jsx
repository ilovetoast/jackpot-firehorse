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
import ConfirmDialog from '../../Components/ConfirmDialog'
import FontListbox from '../../Components/BrandGuidelines/FontListbox'
import BuilderAssetSelectorModal from '../../Components/BrandGuidelines/BuilderAssetSelectorModal'
import ResearchInsightsPanel from '../../Components/BrandGuidelines/ResearchInsightsPanel'
import ResearchSummary, { canProceedFromResearchSummary } from '../../Components/BrandGuidelines/ResearchSummary'
import ProcessingProgressPanel from '../../Components/BrandGuidelines/ProcessingProgressPanel'
import BrandResearchReadyToast from '../../Components/BrandGuidelines/BrandResearchReadyToast'
import InlineSuggestionBlock from '../../Components/BrandGuidelines/InlineSuggestionBlock'
import axios from 'axios'

import { ARCHETYPES, ARCHETYPE_RECOMMENDED_TRAITS } from '../../constants/brandOptions'

// Unwrap AI-wrapped field: { value, source, confidence } -> value
function unwrapValue(field) {
    if (field && typeof field === 'object' && 'value' in field) return field.value
    return field
}

function isAiPopulated(field) {
    return field && typeof field === 'object' && field.source === 'ai'
}

function AiFieldBadge({ field, className = '' }) {
    if (!isAiPopulated(field)) return null
    const conf = field.confidence != null ? Math.round(field.confidence * 100) : null
    const sources = field.sources || []
    const tooltip = sources.length > 0 ? `Detected from:\n• ${sources.join('\n• ')}` : 'AI detected'
    return (
        <span
            className={`inline-flex items-center gap-1.5 text-xs text-emerald-400/90 ${className}`}
            title={tooltip}
        >
            <span>AI detected</span>
            {conf != null && <span className="text-white/50">({conf}% confidence)</span>}
        </span>
    )
}

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
function ChipInput({ value = [], onChange, placeholder = 'Type and press Enter', onKeyDown, disabled }) {
    const [input, setInput] = useState('')
    const inputRef = useRef(null)

    const add = (v) => {
        if (disabled) return
        const trimmed = (typeof v === 'string' ? v : input).trim()
        if (!trimmed) return
        const next = Array.isArray(value) ? [...value] : []
        if (next.includes(trimmed)) return
        next.push(trimmed)
        onChange(next)
        setInput('')
    }

    const remove = (i) => {
        if (disabled) return
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
        <div className={`flex flex-wrap gap-2 p-3 rounded-xl border border-white/20 bg-white/5 min-h-[52px] ${disabled ? 'opacity-60 pointer-events-none' : ''}`}>
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
                disabled={disabled}
                className="flex-1 min-w-[120px] bg-transparent border-0 text-white placeholder-white/50 focus:ring-0 focus:outline-none text-sm disabled:cursor-not-allowed"
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

// ——— ProcessingView ———
// Full-page processing status. Only shown when step=processing (intentional checkpoint).
// Only shows items for sources that are actually applicable (hasPdf, hasWebsite, etc.).
function ProcessingView({ pdfExtractionPolling, ingestionProcessing, ingestionRecords, crawlerRunning, polledResearch, guidelinesPdfFilename, hasPdf = false, hasWebsite = false, hasSocial = false, hasMaterials = false }) {
    const hasIngestion = ingestionProcessing || (polledResearch?.ingestionProcessing ?? false)
    const effectiveCrawler = crawlerRunning || (polledResearch?.crawlerRunning ?? false)
    const records = polledResearch?.ingestionRecords ?? ingestionRecords ?? []
    const hasErrors = records.some((r) => r.status === 'failed' || r.error)

    const pdf = polledResearch?.pdf ?? {}
    const website = polledResearch?.website ?? {}
    const social = polledResearch?.social ?? {}
    const materials = polledResearch?.materials ?? {}

    const pdfStatus = pdf.status || (pdfExtractionPolling ? 'processing' : 'pending')
    const pdfPages = pdf.pages_total > 0 ? `${pdf.pages_processed ?? 0} / ${pdf.pages_total}` : null
    const pdfSignals = pdf.signals_detected > 0 ? `Signals: ${pdf.signals_detected}` : null
    // PDF phase: text_extraction | vision_rendering (pages_total=0) | vision (page-by-page AI)
    const pdfPhase = pdf.phase || (pdf.pages_total > 0 ? 'vision' : (pdfExtractionPolling ? 'text_extraction' : null))
    const pdfPhaseRendering = pdf.phase === 'vision' && pdf.pages_total === 0 && pdfStatus === 'processing'

    const websiteStatus = website.status || (effectiveCrawler ? 'processing' : 'pending')
    const materialsStatus = materials.status || (hasIngestion ? 'processing' : 'pending')
    const materialsProgress = materials.assets_total > 0 ? `${materials.assets_processed ?? 0} / ${materials.assets_total}` : null

    const incomingPdfProgress = pdf.pages_total > 0 ? Math.round(((pdf.pages_processed ?? 0) / pdf.pages_total) * 100) : 0
    const incomingMaterialsProgress = materials.assets_total > 0 ? Math.round(((materials.assets_processed ?? 0) / materials.assets_total) * 100) : 0
    const [stablePdfProgress, setStablePdfProgress] = useState(0)
    const [stableMaterialsProgress, setStableMaterialsProgress] = useState(0)
    useEffect(() => {
        setStablePdfProgress((prev) => Math.max(prev, incomingPdfProgress))
    }, [incomingPdfProgress])
    useEffect(() => {
        setStableMaterialsProgress((prev) => Math.max(prev, incomingMaterialsProgress))
    }, [incomingMaterialsProgress])

    // Only show Upload when there are multiple PDF-related stages (2+); otherwise it's redundant
    const pdfHasMultipleStages = hasPdf && (pdfStatus === 'processing' || hasIngestion)
    const allCandidates = [
        pdfHasMultipleStages && {
            key: 'upload',
            label: 'Upload',
            status: 'complete',
            detail: 'Complete',
        },
        hasPdf && {
            key: 'pdf',
            label: guidelinesPdfFilename ? `Brand Guidelines PDF (${guidelinesPdfFilename})` : 'Brand Guidelines PDF',
            status: pdfStatus === 'completed' ? 'complete' : pdfStatus === 'failed' ? 'failed' : pdfStatus === 'processing' ? 'processing' : 'pending',
            detail: pdfStatus === 'completed' ? 'Complete' : pdfStatus === 'failed' ? 'Failed' : pdfPhaseRendering ? 'Rendering pages…' : pdfPhase === 'text_extraction' ? 'Extracting text…' : pdfPages ? `Analyzing page ${pdf.pages_processed ?? 0} of ${pdf.pages_total}` : (pdfStatus === 'processing' ? 'Processing…' : 'Pending'),
            signals: pdfSignals,
            progress: pdf.pages_total > 0 ? stablePdfProgress : null,
        },
        hasWebsite && {
            key: 'website',
            label: 'Website',
            status: websiteStatus === 'completed' ? 'complete' : websiteStatus === 'processing' ? 'processing' : 'pending',
            detail: websiteStatus === 'completed' ? 'Complete' : websiteStatus === 'processing' ? 'Analyzing…' : 'Pending',
            signals: website.signals_detected > 0 ? `Signals: ${website.signals_detected}` : null,
        },
        hasMaterials && {
            key: 'materials',
            label: 'Brand Materials',
            status: materialsStatus === 'completed' ? 'complete' : materialsStatus === 'processing' ? 'processing' : 'pending',
            detail: materialsStatus === 'completed' ? 'Complete' : materialsProgress ? `Processing ${materialsProgress}` : (materialsStatus === 'processing' ? 'Processing…' : 'Pending'),
            progress: materials.assets_total > 0 ? stableMaterialsProgress : null,
        },
        hasSocial && {
            key: 'social',
            label: 'Social',
            status: social.status === 'completed' ? 'complete' : social.status === 'processing' ? 'processing' : 'pending',
            detail: social.status === 'completed' ? 'Complete' : social.status === 'processing' ? 'Analyzing…' : 'Pending',
        },
    ]

    // Ingestion / AI Summary — show when processing (generating insights) or failed
    if (hasIngestion) {
        const ingestionFailed = records.some((r) => r.status === 'failed' || r.error)
        if (!ingestionFailed) {
            allCandidates.push({
                key: 'ingestion',
                label: 'AI Summary',
                status: 'processing',
                detail: 'Generating insights and suggestions…',
                progress: null,
            })
        }
    }

    const sourceItems = allCandidates.filter(Boolean)

    records.forEach((r, i) => {
        if (r.status === 'failed' || r.error) {
            sourceItems.push({
                key: `record-${r.id || i}`,
                label: 'AI Summary',
                status: 'failed',
                detail: r.error || 'Failed',
            })
        }
    })

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-8 w-full">
            <p className="text-white/60 text-sm mb-2">
                We&apos;re extracting data from your uploads to fill and suggest content for the next steps.
            </p>
            <p className="text-white/50 text-sm mb-6">
                This may take a few minutes — we&apos;re using AI to extract text and analyze images. You can leave and return; progress is saved.
            </p>
            <div className="space-y-5">
                {sourceItems.map((item) => (
                    <div key={item.key} className="flex items-center gap-4">
                        <div className={`flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${
                            item.status === 'complete' ? 'bg-emerald-500/20 text-emerald-400' :
                            item.status === 'failed' ? 'bg-red-500/20 text-red-400' :
                            'bg-amber-500/20 text-amber-400'
                        }`}>
                            {item.status === 'complete' ? (
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" /></svg>
                            ) : item.status === 'failed' ? (
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" /></svg>
                            ) : (
                                <motion.div
                                    className="w-4 h-4 rounded-full bg-amber-400"
                                    animate={{ opacity: [0.5, 1, 0.5] }}
                                    transition={{ duration: 1.2, repeat: Infinity }}
                                />
                            )}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className={`text-sm font-medium ${item.status === 'complete' ? 'text-emerald-400' : item.status === 'failed' ? 'text-red-400' : 'text-amber-200'}`}>
                                {item.label}
                            </p>
                            <p className="text-xs text-white/50 mt-0.5">
                                {item.detail}
                                {item.signals && ` • ${item.signals}`}
                            </p>
                            {item.progress != null && item.status === 'processing' && (
                                <div className="mt-1.5 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                    <motion.div
                                        className="h-full rounded-full bg-amber-400"
                                        initial={{ width: 0 }}
                                        animate={{ width: `${item.progress}%` }}
                                        transition={{ duration: 0.4 }}
                                    />
                                </div>
                            )}
                            {item.status === 'processing' && item.progress == null && (
                                <div className="mt-1.5 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                    <motion.div
                                        className="h-full rounded-full bg-amber-400"
                                        initial={{ width: '0%' }}
                                        animate={{ width: ['0%', '80%', '100%', '60%'] }}
                                        transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            <p className="mt-8 text-sm text-white/50">
                You cannot proceed to the next step until processing is complete.
            </p>
        </div>
    )
}

// ——— ProcessingStatusPanel ———
// Compact panel for non-Background steps. On Background step when processing, ProcessingView is shown instead.
function ProcessingStatusPanel({ pdfExtractionPolling, ingestionProcessing, ingestionRecords, crawlerRunning, polledResearch, guidelinesPdfFilename, currentStep }) {
    const hasIngestion = ingestionProcessing || (polledResearch?.ingestionProcessing ?? false)
    const effectiveCrawler = crawlerRunning || (polledResearch?.crawlerRunning ?? false)
    const records = polledResearch?.ingestionRecords ?? ingestionRecords ?? []
    const hasErrors = records.some((r) => r.status === 'failed' || r.error)
    const showCrawlerInPanel = effectiveCrawler && currentStep !== 'background'

    if (!pdfExtractionPolling && !hasIngestion && !showCrawlerInPanel && !hasErrors && records.length === 0) return null

    return (
        <div className="mb-6 rounded-xl border border-white/20 bg-white/5 p-5">
            <h4 className="text-sm font-medium text-white/80 mb-3">Processing</h4>
            <div className="space-y-3">
                {pdfExtractionPolling && (
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-amber-200">
                                {guidelinesPdfFilename ? `Extracting text from ${guidelinesPdfFilename}` : 'Extracting text from Brand Guidelines PDF'}
                            </span>
                        </div>
                        <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
                            <motion.div
                                className="h-full rounded-full bg-amber-400"
                                initial={{ width: '0%' }}
                                animate={{ width: ['0%', '50%', '100%', '50%'] }}
                                transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                            />
                        </div>
                    </div>
                )}
                {showCrawlerInPanel && (
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-amber-200">Analyzing website & social links</span>
                        </div>
                        <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
                            <motion.div
                                className="h-full rounded-full bg-amber-400"
                                initial={{ width: '0%' }}
                                animate={{ width: ['0%', '70%', '100%', '70%'] }}
                                transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
                            />
                        </div>
                    </div>
                )}
                {(ingestionProcessing || (polledResearch?.ingestionProcessing ?? false)) && (
                    <div className="space-y-1.5">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-amber-200">
                                {guidelinesPdfFilename ? `Processing Brand Guidelines (${guidelinesPdfFilename})` : 'Processing PDF and materials'}
                            </span>
                        </div>
                        <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
                            <motion.div
                                className="h-full rounded-full bg-amber-400"
                                initial={{ width: '0%' }}
                                animate={{ width: ['0%', '60%', '100%', '60%'] }}
                                transition={{ duration: 2.5, repeat: Infinity, ease: 'easeInOut' }}
                            />
                        </div>
                    </div>
                )}
                {records.map((r, i) => (
                    <div key={r.id || i} className="space-y-1.5">
                        <div className="flex items-center justify-between text-sm">
                            <span className={r.status === 'completed' ? 'text-emerald-400' : r.status === 'failed' || r.error ? 'text-red-400' : 'text-amber-200'}>
                                {r.status === 'processing' && 'Extracting…'}
                                {r.status === 'completed' && 'Complete'}
                                {(r.status === 'failed' || r.error) && (r.error || 'Failed')}
                            </span>
                            {r.status === 'completed' && (
                                <span className="text-emerald-400/80 text-xs">✓</span>
                            )}
                        </div>
                        <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
                            {r.status === 'processing' ? (
                                <motion.div
                                    className="h-full rounded-full bg-amber-400"
                                    initial={{ width: '10%' }}
                                    animate={{ width: ['10%', '90%', '10%'] }}
                                    transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
                                />
                            ) : r.status === 'completed' ? (
                                <motion.div
                                    className="h-full rounded-full bg-emerald-400"
                                    initial={{ width: 0 }}
                                    animate={{ width: '100%' }}
                                    transition={{ duration: 0.3 }}
                                />
                            ) : (r.status === 'failed' || r.error) ? (
                                <div className="h-full w-full rounded-full bg-red-500/60" />
                            ) : null}
                        </div>
                    </div>
                ))}
            </div>
            {(pdfExtractionPolling || hasIngestion || showCrawlerInPanel) ? (
                <p className="mt-3 text-xs text-white/50">Wait for processing to finish before continuing to the next step.</p>
            ) : records.length > 0 && !hasErrors ? (
                <p className="mt-3 text-xs text-emerald-400/80">Processing complete. You can continue to the next step.</p>
            ) : null}
        </div>
    )
}

// ——— PdfGuidelinesUploadCard ———
// Upload PDF → attach to draft → trigger extraction → poll → prefill draft. Additive by default (fill_empty).
function PdfGuidelinesUploadCard({ brandId, accentColor, payload, setPayload, setBrandColors, saving, setErrors, onTriggerIngestion, onExtractionPollingChange, onPdfUploadingChange, onPdfAttached, initialPdfAssetId, initialPdfFilename }) {
    const [pdfAssetId, setPdfAssetId] = useState(initialPdfAssetId ?? null)
    const [pdfFilename, setPdfFilename] = useState(initialPdfFilename ?? null)
    const [extractionStatus, setExtractionStatus] = useState(null)
    const [extractionPolling, setExtractionPolling] = useState(false)
    const [prefillLoading, setPrefillLoading] = useState(false)
    const [prefilled, setPrefilled] = useState(false)
    const [manualPaste, setManualPaste] = useState('')
    const [showManualPaste, setShowManualPaste] = useState(false)
    const pollRef = useRef(null)
    const pollStartRef = useRef(null)
    const autoAppliedRef = useRef(false)

    useEffect(() => {
        setPdfAssetId(initialPdfAssetId ?? null)
        setPdfFilename(initialPdfFilename ?? null)
    }, [initialPdfAssetId, initialPdfFilename])

    useEffect(() => {
        autoAppliedRef.current = false
    }, [pdfAssetId])

    // Fetch extraction status on mount when PDF exists (e.g. user returned to page after failed extraction)
    useEffect(() => {
        if (!initialPdfAssetId || extractionPolling) return
        axios.get(route('assets.pdf-text-extraction.show', { asset: initialPdfAssetId }))
            .then((res) => {
                const ext = res.data?.extraction
                if (!ext) return
                if (ext.status === 'failed') {
                    setExtractionStatus({
                        status: 'failed',
                        error: ext.failure_reason ? 'Extraction failed — no selectable text detected.' : ext.error_message,
                        failure_reason: ext.failure_reason,
                    })
                } else if (ext.status === 'complete' && ext.extracted_text) {
                    setExtractionStatus(ext)
                } else if (ext.status === 'complete' && !ext.extracted_text) {
                    setExtractionStatus({ status: 'empty' })
                }
            })
            .catch((err) => {
                // Asset was deleted (404) — clear stale state so user sees upload dropzone
                if (err.response?.status === 404 || (err.response?.data?.message || '').includes('No query results')) {
                    setPdfAssetId(null)
                    setPdfFilename(null)
                }
            })
    }, [initialPdfAssetId, extractionPolling])

    const triggerExtraction = useCallback(async (assetId) => {
        try {
            const res = await axios.post(route('assets.pdf-text-extraction.store', { asset: assetId }))
            if (res.status === 202) {
                setExtractionPolling(true)
                pollStartRef.current = Date.now()
                onExtractionPollingChange?.(true)
            }
        } catch (e) {
            if (e.response?.status === 404 || (e.response?.data?.message || '').includes('No query results')) {
                setPdfAssetId(null)
                setPdfFilename(null)
            } else {
                setErrors([e.response?.data?.message || 'Failed to start extraction'])
            }
        }
    }, [setErrors, onExtractionPollingChange])

    const pollExtraction = useCallback(async () => {
        if (!pdfAssetId || !extractionPolling) return
        if (Date.now() - (pollStartRef.current || 0) > 45000) {
            setExtractionPolling(false)
            onExtractionPollingChange?.(false)
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
                onExtractionPollingChange?.(false)
                setExtractionStatus(ext)
                // Only trigger ingestion for text-based PDFs. When vision_fallback_triggered, MergeBrandPdfExtractionJob will trigger it.
                if (ext.extracted_text && !ext.vision_fallback_triggered && onTriggerIngestion) {
                    onTriggerIngestion({ pdf_asset_id: pdfAssetId })
                }
                return
            }
            if (ext.status === 'failed') {
                setExtractionPolling(false)
                onExtractionPollingChange?.(false)
                setExtractionStatus({
                    status: 'failed',
                    error: ext.failure_reason ? 'Extraction failed — no selectable text detected.' : ext.error_message,
                    failure_reason: ext.failure_reason,
                })
                return
            }
        } catch (err) {
            if (err.response?.status === 404 || (err.response?.data?.message || '').includes('No query results')) {
                setExtractionPolling(false)
                onExtractionPollingChange?.(false)
                setPdfAssetId(null)
                setPdfFilename(null)
            }
            // else keep polling
        }
    }, [pdfAssetId, extractionPolling, onTriggerIngestion, onExtractionPollingChange])

    useEffect(() => {
        if (!extractionPolling) return
        pollRef.current = setInterval(pollExtraction, 2000)
        return () => {
            if (pollRef.current) clearInterval(pollRef.current)
        }
    }, [extractionPolling, pollExtraction])

    const handlePdfUploadComplete = useCallback(async (assetId, meta) => {
        setPdfAssetId(assetId)
        setPdfFilename(meta?.filename ?? null)
        setExtractionStatus(null)
        setPrefilled(false)
        try {
            await axios.post(route('brands.brand-dna.builder.attach-asset', { brand: brandId }), {
                asset_id: assetId,
                builder_context: 'guidelines_pdf',
            })
            onPdfAttached?.()
        } catch (e) {
            if (e.response?.status === 404 || (e.response?.data?.message || '').includes('No query results')) {
                setPdfAssetId(null)
                setPdfFilename(null)
            } else {
                setErrors([e.response?.data?.message || 'Failed to attach PDF'])
            }
            return
        }
        triggerExtraction(assetId)
    }, [brandId, triggerExtraction, setErrors, onPdfAttached])

    const handleReplacePdf = useCallback(async () => {
        if (!pdfAssetId) {
            setPdfAssetId(null)
            setPdfFilename(null)
            setExtractionStatus(null)
            return
        }
        try {
            await axios.post(route('brands.brand-dna.builder.detach-asset', { brand: brandId }), {
                asset_id: pdfAssetId,
                builder_context: 'guidelines_pdf',
            })
        } catch {
            // continue to clear UI
        }
        setPdfAssetId(null)
        setPdfFilename(null)
        setExtractionStatus(null)
    }, [brandId, pdfAssetId])

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

    const isReady = extractionStatus?.status === 'complete' && extractionStatus?.extracted_text
    useEffect(() => {
        if (isReady && !prefilled && !prefillLoading && !autoAppliedRef.current) {
            autoAppliedRef.current = true
            handlePrefill('fill_empty')
        }
    }, [isReady, prefilled, prefillLoading, handlePrefill])

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

    const isEmpty = extractionStatus?.status === 'empty' || (extractionStatus?.status === 'complete' && !extractionStatus?.extracted_text)
    const isFailed = extractionStatus?.status === 'failed'
    const isTimeout = extractionStatus?.status === 'timeout'

    return (
        <FieldCard title="Import Official Brand Guidelines (PDF)">
            <p className="text-white/60 text-sm mb-4">We&apos;ll extract text and apply it to your Brand DNA automatically. Optional.</p>
            {!pdfAssetId ? (
                <BuilderUploadDropzone
                    brandId={brandId}
                    builderContext="guidelines_pdf"
                    onUploadComplete={handlePdfUploadComplete}
                    onUploadingChange={onPdfUploadingChange}
                    label="Upload PDF guidelines"
                    accept=".pdf,application/pdf"
                />
            ) : (
                <div className="space-y-4">
                    {/* Cinematic filename display — dropzone replaced by prominent file name */}
                    <motion.div
                        initial={{ opacity: 0, y: 8, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
                        className="rounded-xl border border-white/20 bg-white/5 px-5 py-4"
                    >
                        <div className="flex items-center gap-3">
                            <div className="flex-shrink-0 w-10 h-10 rounded-lg bg-white/10 flex items-center justify-center">
                                <svg className="w-5 h-5 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-white font-medium truncate" title={pdfFilename || 'PDF'}>
                                    {pdfFilename || 'Guidelines PDF'}
                                </p>
                                <p className="text-white/50 text-xs mt-0.5">
                                    {extractionPolling ? 'Extracting text…' : prefillLoading ? 'Applying…' : prefilled ? 'Applied' : isReady ? 'Applying…' : isFailed ? 'Extraction failed' : isEmpty ? 'No extractable text' : isTimeout ? 'Still processing…' : 'Processing…'}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={handleReplacePdf}
                                className="text-white/50 hover:text-white/90 text-sm underline"
                            >
                                Replace
                            </button>
                        </div>
                    </motion.div>
                    {isTimeout && (
                        <p className="text-amber-200/90 text-sm">Still working — continue and we&apos;ll notify when ready.</p>
                    )}
                    {isFailed && (
                        <div className="space-y-2">
                            <p className="text-red-400/90 text-sm">{extractionStatus?.error || 'Extraction failed — no selectable text detected.'}</p>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={handleReplacePdf}
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
                    {isEmpty && (
                        <div className="space-y-2">
                            <p className="text-amber-200/90 text-sm">This PDF appears to be scanned or has no selectable text.</p>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    onClick={handleReplacePdf}
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
                    {prefilled && (
                        <p className="text-emerald-400 text-sm">Applied — review each step.</p>
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
// Supports both click-to-upload and drag-and-drop. Shows upload progress and errors.
function BuilderUploadDropzone({ brandId, builderContext, onUploadComplete, onUploadingChange, label, count = 0, accept }) {
    const [uploadingCount, setUploadingCount] = useState(0)
    const [pendingNames, setPendingNames] = useState([])
    const [error, setError] = useState(null)
    const [isDragging, setIsDragging] = useState(false)
    const inputRef = useRef(null)
    const uploading = uploadingCount > 0

    const doUpload = useCallback(async (file) => {
        if (!file) return
        setError(null)
        setUploadingCount((c) => {
            const next = c + 1
            onUploadingChange?.(1)
            return next
        })
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
            if (result?.status === 'failed') {
                const errMsg = typeof result.error === 'string' ? result.error : result.error?.message
                throw new Error(errMsg || 'Upload failed')
            }
            const assetId = result?.asset_id ?? result?.id
            if (assetId) onUploadComplete?.(assetId, { filename: file.name })
        } catch (e) {
            setError(e.message || 'Upload failed')
        } finally {
            setUploadingCount((c) => {
                const next = Math.max(0, c - 1)
                onUploadingChange?.(-1)
                return next
            })
            setPendingNames((prev) => {
                const idx = prev.indexOf(file.name)
                if (idx === -1) return prev
                return [...prev.slice(0, idx), ...prev.slice(idx + 1)]
            })
        }
    }, [brandId, builderContext, onUploadComplete, onUploadingChange])

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
            {error && (
                <div className="mt-3 flex items-start gap-2 rounded-lg bg-red-500/20 border border-red-500/40 p-3">
                    <p className="flex-1 text-sm text-red-200">{error}</p>
                    <button type="button" onClick={() => setError(null)} className="text-red-300 hover:text-red-100 text-xs underline">
                        Dismiss
                    </button>
                </div>
            )}
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
        { label: 'Archetype', value: unwrapValue(personality.primary_archetype) || (personality.candidate_archetypes || []).join(', ') || '—' },
        { label: 'Why', value: truncate(unwrapValue(identity.mission)) },
        { label: 'What', value: truncate(unwrapValue(identity.positioning)) },
        { label: 'Brand Look', value: truncate(personality.brand_look) },
        { label: 'Brand Voice', value: truncate(personality.voice_description) },
        { label: 'Tone & Traits', value: ((scoringRules.tone_keywords || []).length + (personality.traits || []).length) > 0 ? `${(scoringRules.tone_keywords || []).length} tone keywords, ${(personality.traits || []).length} traits` : '—' },
        { label: 'Positioning', value: identity.industry || identity.target_audience ? `${identity.industry || '—'} / ${identity.target_audience || '—'}` : '—' },
        { label: 'Beliefs & Values', value: [...(identity.beliefs || []), ...(identity.values || [])].length ? `${(identity.beliefs || []).length} beliefs, ${(identity.values || []).length} values` : '—' },
        { label: 'Typography', value: unwrapValue(typography.primary_font) ? `${unwrapValue(typography.primary_font)} / ${typography.secondary_font || '—'}` : '—' },
        { label: 'Color Palette', value: (unwrapValue(scoringRules.allowed_color_palette) || []).length ? `${(unwrapValue(scoringRules.allowed_color_palette) || []).length} colors` : (brandColors?.primary ? 'Brand colors set' : '—') },
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
    ingestionProcessing = false,
    ingestionRecords = [],
    latestSnapshot = null,
    latestSuggestions = {},
    latestSnapshotLite = null,
    latestCoherence = null,
    latestAlignment = null,
    insightState = { dismissed: [], accepted: [] },
    brandMaterialCount: initialBrandMaterialCount = 0,
    brandMaterials: initialBrandMaterials = [],
    visualReferences: initialVisualReferences = [],
    guidelinesPdfAssetId = null,
    guidelinesPdfFilename = null,
    overallStatus: initialOverallStatus = 'pending',
    researchFinalized = false,
    pipelineStatus = {},
    isLocal = false,
}) {
    const { auth } = usePage().props

    // Sync active brand to match the brand being edited — avoids confusion when nav shows a different brand
    useEffect(() => {
        if (brand?.id && auth?.activeBrand?.id && brand.id !== auth.activeBrand.id) {
            router.post(`/app/brands/${brand.id}/switch`, {}, {
                preserveScroll: true,
                preserveState: true,
            })
        }
    }, [brand?.id, auth?.activeBrand?.id])

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
    const [showStartOverConfirm, setShowStartOverConfirm] = useState(false)
    const [researchPolling, setResearchPolling] = useState(false)
    const [ingestionPolling, setIngestionPolling] = useState(ingestionProcessing ?? false)
    const [pdfExtractionPolling, setPdfExtractionPolling] = useState(false)
    const [backgroundUploadingCount, setBackgroundUploadingCount] = useState(0)
    const [pdfAttachedThisSession, setPdfAttachedThisSession] = useState(!!guidelinesPdfAssetId)
    const [polledResearch, setPolledResearch] = useState(null)
    const [showResearchReadyToast, setShowResearchReadyToast] = useState(false)
    const prevResearchFinalizedRef = useRef(null)

    useEffect(() => {
        setBrandMaterials(initialBrandMaterials ?? [])
        setBrandMaterialCount(initialBrandMaterialCount ?? 0)
        setVisualReferences(initialVisualReferences ?? [])
    }, [initialBrandMaterialCount, initialBrandMaterials, initialVisualReferences])

    useEffect(() => {
        if (guidelinesPdfAssetId) setPdfAttachedThisSession(true)
    }, [guidelinesPdfAssetId])

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
    const hasProcessingEarly = pdfExtractionPolling || ingestionProcessing || ingestionPolling || (researchPolling || (polledResearch?.crawlerRunning ?? crawlerRunning))
    // Always include research-summary as step 2 (after background). Include 'processing' only when actively processing.
    const allStepKeys = [
        stepKeys[0],
        ...(hasProcessingEarly ? ['processing'] : []),
        'research-summary',
        ...stepKeys.slice(1),
        REVIEW_STEP,
    ]
    const effectiveStep = viewingReview ? REVIEW_STEP : currentStep
    const stepIndex = allStepKeys.indexOf(effectiveStep)
    const currentStepConfig = steps.find((s) => s.key === currentStep)
    const isReviewStep = viewingReview
    const isLastDataStep = currentStep === stepKeys[stepKeys.length - 1] && !viewingReview

    // UI-only fallback for preview; never write back or send in payload
    const displayPrimary = brand.primary_color ?? '#6366f1'
    const displaySecondary = brand.secondary_color ?? '#8b5cf6'
    const displayAccent = brand.accent_color ?? '#06b6d4'

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
            // Interstitial steps (processing, research-summary) have no savable payload — do not patch
            const interstitialSteps = ['processing', 'research-summary']
            const stepToPatch = isReviewStep ? null : (interstitialSteps.includes(currentStep) ? null : currentStep)
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
        } else if (currentStep === 'background') {
            // If items are processing, go to processing step. Else go to research-summary (always step 2).
            if (hasProcessingEarly) {
                patchAndNavigate('processing')
            } else {
                patchAndNavigate('research-summary')
            }
        } else if (currentStep === 'processing') {
            // Processing step: Next goes to research-summary
            patchAndNavigate('research-summary')
        } else if (currentStep === 'research-summary') {
            // Research summary: Next goes to archetype
            patchAndNavigate(stepKeys[stepKeys.indexOf('archetype')])
        } else {
            patchAndNavigate(stepKeys[stepKeys.indexOf(currentStep) + 1])
        }
    }, [isReviewStep, isLastDataStep, currentStep, stepKeys, patchAndNavigate, REVIEW_STEP, hasProcessingEarly])

    const handleBack = useCallback(() => {
        if (viewingReview) {
            setViewingReview(false)
            return
        }
        if (currentStep === 'processing') {
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: 'background' }))
            return
        }
        if (currentStep === 'research-summary') {
            const prevIdx = allStepKeys.indexOf('research-summary') - 1
            const prevStep = prevIdx >= 0 ? allStepKeys[prevIdx] : 'background'
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: prevStep }))
            return
        }
        const idx = allStepKeys.indexOf(currentStep)
        if (idx <= 0) return
        router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: allStepKeys[idx - 1] }))
    }, [viewingReview, currentStep, allStepKeys, brand.id])

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

    const effectiveOverallStatus = polledResearch?.overall_status ?? initialOverallStatus

    const triggerIngestion = useCallback(async (opts = {}) => {
        try {
            await axios.post(route('brands.brand-dna.builder.trigger-ingestion', { brand: brand.id }), {
                pdf_asset_id: opts.pdf_asset_id || null,
                website_url: opts.website_url || (payload.sources?.website_url || '').trim() || null,
                material_asset_ids: opts.material_asset_ids || undefined,
            })
            setIngestionPolling(true)
        } catch (e) {
            if (e.response?.status !== 422) {
                setErrors((prev) => [...prev, e.response?.data?.error || 'Failed to start processing'])
            }
        }
    }, [brand.id, payload.sources?.website_url])

    const handleAnalyzeAll = useCallback(async () => {
        const websiteUrl = (payload.sources?.website_url || '').trim()
        const socialUrls = (payload.sources?.social_urls || []).filter((u) => typeof u === 'string' && u.trim().startsWith('http'))
        const urls = [...(websiteUrl ? [websiteUrl] : []), ...socialUrls]
        if (urls.length === 0) return
        try {
            for (const url of urls) {
                await axios.post(route('brands.brand-dna.builder.trigger-research', { brand: brand.id }), { url })
            }
            setResearchPolling(true)
        } catch {}
    }, [payload.sources?.website_url, payload.sources?.social_urls, brand.id])

    const [brandMaterialFeedback, setBrandMaterialFeedback] = useState(null)
    useEffect(() => {
        if (!brandMaterialFeedback) return
        const t = setTimeout(() => setBrandMaterialFeedback(null), 4000)
        return () => clearTimeout(t)
    }, [brandMaterialFeedback])

    const handleBrandMaterialUploadComplete = useCallback(async (assetId, meta) => {
        if (!assetId) return
        try {
            const res = await axios.post(route('brands.brand-dna.builder.attach-asset', { brand: brand.id }), {
                asset_id: assetId,
                builder_context: 'brand_material',
            })
            const count = res.data?.count ?? 0
            setBrandMaterialCount(count)
            setBrandMaterials((prev) => [...prev, { id: assetId, title: meta?.filename || 'Asset', original_filename: meta?.filename || 'Uploaded', thumbnail_url: null, signed_url: null }])
            setBrandMaterialFeedback(`Uploaded successfully. ${count} material${count !== 1 ? 's' : ''} added. Processing…`)
            setIngestionPolling(true)
            await triggerIngestion({})
        } catch (e) {
            setBrandMaterialFeedback(e.response?.data?.message || 'Upload failed')
        }
    }, [brand.id, triggerIngestion])

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
                setBrandMaterialFeedback(`Added successfully. ${count} material${count !== 1 ? 's' : ''} total. Processing…`)
                setIngestionPolling(true)
                await triggerIngestion({})
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
        } catch (e) {
            if (context === 'brand_material') {
                setBrandMaterialFeedback(e.response?.data?.message || 'Failed to add')
            }
        }
    }, [brand.id, triggerIngestion])

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

    // Poll research + ingestion status when processing, or when on processing step
    useEffect(() => {
        const shouldPoll = (researchPolling || ingestionPolling || pdfExtractionPolling || currentStep === 'processing') && brand?.id
        if (!shouldPoll) return
        const poll = async () => {
            try {
                const res = await axios.get(route('brands.brand-dna.builder.research-insights', { brand: brand.id }))
                const data = res.data
                setPolledResearch(data)
                if (researchPolling && !data?.crawlerRunning && data?.latestSnapshotLite) {
                    setResearchPolling(false)
                }
                if (ingestionPolling && !data?.ingestionProcessing) {
                    setIngestionPolling(false)
                }
                if (pdfExtractionPolling && data?.pdf?.status === 'completed') {
                    setPdfExtractionPolling(false)
                }
            } catch {
                setResearchPolling(false)
                setIngestionPolling(false)
            }
        }
        poll()
        const id = setInterval(poll, 2000)
        return () => clearInterval(id)
    }, [researchPolling, ingestionPolling, pdfExtractionPolling, currentStep, brand?.id])

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

    const CONFIDENCE_SAFE_APPLY = 0.75

    const handleApplySafeSuggestions = useCallback(async ({ snapshot, suggestions }) => {
        setSaving(true)
        setErrors([])
        try {
            const sug = suggestions || {}
            const identityUpdates = { ...(payload.identity || {}) }
            let identityChanged = false
            let archetypeApplied = false
            const standardsPayload = {}
            const patches = []

            const items = sug.items || []
            for (const item of items) {
                const conf = item.confidence ?? 0
                if (conf < CONFIDENCE_SAFE_APPLY) continue
                const path = item.path
                const value = item.value
                if (!path || value === undefined) continue

                const parts = path.split('.')
                const section = parts[0]
                const key = parts.slice(1).join('.') || parts[0]
                if (!section || !key) continue

                let current = payload[section]
                if (section && key) current = current?.[key]
                const isEmpty = current === undefined || current === null || current === '' || (Array.isArray(current) && current.length === 0)
                if (!isEmpty) continue

                if (section === 'personality' && key === 'primary_archetype') {
                    const archVal = typeof value === 'string' ? value : (value?.label ?? value?.value ?? value)
                    if (archVal) {
                        patches.push({ step_key: 'archetype', payload: { personality: { ...(payload.personality || {}), primary_archetype: archVal } } })
                        archetypeApplied = true
                    }
                } else if (section === 'identity') {
                    if (key === 'mission' && !identityUpdates.mission) { identityUpdates.mission = value; identityChanged = true }
                    else if (key === 'positioning' && !identityUpdates.positioning) { identityUpdates.positioning = value; identityChanged = true }
                } else if (section === 'scoring_rules') {
                    if (key === 'allowed_color_palette') {
                        const palette = Array.isArray(value) ? value.map((c) => (typeof c === 'object' && c?.hex ? { hex: c.hex, role: null } : { hex: String(c), role: null })) : []
                        if (palette.length > 0) {
                            standardsPayload.scoring_rules = { ...(standardsPayload.scoring_rules || payload.scoring_rules || {}), allowed_color_palette: palette }
                        }
                    } else if (key === 'tone_keywords') {
                        const keywords = Array.isArray(value) ? value : [value]
                        if (keywords.length > 0) {
                            standardsPayload.scoring_rules = { ...(standardsPayload.scoring_rules || payload.scoring_rules || {}), tone_keywords: keywords }
                        }
                    }
                } else if (section === 'typography' && key === 'primary_font') {
                    standardsPayload.typography = { ...(standardsPayload.typography || payload.typography || {}), primary_font: value }
                }
            }

            if (!archetypeApplied) {
                const arch = sug.recommended_archetypes?.[0]
                const archVal = typeof arch === 'string' ? arch : (arch?.label ?? arch?.archetype ?? arch)
                if (archVal && !unwrapValue(payload.personality?.primary_archetype)) {
                    patches.push({ step_key: 'archetype', payload: { personality: { ...(payload.personality || {}), primary_archetype: archVal } } })
                }
            }
            if (sug.mission_suggestion && !identityUpdates.mission) { identityUpdates.mission = sug.mission_suggestion; identityChanged = true }
            if (sug.positioning_suggestion && !identityUpdates.positioning) { identityUpdates.positioning = sug.positioning_suggestion; identityChanged = true }
            if (identityChanged) {
                patches.push({ step_key: 'purpose_promise', payload: { identity: identityUpdates } })
            }

            const palette = unwrapValue(payload.scoring_rules?.allowed_color_palette) || []
            const colors = snapshot?.primary_colors
            if (Array.isArray(colors) && colors.length > 0 && !(Array.isArray(palette) && palette.length > 0) && !standardsPayload.scoring_rules?.allowed_color_palette) {
                const hexColors = colors.map((c) => (typeof c === 'string' ? c : c?.hex ?? c)).filter(Boolean)
                if (hexColors.length) {
                    standardsPayload.scoring_rules = { ...(standardsPayload.scoring_rules || payload.scoring_rules || {}), allowed_color_palette: hexColors.map((hex) => ({ hex, role: null })) }
                }
            }
            const fonts = snapshot?.detected_fonts
            if (Array.isArray(fonts) && fonts.length > 0 && !unwrapValue(payload.typography?.primary_font) && !standardsPayload.typography?.primary_font) {
                standardsPayload.typography = { ...(standardsPayload.typography || payload.typography || {}), primary_font: fonts[0] }
            }
            if (Object.keys(standardsPayload).length > 0) {
                patches.push({ step_key: 'standards', payload: standardsPayload })
            }

            for (const { step_key, payload: p } of patches) {
                await axios.post(route('brands.brand-dna.builder.patch', { brand: brand.id }), { step_key, payload: p })
            }
            if (patches.length > 0) {
                setPayload((prev) => {
                    let next = { ...prev }
                    for (const { payload: p } of patches) {
                        if (p.personality) next.personality = { ...(next.personality || {}), ...p.personality }
                        if (p.identity) next.identity = { ...(next.identity || {}), ...p.identity }
                        if (p.scoring_rules) next.scoring_rules = { ...(next.scoring_rules || {}), ...p.scoring_rules }
                        if (p.typography) next.typography = { ...(next.typography || {}), ...p.typography }
                    }
                    return next
                })
                setSavedAt(Date.now())
            }
        } catch (e) {
            setErrors([e.response?.data?.message || 'Failed to apply suggestions'])
        } finally {
            setSaving(false)
        }
    }, [brand.id, payload])

    const effectiveCrawlerRunning = researchPolling || (polledResearch?.crawlerRunning ?? crawlerRunning)
    const hasProcessing = pdfExtractionPolling || ingestionProcessing || ingestionPolling || effectiveCrawlerRunning
    const isProcessing = hasProcessing

    // When on processing step and overall_status becomes completed, redirect to research-summary
    useEffect(() => {
        if (currentStep === 'processing' && effectiveOverallStatus === 'completed') {
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: 'research-summary' }))
        }
    }, [currentStep, effectiveOverallStatus, brand.id])

    // When on research-summary but processing not complete (e.g. page data not ready), redirect back to processing
    useEffect(() => {
        if (currentStep === 'research-summary' && effectiveOverallStatus !== 'completed') {
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: 'processing' }))
        }
    }, [currentStep, effectiveOverallStatus, brand.id])

    const effectiveSnapshotLite = polledResearch?.latestSnapshotLite ?? latestSnapshotLite
    const effectiveCoherence = polledResearch?.latestCoherence ?? latestCoherence
    const effectiveAlignment = polledResearch?.latestAlignment ?? latestAlignment
    const effectiveSuggestions = polledResearch?.latestSuggestions ?? latestSuggestions
    const hasResearchData = researchPolling || effectiveCrawlerRunning || effectiveSnapshotLite || effectiveCoherence || effectiveAlignment || Object.keys(effectiveSuggestions || {}).length > 0

    const effectiveResearchFinalized = polledResearch?.researchFinalized ?? researchFinalized ?? null

    // Show toast only when user has navigated away from the builder. Do NOT show when on any builder step (user is already in context).
    // When on the builder, they see inline CTAs (ProcessingProgressPanel, research-summary) instead.
    useEffect(() => {
        // Never show toast when on the brand guidelines builder — only show when user is on a different page.
        // Since this component only mounts when on the builder, we never show the toast here.
        prevResearchFinalizedRef.current = effectiveResearchFinalized
    }, [effectiveResearchFinalized, currentStep])

    const canProceedFromResearch = canProceedFromResearchSummary(
        polledResearch,
        effectiveSnapshotLite,
        latestSnapshot ?? [],
        ingestionProcessing || ingestionPolling,
        effectiveResearchFinalized
    )

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
                    background: `linear-gradient(160deg, #0f0e14 0%, ${displayPrimary}15 40%, #0f0e14 100%)`,
                }}
            />
            <div
                className="absolute inset-0 opacity-[0.03] pointer-events-none"
                style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                }}
            />

            <AppHead title={`Brand Guidelines Builder — ${brand.name}`} />
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="relative z-10 flex-1 flex flex-col min-h-0">
                <header className="flex-shrink-0 px-4 sm:px-8 pt-6 pb-4">
                    <div className="flex items-center justify-between gap-4">
                        <Link
                            href={route('brands.guidelines.index', { brand: brand.id })}
                            className="text-sm font-medium text-white/60 hover:text-white/90"
                        >
                            ← Back to Guidelines
                        </Link>
                        <span className="text-sm font-medium text-white/80 truncate max-w-[200px] sm:max-w-xs" title={brand.name}>
                            {brand.name}
                        </span>
                        <button
                            type="button"
                            onClick={() => setShowStartOverConfirm(true)}
                            className="text-sm font-medium text-white/50 hover:text-white/80"
                        >
                            Start over
                        </button>
                        <ConfirmDialog
                            open={showStartOverConfirm}
                            onClose={() => setShowStartOverConfirm(false)}
                            onConfirm={() => {
                                setShowStartOverConfirm(false)
                                router.post(route('brands.brand-dna.builder.start', { brand: brand.id }))
                            }}
                            title="Start over"
                            message="Your current draft will be replaced with a fresh one. This cannot be undone."
                            confirmText="Start over"
                            cancelText="Cancel"
                            variant="warning"
                        />
                    </div>
                    <div className="mt-4">
                        <ProgressRail steps={steps} stepKeys={allStepKeys} currentStep={effectiveStep} accentColor={displayAccent} />
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
                                        style={{ backgroundColor: displayPrimary }}
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
                                <StepShell
                                    title={currentStep === 'processing' ? 'Processing your Brand Guidelines' : currentStep === 'research-summary' ? 'Research Summary' : currentStepConfig?.title}
                                    description={currentStep === 'processing' ? 'Extracting data to fill and suggest the next steps.' : currentStep === 'research-summary' ? 'Review what the system discovered before editing brand fields.' : currentStepConfig?.description}
                                >
                                    {/* Processing status — show on non-Background, non-Processing steps */}
                                    {/* Brand Guidelines PDF summary — show on non-Background, non-Processing steps when PDF was uploaded */}
                                    {currentStep !== 'background' && currentStep !== 'processing' && guidelinesPdfAssetId && (
                                        <div className="mb-6 rounded-xl border border-white/20 bg-white/5 px-4 py-3 flex items-center gap-3">
                                            <div className="flex-shrink-0 w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center">
                                                <svg className="w-4 h-4 text-white/70" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium text-white/90 truncate" title={guidelinesPdfFilename || 'Brand Guidelines PDF'}>
                                                    {guidelinesPdfFilename || 'Brand Guidelines PDF'}
                                                </p>
                                                <p className="text-xs text-white/50">Imported from Background step</p>
                                            </div>
                                        </div>
                                    )}
                                    {currentStep === 'research-summary' && (
                                        <ResearchSummary
                                            brandId={brand.id}
                                            polledResearch={polledResearch}
                                            initialSnapshot={latestSnapshot ?? {}}
                                            initialSuggestions={latestSuggestions ?? {}}
                                            modelPayload={payload}
                                            initialCoherence={latestCoherence}
                                            initialAlignment={latestAlignment}
                                            initialInsightState={insightState}
                                            ingestionProcessing={ingestionProcessing || ingestionPolling}
                                            researchFinalized={researchFinalized}
                                            onApplySuggestion={handleApplySuggestion}
                                            onDismissInsight={handleDismissInsight}
                                            onApplySafeSuggestions={handleApplySafeSuggestions}
                                            accentColor={displayAccent}
                                            isLocal={isLocal}
                                        />
                                    )}
                                    {currentStep === 'processing' && (
                                        polledResearch?.processing_progress ? (
                                            <ProcessingProgressPanel
                                                processingProgress={polledResearch.processing_progress}
                                                accentColor={displayAccent || brand?.accent_color || '#06b6d4'}
                                                brandId={brand?.id}
                                                researchFinalized={effectiveResearchFinalized === true}
                                                ingestionProcessing={polledResearch?.ingestionProcessing ?? ingestionProcessing ?? ingestionPolling}
                                            />
                                        ) : (
                                            <ProcessingView
                                                pdfExtractionPolling={pdfExtractionPolling}
                                                ingestionProcessing={ingestionProcessing || ingestionPolling}
                                                ingestionRecords={polledResearch?.ingestionRecords ?? ingestionRecords}
                                                crawlerRunning={effectiveCrawlerRunning}
                                                polledResearch={polledResearch}
                                                guidelinesPdfFilename={guidelinesPdfFilename}
                                                hasPdf={!!(guidelinesPdfAssetId || guidelinesPdfFilename)}
                                                hasWebsite={!!(sources?.website_url?.trim?.())}
                                                hasSocial={!!(sources?.social_urls?.length)}
                                                hasMaterials={brandMaterialCount > 0}
                                            />
                                        )
                                    )}
                                    {currentStep === 'background' && (
                                        <div className="space-y-8">
                                            {hasProcessingEarly && (
                                                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200/90">
                                                    Processing in progress. Click Next to see progress — you can continue once extraction is complete.
                                                </div>
                                            )}
                                            <>
                                            {/* 1. Import Official Brand Guidelines (PDF) */}
                                            <PdfGuidelinesUploadCard
                                                brandId={brand.id}
                                                accentColor={displayAccent}
                                                payload={payload}
                                                setPayload={setPayload}
                                                setBrandColors={setBrandColors}
                                                saving={saving}
                                                setErrors={setErrors}
                                                onTriggerIngestion={triggerIngestion}
                                                onExtractionPollingChange={setPdfExtractionPolling}
                                                onPdfUploadingChange={(delta) => setBackgroundUploadingCount((c) => Math.max(0, c + delta))}
                                                onPdfAttached={() => setPdfAttachedThisSession(true)}
                                                initialPdfAssetId={guidelinesPdfAssetId}
                                                initialPdfFilename={guidelinesPdfFilename}
                                            />

                                            {/* 2. Website & Social */}
                                            <FieldCard title="Website & Social">
                                                <div className="space-y-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Website URL</label>
                                                        <input
                                                            type="url"
                                                            value={sources.website_url || ''}
                                                            onChange={(e) => updatePayload('sources', 'website_url', e.target.value)}
                                                            placeholder="https://yoursite.com"
                                                            disabled={effectiveCrawlerRunning}
                                                            className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 focus:border-white/30 disabled:opacity-60 disabled:cursor-not-allowed"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Social URLs</label>
                                                        <ChipInput
                                                            value={sources.social_urls || []}
                                                            onChange={(v) => updatePayload('sources', 'social_urls', v)}
                                                            placeholder="Paste URL and press Enter"
                                                            disabled={effectiveCrawlerRunning}
                                                        />
                                                    </div>
                                                    <div className="pt-2">
                                                        <button
                                                            type="button"
                                                            disabled={effectiveCrawlerRunning || (!(sources.website_url || '').trim() && (sources.social_urls || []).length === 0)}
                                                            onClick={handleAnalyzeAll}
                                                            className="w-full sm:w-auto px-6 py-3 rounded-xl font-medium text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                                            style={{ backgroundColor: effectiveCrawlerRunning ? 'rgba(99, 102, 241, 0.5)' : (displayAccent || '#6366f1') }}
                                                        >
                                                            {effectiveCrawlerRunning ? 'Analyzing…' : 'Analyze'}
                                                        </button>
                                                        {effectiveCrawlerRunning && (
                                                            <>
                                                                <div className="mt-3 space-y-1.5">
                                                                    <div className="flex items-center justify-between text-sm">
                                                                        <span className="text-amber-200">Analyzing website & social links</span>
                                                                    </div>
                                                                    <div className="h-1.5 rounded-full bg-white/10 overflow-hidden">
                                                                        <motion.div
                                                                            className="h-full rounded-full bg-amber-400"
                                                                            initial={{ width: '0%' }}
                                                                            animate={{ width: ['0%', '70%', '100%', '70%'] }}
                                                                            transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
                                                                        />
                                                                    </div>
                                                                </div>
                                                                <p className="mt-2 text-sm text-indigo-300">
                                                                    Crawling website and social links… Results will appear in Research Insights when you continue to the next step.
                                                                </p>
                                                                {(polledResearch?.runningSnapshotLite?.source_url || polledResearch?.latestSnapshotLite?.source_url) && (
                                                                    <p className="mt-1 text-sm text-white/70">
                                                                        {polledResearch?.runningSnapshotLite?.source_url || polledResearch?.latestSnapshotLite?.source_url}
                                                                    </p>
                                                                )}
                                                            </>
                                                        )}
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
                                                    onUploadingChange={(delta) => setBackgroundUploadingCount((c) => Math.max(0, c + delta))}
                                                    label="Or upload brand materials (optional)"
                                                    count={brandMaterialCount}
                                                    accept="image/*,.pdf"
                                                />
                                            </FieldCard>
                                            </>
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
                                                <div className="space-y-4">
                                                    {isAiPopulated(personality.primary_archetype) && (
                                                        <div className="flex items-center gap-2">
                                                            <AiFieldBadge field={personality.primary_archetype} />
                                                        </div>
                                                    )}
                                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                                                    {ARCHETYPES.map((a) => {
                                                        const primary = unwrapValue(personality.primary_archetype)
                                                        const selected = [primary, ...(personality.candidate_archetypes || [])].filter(Boolean)
                                                        const isSelected = selected.includes(a.id)
                                                        const isSelectedFirst = primary === a.id
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
                                                </div>
                                            ) : (
                                                /* Avenue 2: Apply / Doesn't apply — two groupings + hero cards */
                                                <div className="space-y-6">
                                                    <p className="text-white/70 text-sm">Click each archetype to add it to &quot;Applies to us&quot; (up to 2) or &quot;Doesn&apos;t apply&quot;.</p>
                                                    <div className="grid md:grid-cols-2 gap-6">
                                                        <div className="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4">
                                                            <h4 className="text-sm font-semibold text-emerald-300 mb-3">Applies to us</h4>
                                                            <div className="space-y-2 min-h-[100px]">
                                                                {[unwrapValue(personality.primary_archetype), ...(personality.candidate_archetypes || [])].filter(Boolean).map((id) => {
                                                                    const a = ARCHETYPES.find((x) => x.id === id)
                                                                    if (!a) return null
                                                                    return (
                                                                        <div key={a.id} className="flex items-center justify-between rounded-lg bg-white/10 px-3 py-2">
                                                                            <span className="text-white font-medium">{a.id}</span>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    const selected = [unwrapValue(personality.primary_archetype), ...(personality.candidate_archetypes || [])].filter(Boolean)
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
                                                                const selected = [unwrapValue(personality.primary_archetype), ...(personality.candidate_archetypes || [])].filter(Boolean)
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
                                                                                const selected = [unwrapValue(personality.primary_archetype), ...(personality.candidate_archetypes || [])].filter(Boolean)
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
                                                                            disabled={[unwrapValue(personality.primary_archetype), ...(personality.candidate_archetypes || [])].filter(Boolean).length >= 2}
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
                                                {isAiPopulated(identity.mission) && <AiFieldBadge field={identity.mission} className="mb-2" />}
                                                <textarea
                                                    value={unwrapValue(identity.mission) || ''}
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
                                                {isAiPopulated(identity.positioning) && <AiFieldBadge field={identity.positioning} className="mb-2" />}
                                                <textarea
                                                    value={unwrapValue(identity.positioning) || ''}
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
                                            {unwrapValue(personality.primary_archetype) && ARCHETYPE_RECOMMENDED_TRAITS[unwrapValue(personality.primary_archetype)] && !dismissedInlineSuggestions.includes('expression:traits') && (
                                                <InlineSuggestionBlock
                                                    title={`Recommended traits for ${unwrapValue(personality.primary_archetype)}`}
                                                    items={ARCHETYPE_RECOMMENDED_TRAITS[unwrapValue(personality.primary_archetype)] || []}
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
                                                    value={(unwrapValue(scoringRules.tone_keywords) || personality.tone_keywords || []).filter(Boolean)}
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
                                                    {isAiPopulated(typography.primary_font) && <AiFieldBadge field={typography.primary_font} className="mb-2" />}
                                                    <FontListbox
                                                        label="Primary Font"
                                                        value={unwrapValue(typography.primary_font) || ''}
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
                                                {isAiPopulated(scoringRules.allowed_color_palette) && <AiFieldBadge field={scoringRules.allowed_color_palette} className="mb-2" />}
                                                <ColorPaletteChipInput
                                                    value={(unwrapValue(scoringRules.allowed_color_palette) || []).map((c) => (typeof c === 'object' && c?.hex) || (typeof c === 'string' ? c : '')).filter(Boolean)}
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
                    {hasResearchData && currentStep !== 'background' && currentStep !== 'processing' && (
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
                                isLocal={isLocal}
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
                                <>
                                    {currentStep === 'background' && backgroundUploadingCount > 0 && !(guidelinesPdfAssetId || pdfAttachedThisSession) && (
                                        <span className="text-sm text-amber-200/90">Wait for upload to finish</span>
                                    )}
                                    {currentStep === 'processing' && effectiveOverallStatus !== 'completed' && (
                                        <span className="text-sm text-amber-200/90">Wait for processing to finish</span>
                                    )}
                                    {currentStep === 'research-summary' && !canProceedFromResearch && (
                                        <span className="text-sm text-amber-200/90">Review results before continuing</span>
                                    )}
                                    {(() => {
                                        const backgroundBlocked = currentStep === 'background' && backgroundUploadingCount > 0 && !(guidelinesPdfAssetId || pdfAttachedThisSession)
                                        const processingBlocked = currentStep === 'processing' && effectiveOverallStatus !== 'completed'
                                        const researchBlocked = currentStep === 'research-summary' && !canProceedFromResearch
                                        const isDisabled = saving || backgroundBlocked || processingBlocked || researchBlocked
                                        return (
                                            <button
                                                type="button"
                                                onClick={handleNext}
                                                disabled={isDisabled}
                                                className="px-6 py-2.5 rounded-xl font-medium text-white transition-colors disabled:opacity-50"
                                                style={{ backgroundColor: isDisabled ? '#4b5563' : displayAccent }}
                                            >
                                                {saving ? 'Saving…' : backgroundBlocked ? 'Uploading…' : processingBlocked ? 'Processing…' : researchBlocked ? 'Waiting…' : isLastDataStep ? 'Review' : 'Next'}
                                            </button>
                                        )
                                    })()}
                                </>
                            )}
                        </div>
                    </div>
                </footer>

                <BrandResearchReadyToast
                    visible={showResearchReadyToast}
                    onDismiss={() => setShowResearchReadyToast(false)}
                    brandId={brand?.id}
                    accentColor={displayAccent || brand?.accent_color || '#06b6d4'}
                />
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

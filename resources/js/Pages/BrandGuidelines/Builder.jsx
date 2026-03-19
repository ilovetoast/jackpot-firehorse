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
import FontManager from '../../Components/BrandGuidelines/FontManager'
import BuilderAssetSelectorModal from '../../Components/BrandGuidelines/BuilderAssetSelectorModal'
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
                    {typeof item === 'string' ? item : (item?.value ?? String(item))}
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
function ProcessingView({ pdfExtractionPolling, ingestionProcessing, ingestionRecords, crawlerRunning, polledResearch, guidelinesPdfFilename, hasPdf = false, hasWebsite = false, hasSocial = false, hasMaterials = false, brandId, onRetry, onStartOver }) {
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

    const pipelineError = polledResearch?.pipeline_error ?? null
    const pipelineErrorKind = polledResearch?.pipeline_error_kind ?? null
    const canRetry = polledResearch?.can_retry ?? false
    const hasError = !!pipelineError
    const [retrying, setRetrying] = useState(false)

    const handleRetry = async () => {
        if (!brandId || retrying) return
        setRetrying(true)
        try {
            await axios.post(route('brands.brand-dna.builder.retry-pipeline', { brand: brandId }))
        } catch (err) {
            console.error('[ProcessingView] Retry failed:', err)
        } finally {
            setTimeout(() => setRetrying(false), 2000)
        }
    }

    if (hasError && sourceItems.length > 0) {
        sourceItems.forEach((item) => {
            if (item.status === 'processing' || item.status === 'pending') {
                if (pipelineErrorKind === 'stuck') {
                    item.status = 'failed'
                    item.detail = 'Stalled — no progress detected'
                } else if (pipelineErrorKind === 'failed') {
                    item.status = 'failed'
                    item.detail = 'Failed'
                }
            }
        })
    }

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-8 w-full">
            {hasError ? (
                <div className="mb-6 rounded-xl border border-red-500/30 bg-red-500/10 p-5">
                    <div className="flex items-start gap-3">
                        <svg className="w-5 h-5 text-red-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                        <div className="flex-1">
                            <p className="text-sm font-medium text-red-300">
                                {pipelineErrorKind === 'stuck' ? 'Processing appears stuck' : 'Processing encountered an error'}
                            </p>
                            <p className="text-xs text-red-300/70 mt-1">{pipelineError}</p>
                            {canRetry && (
                                <div className="flex gap-3 mt-4">
                                    <button
                                        onClick={handleRetry}
                                        disabled={retrying}
                                        className="inline-flex items-center gap-2 rounded-lg bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2 text-sm font-medium text-white transition disabled:opacity-50"
                                    >
                                        {retrying ? (
                                            <>
                                                <motion.div className="w-3.5 h-3.5 rounded-full border-2 border-white/30 border-t-white" animate={{ rotate: 360 }} transition={{ duration: 0.8, repeat: Infinity, ease: 'linear' }} />
                                                Retrying…
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" /></svg>
                                                Retry Processing
                                            </>
                                        )}
                                    </button>
                                    {onStartOver && (
                                        <button
                                            onClick={onStartOver}
                                            className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-4 py-2 text-sm text-white/60 hover:text-white/80 hover:border-white/20 transition"
                                        >
                                            Start Over
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    <p className="text-white/60 text-sm mb-2">
                        We&apos;re extracting data from your uploads to fill and suggest content for the next steps.
                    </p>
                    <p className="text-white/50 text-sm mb-6">
                        This may take a few minutes — we&apos;re using AI to extract text and analyze images. You can leave and return; progress is saved.
                    </p>
                </>
            )}
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
            {!hasError && (
                <p className="mt-8 text-sm text-white/50">
                    You cannot proceed to the next step until processing is complete.
                </p>
            )}
        </div>
    )
}

// ——— ProcessingStatusPanel ———
// Compact panel for non-Background steps. On Background step when processing, ProcessingView is shown instead.
function ProcessingStatusPanel({ pdfExtractionPolling, ingestionProcessing, ingestionRecords, crawlerRunning, polledResearch, guidelinesPdfFilename, currentStep, brandId }) {
    const hasIngestion = ingestionProcessing || (polledResearch?.ingestionProcessing ?? false)
    const effectiveCrawler = crawlerRunning || (polledResearch?.crawlerRunning ?? false)
    const records = polledResearch?.ingestionRecords ?? ingestionRecords ?? []
    const hasErrors = records.some((r) => r.status === 'failed' || r.error)
    const showCrawlerInPanel = effectiveCrawler && currentStep !== 'background'
    const pipelineError = polledResearch?.pipeline_error ?? null
    const canRetry = polledResearch?.can_retry ?? false
    const [retrying, setRetrying] = useState(false)

    if (!pdfExtractionPolling && !hasIngestion && !showCrawlerInPanel && !hasErrors && !pipelineError && records.length === 0) return null

    const handleRetry = async () => {
        if (!brandId || retrying) return
        setRetrying(true)
        try {
            await axios.post(route('brands.brand-dna.builder.retry-pipeline', { brand: brandId }))
        } catch (err) {
            console.error('[ProcessingStatusPanel] Retry failed:', err)
        } finally {
            setTimeout(() => setRetrying(false), 2000)
        }
    }

    return (
        <div className={`mb-6 rounded-xl border ${pipelineError ? 'border-red-500/30 bg-red-500/5' : 'border-white/20 bg-white/5'} p-5`}>
            <h4 className="text-sm font-medium text-white/80 mb-3">{pipelineError ? 'Processing Issue' : 'Processing'}</h4>
            {pipelineError && (
                <div className="mb-3 flex items-start gap-2 text-sm text-red-300/80">
                    <svg className="w-4 h-4 mt-0.5 shrink-0 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>
                    <div className="flex-1">
                        <span>{pipelineError}</span>
                        {canRetry && (
                            <button onClick={handleRetry} disabled={retrying} className="ml-3 underline text-white/70 hover:text-white transition">
                                {retrying ? 'Retrying…' : 'Retry'}
                            </button>
                        )}
                    </div>
                </div>
            )}
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
                // Pipeline is started by ExtractPdfTextJob when guidelines_pdf; no frontend trigger needed.
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

// ——— Logo Usage Guidelines (Visual Proof Cards) ———
const DEFAULT_LOGO_GUIDELINES = {
    clear_space: 'Maintain a minimum clear space equal to the height of the logo mark on all sides.',
    minimum_size: 'The logo should never be displayed smaller than 24px in height on digital, or 0.5 inches in print.',
    color_usage: 'Use the primary brand color version on light backgrounds. Use the reversed (white) version on dark or busy backgrounds.',
    dont_stretch: 'Never stretch, compress, or distort the logo in any direction.',
    dont_rotate: 'Never rotate or tilt the logo at an angle.',
    dont_recolor: 'Never apply unapproved colors, gradients, or effects to the logo.',
    dont_crop: 'Never crop or partially obscure the logo.',
    dont_add_effects: 'Never add shadows, outlines, glows, or other visual effects to the logo.',
    background_contrast: 'Ensure sufficient contrast between the logo and its background. Avoid placing on busy imagery without a container.',
}

const GUIDELINE_CARDS = {
    clear_space: {
        label: 'Clear Space',
        category: 'do',
        render: (logoSrc) => (
            <div className="relative w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center">
                <div className="relative">
                    <div className="absolute inset-0 -m-5 border-2 border-dashed border-blue-400/50 rounded" />
                    <div className="absolute -top-5 left-1/2 -translate-x-1/2 flex flex-col items-center">
                        <div className="w-px h-4 bg-blue-400/60" />
                        <span className="text-[8px] text-blue-500 font-medium">x</span>
                    </div>
                    <div className="absolute -left-5 top-1/2 -translate-y-1/2 flex items-center">
                        <div className="h-px w-4 bg-blue-400/60" />
                        <span className="text-[8px] text-blue-500 font-medium ml-0.5">x</span>
                    </div>
                    <img src={logoSrc} alt="" className="h-10 max-w-[100px] object-contain" />
                </div>
            </div>
        ),
    },
    minimum_size: {
        label: 'Minimum Size',
        category: 'do',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-end justify-center gap-6 pb-4 px-4">
                <div className="flex flex-col items-center gap-1">
                    <img src={logoSrc} alt="" className="h-10 max-w-[80px] object-contain" />
                    <span className="text-[8px] text-gray-500 font-medium">Full size</span>
                </div>
                <div className="flex flex-col items-center gap-1">
                    <img src={logoSrc} alt="" className="h-5 max-w-[40px] object-contain" />
                    <span className="text-[8px] text-gray-500 font-medium">Min size</span>
                </div>
                <div className="flex flex-col items-center gap-1 opacity-30">
                    <img src={logoSrc} alt="" className="h-2.5 max-w-[20px] object-contain" />
                    <div className="flex items-center gap-0.5">
                        <span className="text-red-500 text-[10px]">✕</span>
                        <span className="text-[8px] text-red-500 font-medium">Too small</span>
                    </div>
                </div>
            </div>
        ),
    },
    color_usage: {
        label: 'Color Usage',
        category: 'do',
        render: (logoSrc, brandColors) => (
            <div className="w-full aspect-[3/2] rounded-lg overflow-hidden grid grid-cols-2">
                <div className="bg-white flex items-center justify-center p-3">
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain" />
                </div>
                <div className="flex items-center justify-center p-3" style={{ backgroundColor: brandColors?.primary || '#1a1a2e' }}>
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain brightness-0 invert" />
                </div>
                <div className="bg-gray-100 flex items-center justify-center p-3" style={{ backgroundColor: brandColors?.secondary || '#f0f0f0' }}>
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain" />
                </div>
                <div className="bg-gray-800 flex items-center justify-center p-3">
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain brightness-0 invert" />
                </div>
            </div>
        ),
    },
    background_contrast: {
        label: 'Background Contrast',
        category: 'do',
        render: (logoSrc, brandColors) => (
            <div className="w-full aspect-[3/2] rounded-lg overflow-hidden grid grid-cols-2">
                <div className="flex items-center justify-center p-3 relative" style={{ backgroundColor: brandColors?.primary || '#002A3A' }}>
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain brightness-0 invert relative z-10" />
                    <span className="absolute bottom-1 text-[8px] text-white/60 font-medium">✓ Good</span>
                </div>
                <div className="flex items-center justify-center p-3 relative bg-[repeating-conic-gradient(#e0e0e0_0%_25%,#fff_0%_50%)] bg-[length:16px_16px]">
                    <img src={logoSrc} alt="" className="h-8 max-w-[70px] object-contain opacity-40 relative z-10" />
                    <span className="absolute bottom-1 text-[8px] text-red-500 font-medium z-10">✕ Busy bg</span>
                </div>
            </div>
        ),
    },
    dont_stretch: {
        label: "Don't Stretch",
        category: 'dont',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center gap-4 px-4 relative">
                <div className="flex flex-col items-center gap-1">
                    <img src={logoSrc} alt="" className="h-8 max-w-[60px] object-contain" style={{ transform: 'scaleX(1.6)' }} />
                </div>
                <div className="flex flex-col items-center gap-1">
                    <img src={logoSrc} alt="" className="h-12 max-w-[30px] object-contain" style={{ transform: 'scaleY(1.5) scaleX(0.6)' }} />
                </div>
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        ),
    },
    dont_rotate: {
        label: "Don't Rotate",
        category: 'dont',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center px-4 relative">
                <img src={logoSrc} alt="" className="h-10 max-w-[80px] object-contain" style={{ transform: 'rotate(-15deg)' }} />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        ),
    },
    dont_recolor: {
        label: "Don't Recolor",
        category: 'dont',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center px-4 relative">
                <img src={logoSrc} alt="" className="h-10 max-w-[80px] object-contain" style={{ filter: 'hue-rotate(180deg) saturate(2)' }} />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        ),
    },
    dont_crop: {
        label: "Don't Crop",
        category: 'dont',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-end overflow-hidden relative">
                <img src={logoSrc} alt="" className="h-10 max-w-[80px] object-contain mr-[-20px]" />
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        ),
    },
    dont_add_effects: {
        label: "Don't Add Effects",
        category: 'dont',
        render: (logoSrc) => (
            <div className="w-full aspect-[3/2] bg-white rounded-lg flex items-center justify-center px-4 relative">
                <img src={logoSrc} alt="" className="h-10 max-w-[80px] object-contain" style={{ filter: 'drop-shadow(4px 4px 6px rgba(0,0,0,0.5))' }} />
                <div className="absolute top-2 right-2 px-1.5 py-0.5 bg-yellow-400/90 rounded text-[7px] font-bold text-black tracking-wide">GLOW</div>
                <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                    <div className="w-12 h-12 rounded-full border-[3px] border-red-500/70 flex items-center justify-center">
                        <div className="w-10 h-[3px] bg-red-500/70 rotate-45 rounded-full" />
                    </div>
                </div>
            </div>
        ),
    },
}

function LogoUsageGuidelines({ guidelines, onChange, brandId, brandName, logoSrc, brandColors }) {
    const [generating, setGenerating] = useState(false)
    const [editingKey, setEditingKey] = useState(null)
    const raw = unwrapValue(guidelines)
    const current = (raw && typeof raw === 'object' && !Array.isArray(raw)) ? raw : (guidelines && typeof guidelines === 'object' && !('source' in guidelines)) ? guidelines : {}
    const hasGuidelines = Object.keys(current).length > 0

    const handleGenerate = useCallback(async () => {
        setGenerating(true)
        try {
            const res = await axios.post(route('brands.brand-dna.builder.generate-logo-guidelines', { brand: brandId }))
            if (res.data?.guidelines) {
                onChange(res.data.guidelines)
            }
        } catch {
            onChange({ ...DEFAULT_LOGO_GUIDELINES })
        } finally {
            setGenerating(false)
        }
    }, [brandId, onChange])

    const handleUseDefaults = useCallback(() => {
        onChange({ ...DEFAULT_LOGO_GUIDELINES })
    }, [onChange])

    const updateField = useCallback((key, value) => {
        onChange({ ...current, [key]: value })
    }, [current, onChange])

    const removeField = useCallback((key) => {
        const next = { ...current }
        delete next[key]
        onChange(next)
    }, [current, onChange])

    if (!hasGuidelines) {
        return (
            <div className="space-y-3">
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={handleGenerate}
                        disabled={generating}
                        className="px-4 py-2.5 rounded-xl border border-indigo-500/40 bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/30 text-sm disabled:opacity-50"
                    >
                        {generating ? 'Generating…' : 'Generate with AI'}
                    </button>
                    <button
                        type="button"
                        onClick={handleUseDefaults}
                        className="px-4 py-2.5 rounded-xl border border-white/20 text-white/70 hover:bg-white/10 text-sm"
                    >
                        Use Standard Defaults
                    </button>
                </div>
                <p className="text-xs text-white/40">Generate guidelines tailored to your brand, or start with industry-standard defaults.</p>
            </div>
        )
    }

    const doRules = Object.entries(current).filter(([key]) => GUIDELINE_CARDS[key]?.category === 'do')
    const dontRules = Object.entries(current).filter(([key]) => GUIDELINE_CARDS[key]?.category === 'dont')
    const otherRules = Object.entries(current).filter(([key]) => !GUIDELINE_CARDS[key])

    const renderCard = ([key, value]) => {
        const card = GUIDELINE_CARDS[key]
        const isDont = card?.category === 'dont'
        return (
            <div key={key} className="group rounded-xl border border-white/10 bg-white/[0.03] overflow-hidden hover:border-white/20 transition-colors">
                {/* Visual proof */}
                {card && logoSrc ? (
                    <div className="relative">
                        {card.render(logoSrc, brandColors)}
                        {isDont && (
                            <div className="absolute top-2 left-2 px-1.5 py-0.5 rounded bg-red-500/90 text-[9px] font-bold text-white uppercase tracking-wider">
                                Don&apos;t
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="w-full aspect-[3/2] bg-white/5 rounded-t-lg flex items-center justify-center">
                        <span className="text-white/20 text-sm">{card?.label || key}</span>
                    </div>
                )}
                {/* Label + editable description */}
                <div className="p-3">
                    <div className="flex items-center justify-between mb-1.5">
                        <h4 className="text-xs font-semibold text-white/80 uppercase tracking-wide">
                            {card?.label || key.replace(/_/g, ' ')}
                        </h4>
                        <div className="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button
                                type="button"
                                onClick={() => setEditingKey(editingKey === key ? null : key)}
                                className="text-[10px] text-white/40 hover:text-white/70 px-1"
                            >
                                {editingKey === key ? 'Done' : 'Edit'}
                            </button>
                            <button
                                type="button"
                                onClick={() => removeField(key)}
                                className="text-[10px] text-red-400/50 hover:text-red-400 px-1"
                            >
                                ×
                            </button>
                        </div>
                    </div>
                    {editingKey === key ? (
                        <textarea
                            value={typeof value === 'string' ? value : (value?.value ?? '')}
                            onChange={(e) => updateField(key, e.target.value)}
                            rows={3}
                            className="w-full rounded-lg border border-white/15 bg-white/5 px-2.5 py-1.5 text-white/90 text-xs resize-none focus:border-white/30 focus:ring-0"
                            autoFocus
                        />
                    ) : (
                        <p className="text-[11px] leading-relaxed text-white/50 cursor-pointer" onClick={() => setEditingKey(key)}>
                            {typeof value === 'string' ? value : (value?.value ?? String(value ?? 'Click to add description…'))}
                        </p>
                    )}
                </div>
            </div>
        )
    }

    return (
        <div className="space-y-6">
            {doRules.length > 0 && (
                <div>
                    <h4 className="text-xs font-semibold text-emerald-400/80 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span className="w-4 h-4 rounded-full bg-emerald-500/20 flex items-center justify-center text-[10px]">✓</span>
                        Accepted Usage
                    </h4>
                    <div className="grid grid-cols-2 gap-3">
                        {doRules.map(renderCard)}
                    </div>
                </div>
            )}
            {dontRules.length > 0 && (
                <div>
                    <h4 className="text-xs font-semibold text-red-400/80 uppercase tracking-wider mb-3 flex items-center gap-2">
                        <span className="w-4 h-4 rounded-full bg-red-500/20 flex items-center justify-center text-[10px]">✕</span>
                        Unacceptable Usage
                    </h4>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        {dontRules.map(renderCard)}
                    </div>
                </div>
            )}
            {otherRules.length > 0 && (
                <div>
                    <h4 className="text-xs font-semibold text-white/60 uppercase tracking-wider mb-3">Other Guidelines</h4>
                    <div className="grid grid-cols-2 gap-3">
                        {otherRules.map(renderCard)}
                    </div>
                </div>
            )}
            <div className="flex gap-2 pt-1">
                <button
                    type="button"
                    onClick={handleGenerate}
                    disabled={generating}
                    className="px-3 py-1.5 rounded-lg border border-indigo-500/30 text-indigo-300 hover:bg-indigo-500/20 text-xs disabled:opacity-50"
                >
                    {generating ? 'Regenerating…' : 'Regenerate with AI'}
                </button>
            </div>
        </div>
    )
}

// ——— ReviewPanel ———
function ReviewPanel({ payload, brand, logoRef }) {
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
        { label: 'Website & Social', value: unwrapValue(sources.website_url) ? `Website: ${unwrapValue(sources.website_url)}` : `Social URLs: ${(unwrapValue(sources.social_urls) || []).length}` },
        { label: 'Logo', value: logoRef?.original_filename ? `✓ ${logoRef.original_filename}` : '—' },
        { label: 'Archetype', value: unwrapValue(personality.primary_archetype) || (unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c)).filter(Boolean).join(', ') || '—' },
        { label: 'Why', value: truncate(unwrapValue(identity.mission)) },
        { label: 'What', value: truncate(unwrapValue(identity.positioning)) },
        { label: 'Brand Look', value: truncate(unwrapValue(personality.brand_look)) },
        { label: 'Brand Voice', value: truncate(unwrapValue(personality.voice_description)) },
        { label: 'Tone & Traits', value: (() => { const tk = unwrapValue(scoringRules.tone_keywords) || unwrapValue(personality.tone_keywords) || []; const tr = unwrapValue(personality.traits) || []; return (tk.length + tr.length) > 0 ? `${tk.length} tone keywords, ${tr.length} traits` : '—' })() },
        { label: 'Positioning', value: (() => { const ind = unwrapValue(identity.industry); const ta = unwrapValue(identity.target_audience); return ind || ta ? `${ind || '—'} / ${ta || '—'}` : '—' })() },
        { label: 'Beliefs & Values', value: (() => { const b = unwrapValue(identity.beliefs) || []; const v = unwrapValue(identity.values) || []; return (b.length + v.length) > 0 ? `${b.length} beliefs, ${v.length} values` : '—' })() },
        { label: 'Typography', value: (() => {
            const fontsArr = unwrapValue(typography.fonts) || []
            if (fontsArr.length > 0) return fontsArr.map((f) => typeof f === 'string' ? f : f.name).filter(Boolean).join(', ') || '—'
            return unwrapValue(typography.primary_font) ? `${unwrapValue(typography.primary_font)} / ${unwrapValue(typography.secondary_font) || '—'}` : '—'
        })() },
        { label: 'Color Palette', value: (unwrapValue(scoringRules.allowed_color_palette) || []).length ? `${(unwrapValue(scoringRules.allowed_color_palette) || []).length} colors` : (brandColors?.primary ? 'Brand colors set' : '—') },
        { label: 'Visual References', value: approvedRefsCount(unwrapValue(visual.approved_references)) ? `${approvedRefsCount(unwrapValue(visual.approved_references))} references` : '—' },
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
    logoAsset: initialLogoAsset = null,
    guidelinesPdfAssetId = null,
    guidelinesPdfFilename = null,
    overallStatus: initialOverallStatus = 'pending',
    researchFinalized = false,
    pipelineStatus = {},
    isLocal = false,
    brandResearchGate = null,
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
    const [logoRef, setLogoRef] = useState(initialLogoAsset ?? null)
    const [assetSelectorOpen, setAssetSelectorOpen] = useState(null)
    const [dismissedInlineSuggestions, setDismissedInlineSuggestions] = useState([])
    const [publishWarnings, setPublishWarnings] = useState([])
    const [acknowledgeWarnings, setAcknowledgeWarnings] = useState(false)
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
        const rawLinks = payload.typography?.external_font_links
        const links = (Array.isArray(rawLinks) ? rawLinks : (rawLinks?.value && Array.isArray(rawLinks.value) ? rawLinks.value : [])).filter((url) => typeof url === 'string' && url.startsWith('https://'))
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
    // Steps: archetype -> purpose_promise -> expression -> positioning -> standards -> review
    // Background/processing/research-summary are now handled by the Research page.
    const allStepKeys = [
        ...stepKeys.filter(k => k !== 'background'),
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

    // PDF gate: never allow progression until every page is processed. Override backend.
    const pages = polledResearch?.processing_progress?.pages ?? {}
    const hasPdfPages = (pages?.total ?? 0) > 0
    const pagesAnalyzed = pages?.extracted ?? pages?.classified ?? 0
    const pagesTotal = pages?.total ?? 0
    const allPdfPagesDone = hasPdfPages ? pagesAnalyzed >= pagesTotal : true
    const effectiveResearchFinalizedForGate = hasPdfPages
        ? (allPdfPagesDone && (polledResearch?.researchFinalized ?? researchFinalized ?? false))
        : (polledResearch?.researchFinalized ?? researchFinalized ?? false)

    const triggerIngestion = useCallback(async (opts = {}) => {
        try {
            await axios.post(route('brands.brand-dna.builder.trigger-ingestion', { brand: brand.id }), {
                pdf_asset_id: opts.pdf_asset_id || null,
                website_url: opts.website_url || (payload.sources?.website_url || '').trim() || null,
                material_asset_ids: opts.material_asset_ids || undefined,
            })
            setIngestionPolling(true)
        } catch (e) {
            const status = e.response?.status
            const data = e.response?.data
            if (status === 403 && data?.gate) {
                setErrors((prev) => [...prev, data.error || 'AI brand research requires a paid plan.'])
            } else if (status === 429 && data?.gate) {
                setErrors((prev) => [...prev, data.error || 'Monthly brand research limit reached.'])
            } else if (status !== 422) {
                setErrors((prev) => [...prev, data?.error || 'Failed to start processing'])
            }
        }
    }, [brand.id, payload.sources?.website_url])

    const handleNext = useCallback(async () => {
        if (isReviewStep) return
        if (isLastDataStep) {
            patchAndNavigate(REVIEW_STEP)
        } else {
            const dataSteps = stepKeys.filter(k => k !== 'background')
            const idx = dataSteps.indexOf(currentStep)
            const nextKey = idx < dataSteps.length - 1 ? dataSteps[idx + 1] : REVIEW_STEP
            patchAndNavigate(nextKey)
        }
    }, [isReviewStep, isLastDataStep, currentStep, stepKeys, patchAndNavigate, REVIEW_STEP])

    const handleBack = useCallback(() => {
        if (viewingReview) {
            setViewingReview(false)
            return
        }
        const idx = allStepKeys.indexOf(currentStep)
        if (idx <= 0) {
            // First builder step — back goes to research/review
            router.visit(route('brands.research.show', { brand: brand.id }))
            return
        }
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
        setPublishWarnings([])
        setSaving(true)
        try {
            const res = await axios.post(route('brands.brand-dna.versions.publish', { brand: brand.id, version: draft.id }), {
                enable_scoring: enableScoring,
                acknowledge_warnings: acknowledgeWarnings,
            })
            if (res.status === 200) {
                router.visit(route('brands.guidelines.index', { brand: brand.id }))
            }
        } catch (e) {
            const data = e.response?.data
            if (data?.error === 'validation_failed') {
                setMissingFields(data.missing_fields || [])
                setPublishWarnings(data.warnings || [])
                setErrors([])
            } else if (data?.error === 'warnings_unacknowledged') {
                setMissingFields([])
                setPublishWarnings(data.warnings || [])
                setErrors([])
            } else if (data?.error === 'Version must be a draft to publish.') {
                router.visit(route('brands.guidelines.index', { brand: brand.id }))
                return
            } else {
                setErrors([data?.message || data?.error || 'Publish failed'])
            }
        } finally {
            setSaving(false)
        }
    }, [brand.id, draft.id, enableScoring, acknowledgeWarnings])

    const effectiveOverallStatus = polledResearch?.overall_status ?? initialOverallStatus

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
            } else if (context === 'logo_reference') {
                const serverLogo = res.data?.logo_asset
                const logoData = {
                    id: serverLogo?.id ?? assetId,
                    thumbnail_url: serverLogo?.thumbnail_url || item.thumbnail_url || null,
                    preview_url: item.preview_url || null,
                    original_filename: serverLogo?.original_filename || item.original_filename,
                }
                setLogoRef(logoData)
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

    const handleDetachLogo = useCallback(async () => {
        if (!logoRef?.id) return
        try {
            await axios.post(route('brands.brand-dna.builder.detach-asset', { brand: brand.id }), {
                asset_id: logoRef.id,
                builder_context: 'logo_reference',
            })
            setLogoRef(null)
        } catch {}
    }, [brand.id, logoRef])

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

    // When on processing step: only redirect when research is finalized AND (for PDF) all pages processed.
    // Uses effectiveResearchFinalizedForGate, NOT overall_status — backend can return completed too early.
    useEffect(() => {
        if (currentStep === 'processing' && effectiveResearchFinalizedForGate) {
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: 'research-summary' }))
        }
    }, [currentStep, effectiveResearchFinalizedForGate, brand.id])

    // When on research-summary but not ready (e.g. PDF pages not all done), redirect back to processing
    useEffect(() => {
        if (currentStep === 'research-summary' && !effectiveResearchFinalizedForGate) {
            router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: 'processing' }))
        }
    }, [currentStep, effectiveResearchFinalizedForGate, brand.id])

    const effectiveSnapshotLite = polledResearch?.latestSnapshotLite ?? latestSnapshotLite
    const effectiveCoherence = polledResearch?.latestCoherence ?? latestCoherence
    const effectiveAlignment = polledResearch?.latestAlignment ?? latestAlignment
    const effectiveSuggestions = polledResearch?.latestSuggestions ?? latestSuggestions

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
        effectiveResearchFinalizedForGate
    )

    const sources = payload.sources || {}
    const identity = payload.identity || {}
    const personality = payload.personality || {}
    const typography = payload.typography || {}
    const scoringRules = payload.scoring_rules || {}
    const visual = payload.visual || {}

    return (
        <div className="h-screen flex flex-col bg-[#0B0B0D] relative overflow-hidden">
            {/* Cinematic background — brand-driven radial gradients */}
            <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    background: `radial-gradient(ellipse at 20% 0%, ${displayPrimary}30, transparent 70%), radial-gradient(ellipse at 80% 100%, ${displayPrimary}20, transparent 60%), #0B0B0D`,
                }}
            />
            <div
                className="absolute inset-0 pointer-events-none"
                style={{
                    background: `radial-gradient(circle at 60% 30%, ${displayPrimary}12, transparent 50%)`,
                }}
            />
            {/* Depth overlays */}
            <div className="absolute inset-0 pointer-events-none">
                <div className="absolute inset-0 bg-black/20" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/40" />
            </div>
            {/* Film grain */}
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
                    {/* Non-review errors (e.g. general save failures) shown at top of all steps */}
                    {!isReviewStep && errors.length > 0 && (
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

                    <AnimatePresence mode="wait">
                        {isReviewStep ? (
                            <motion.div
                                key="review"
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -12 }}
                                transition={{ duration: 0.3 }}
                            >
                                <ReviewPanel payload={payload} brand={brand} logoRef={logoRef} />

                                {/* Publish section — sticky-visible at bottom of review */}
                                <div className="mt-10 mb-32 pt-8 border-t border-white/10">
                                    {/* Inline validation near the button */}
                                    {missingFields.length > 0 && (
                                        <div className="mb-6 rounded-xl bg-red-500/20 border border-red-500/40 p-4">
                                            <h4 className="font-medium text-red-200 mb-2">Required before publishing:</h4>
                                            <ul className="space-y-1 text-red-100/90 text-sm">
                                                {missingFields.map((msg, i) => {
                                                    const stepKey = msg.includes('Background') ? 'background' : msg.includes('Purpose') ? 'purpose_promise' : msg.includes('Positioning') ? 'positioning' : msg.includes('Archetype') ? 'archetype' : msg.includes('Expression') ? 'expression' : msg.includes('Standards') ? 'standards' : null
                                                    return (
                                                        <li key={`bottom-err-${i}`} className="flex items-center gap-2 flex-wrap">
                                                            <span>{msg}</span>
                                                            {stepKey && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setViewingReview(false)
                                                                        router.visit(route('brands.brand-guidelines.builder', { brand: brand.id, step: stepKey }))
                                                                    }}
                                                                    className="text-red-200 underline hover:text-red-100 text-xs"
                                                                >
                                                                    Fix
                                                                </button>
                                                            )}
                                                        </li>
                                                    )
                                                })}
                                            </ul>
                                        </div>
                                    )}

                                    {publishWarnings.length > 0 && missingFields.length === 0 && (
                                        <div className="mb-6 rounded-xl bg-amber-500/20 border border-amber-500/40 p-4">
                                            <h4 className="font-medium text-amber-200 mb-2">Recommended but not required:</h4>
                                            <ul className="space-y-1 text-amber-100/90 text-sm mb-4">
                                                {publishWarnings.map((msg, i) => (
                                                    <li key={`bottom-warn-${i}`}>{msg}</li>
                                                ))}
                                            </ul>
                                            <label className="flex items-center gap-3 cursor-pointer">
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={acknowledgeWarnings}
                                                    onClick={() => setAcknowledgeWarnings((v) => !v)}
                                                    className={`relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 focus:ring-offset-transparent ${acknowledgeWarnings ? 'bg-amber-500' : 'bg-white/20'}`}
                                                >
                                                    <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${acknowledgeWarnings ? 'translate-x-5' : 'translate-x-0'}`} />
                                                </button>
                                                <span className="text-amber-100/90 text-sm">Publish without completing these</span>
                                            </label>
                                        </div>
                                    )}

                                    {errors.length > 0 && (
                                        <div className="mb-6 rounded-xl bg-red-500/20 border border-red-500/40 p-4 text-red-200 text-sm">
                                            <ul className="list-disc list-inside">
                                                {errors.map((err, i) => (
                                                    <li key={`bottom-gen-${i}`}>{err}</li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6">
                                        <label className="flex items-center gap-3 cursor-pointer order-2 sm:order-1">
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={enableScoring}
                                                onClick={() => setEnableScoring((v) => !v)}
                                                className={`relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-transparent ${enableScoring ? 'bg-indigo-500' : 'bg-white/20'}`}
                                            >
                                                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${enableScoring ? 'translate-x-5' : 'translate-x-0'}`} />
                                            </button>
                                            <span className="text-white/80 text-sm">Enable On-Brand Scoring</span>
                                            <span className="text-white/40 text-xs">(can change later)</span>
                                        </label>
                                        <button
                                            type="button"
                                            onClick={handlePublish}
                                            disabled={saving || (missingFields.length > 0)}
                                            className="order-1 sm:order-2 w-full sm:w-auto px-10 py-3.5 rounded-xl font-semibold text-white text-base transition-all disabled:opacity-50 shadow-lg hover:shadow-xl hover:scale-[1.02] active:scale-[0.98]"
                                            style={{ backgroundColor: (saving || missingFields.length > 0) ? '#4b5563' : displayPrimary }}
                                        >
                                            {saving ? 'Publishing…' : 'Publish Brand Guidelines'}
                                        </button>
                                    </div>
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
                                                pipelineError={polledResearch?.pipeline_error ?? null}
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
                                                hasWebsite={!!(unwrapValue(sources.website_url) || '').trim()}
                                                hasSocial={!!(sources?.social_urls?.length)}
                                                hasMaterials={brandMaterialCount > 0}
                                                brandId={brand?.id}
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
                                            {brandResearchGate && !brandResearchGate.allowed && (
                                                <div className="rounded-xl border border-white/10 bg-white/5 px-5 py-4">
                                                    <div className="flex items-start gap-3">
                                                        <svg className="w-5 h-5 text-amber-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 15v.01M12 9v3m0 0a9 9 0 110 18 9 9 0 010-18z" />
                                                        </svg>
                                                        <div>
                                                            <p className="text-sm font-medium text-white/90">
                                                                {brandResearchGate.is_disabled
                                                                    ? 'AI brand research requires a paid plan'
                                                                    : `Monthly AI research limit reached (${brandResearchGate.usage}/${brandResearchGate.cap})`}
                                                            </p>
                                                            <p className="text-xs text-white/50 mt-1">
                                                                {brandResearchGate.is_disabled
                                                                    ? 'Upgrade to Starter or above to use AI-powered brand guidelines extraction. You can still enter your brand information manually.'
                                                                    : 'Your usage resets at the start of next month. You can still edit your brand DNA manually or upgrade your plan for more analysis runs.'}
                                                            </p>
                                                        </div>
                                                    </div>
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
                                                            value={unwrapValue(sources.website_url) || ''}
                                                            onChange={(e) => updatePayload('sources', 'website_url', e.target.value)}
                                                            placeholder="https://yoursite.com"
                                                            disabled={effectiveCrawlerRunning}
                                                            className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 focus:border-white/30 disabled:opacity-60 disabled:cursor-not-allowed"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-white/80 mb-2">Social URLs</label>
                                                        <ChipInput
                                                            value={(unwrapValue(sources.social_urls) || []).map((u) => typeof u === 'string' ? u : (u?.value ?? String(u)))}
                                                            onChange={(v) => updatePayload('sources', 'social_urls', v)}
                                                            placeholder="Paste URL and press Enter"
                                                            disabled={effectiveCrawlerRunning}
                                                        />
                                                    </div>
                                                    <div className="pt-2">
                                                        <button
                                                            type="button"
                                                            disabled={effectiveCrawlerRunning || (!(unwrapValue(sources.website_url) || '').trim() && (unwrapValue(sources.social_urls) || []).length === 0)}
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
                                                    {isAiPopulated(identity.industry) && <AiFieldBadge field={identity.industry} className="mb-2" />}
                                                    <input
                                                        type="text"
                                                        value={unwrapValue(identity.industry) || ''}
                                                        onChange={(e) => updatePayload('identity', 'industry', e.target.value)}
                                                        placeholder="e.g. SaaS, Healthcare"
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                    />
                                                    {effectiveSuggestions?.industry_suggestion && !unwrapValue(identity.industry) && !dismissedInlineSuggestions.includes('positioning:industry') && (
                                                        <InlineSuggestionBlock
                                                            title="AI suggestion"
                                                            items={[String(effectiveSuggestions.industry_suggestion)]}
                                                            onApply={(val) => updatePayload('identity', 'industry', val)}
                                                            onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'positioning:industry'])}
                                                        />
                                                    )}
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-medium text-white/80 mb-2">Target Audience</label>
                                                    {isAiPopulated(identity.target_audience) && <AiFieldBadge field={identity.target_audience} className="mb-2" />}
                                                    <input
                                                        type="text"
                                                        value={unwrapValue(identity.target_audience) || ''}
                                                        onChange={(e) => updatePayload('identity', 'target_audience', e.target.value)}
                                                        placeholder="Who are your customers?"
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                    />
                                                    {effectiveSuggestions?.target_audience_suggestion && !unwrapValue(identity.target_audience) && !dismissedInlineSuggestions.includes('positioning:target_audience') && (
                                                        <InlineSuggestionBlock
                                                            title="AI suggestion"
                                                            items={[String(effectiveSuggestions.target_audience_suggestion)]}
                                                            onApply={(val) => updatePayload('identity', 'target_audience', val)}
                                                            onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'positioning:target_audience'])}
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Market Category</label>
                                                <input
                                                    type="text"
                                                    value={unwrapValue(identity.market_category) || ''}
                                                    onChange={(e) => updatePayload('identity', 'market_category', e.target.value)}
                                                    placeholder="e.g. Premium consumer electronics"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Competitive Position</label>
                                                <input
                                                    type="text"
                                                    value={unwrapValue(identity.competitive_position) || ''}
                                                    onChange={(e) => updatePayload('identity', 'competitive_position', e.target.value)}
                                                    placeholder="How you differentiate from competitors"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tagline</label>
                                                {isAiPopulated(identity.tagline) && <AiFieldBadge field={identity.tagline} className="mb-2" />}
                                                <input
                                                    type="text"
                                                    value={unwrapValue(identity.tagline) || ''}
                                                    onChange={(e) => updatePayload('identity', 'tagline', e.target.value)}
                                                    placeholder="e.g. Just Do It"
                                                    className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40"
                                                />
                                                {effectiveSuggestions?.tagline_suggestion && !unwrapValue(identity.tagline) && !dismissedInlineSuggestions.includes('positioning:tagline') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggestion"
                                                        items={[String(effectiveSuggestions.tagline_suggestion)]}
                                                        onApply={(val) => updatePayload('identity', 'tagline', val)}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'positioning:tagline'])}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Beliefs</label>
                                                <ChipInput
                                                    value={(unwrapValue(identity.beliefs) || []).filter(v => typeof v === 'string')}
                                                    onChange={(v) => updatePayload('identity', 'beliefs', v)}
                                                    placeholder="Add belief"
                                                />
                                                {effectiveSuggestions?.beliefs_suggestion?.length > 0 && !(unwrapValue(identity.beliefs) || []).length && !dismissedInlineSuggestions.includes('positioning:beliefs') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggested beliefs"
                                                        items={effectiveSuggestions.beliefs_suggestion}
                                                        onApply={(val) => {
                                                            const current = unwrapValue(identity.beliefs) || []
                                                            if (!current.includes(val)) updatePayload('identity', 'beliefs', [...current.filter(v => typeof v === 'string'), val])
                                                        }}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'positioning:beliefs'])}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Values</label>
                                                <ChipInput
                                                    value={(unwrapValue(identity.values) || []).filter(v => typeof v === 'string')}
                                                    onChange={(v) => updatePayload('identity', 'values', v)}
                                                    placeholder="Add value"
                                                />
                                                {effectiveSuggestions?.values_suggestion?.length > 0 && !(unwrapValue(identity.values) || []).length && !dismissedInlineSuggestions.includes('positioning:values') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggested values"
                                                        items={effectiveSuggestions.values_suggestion}
                                                        onApply={(val) => {
                                                            const current = unwrapValue(identity.values) || []
                                                            if (!current.includes(val)) updatePayload('identity', 'values', [...current.filter(v => typeof v === 'string'), val])
                                                        }}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'positioning:values'])}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'archetype' && (
                                        <div className="space-y-8">
                                            {effectiveSuggestions?.recommended_archetypes?.length > 0 && !dismissedInlineSuggestions.includes('archetype:recommended') && (
                                                <InlineSuggestionBlock
                                                    title="Recommended archetypes"
                                                    items={effectiveSuggestions.recommended_archetypes}
                                                    onApply={(archetype) => {
                                                        setPayload((prev) => ({
                                                            ...prev,
                                                            personality: {
                                                                ...(prev.personality || {}),
                                                                primary_archetype: archetype,
                                                                candidate_archetypes: (unwrapValue(prev.personality?.candidate_archetypes) || []).map((a) => unwrapValue(a)).filter((a) => a !== archetype),
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
                                                        const selected = [primary, ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean)
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
                                                                {[unwrapValue(personality.primary_archetype), ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean).map((id) => {
                                                                    const a = ARCHETYPES.find((x) => x.id === id)
                                                                    if (!a) return null
                                                                    return (
                                                                        <div key={a.id} className="flex items-center justify-between rounded-lg bg-white/10 px-3 py-2">
                                                                            <span className="text-white font-medium">{a.id}</span>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => {
                                                                                    const selected = [unwrapValue(personality.primary_archetype), ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean)
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
                                                                {(unwrapValue(personality.rejected_archetypes) || []).map((id) => unwrapValue(id)).filter(Boolean).map((id) => {
                                                                    const a = ARCHETYPES.find((x) => x.id === id)
                                                                    if (!a) return null
                                                                    return (
                                                                        <button
                                                                            key={a.id}
                                                                            type="button"
                                                                            onClick={() => updatePayload('personality', 'rejected_archetypes', (unwrapValue(personality.rejected_archetypes) || []).map((x) => unwrapValue(x)).filter((x) => x !== a.id))}
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
                                                                const selected = [unwrapValue(personality.primary_archetype), ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean)
                                                                const rejected = (unwrapValue(personality.rejected_archetypes) || []).map((r) => unwrapValue(r))
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
                                                                                const selected = [unwrapValue(personality.primary_archetype), ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean)
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
                                                                            disabled={[unwrapValue(personality.primary_archetype), ...(unwrapValue(personality.candidate_archetypes) || []).map((c) => unwrapValue(c))].filter(Boolean).length >= 2}
                                                                            className="px-3 py-1.5 rounded-lg bg-emerald-500/30 text-emerald-200 text-xs font-medium hover:bg-emerald-500/50 disabled:opacity-40 disabled:cursor-not-allowed"
                                                                        >
                                                                            Applies
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => updatePayload('personality', 'rejected_archetypes', [...(unwrapValue(personality.rejected_archetypes) || []).map((r) => unwrapValue(r)), a.id])}
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
                                                {effectiveSuggestions?.mission_suggestion && !unwrapValue(identity.mission) && !dismissedInlineSuggestions.includes('purpose:mission') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggestion"
                                                        items={[String(effectiveSuggestions.mission_suggestion)]}
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
                                                {effectiveSuggestions?.positioning_suggestion && !unwrapValue(identity.positioning) && !dismissedInlineSuggestions.includes('purpose:positioning') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggestion"
                                                        items={[String(effectiveSuggestions.positioning_suggestion)]}
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
                                                        const current = unwrapValue(personality.traits) || []
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
                                                    {isAiPopulated(personality.brand_look) && <AiFieldBadge field={personality.brand_look} className="mb-2" />}
                                                    <textarea
                                                        value={unwrapValue(personality.brand_look) || ''}
                                                        onChange={(e) => updatePayload('personality', 'brand_look', e.target.value)}
                                                        rows={5}
                                                        placeholder="e.g. Minimal, clean lines, lots of white space. Photography is warm and candid. Color palette is muted earth tones."
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                    />
                                                    {effectiveSuggestions?.brand_look_suggestion && !unwrapValue(personality.brand_look) && !dismissedInlineSuggestions.includes('expression:brand_look') && (
                                                        <InlineSuggestionBlock
                                                            title="AI suggestion"
                                                            items={[String(effectiveSuggestions.brand_look_suggestion)]}
                                                            onApply={(val) => updatePayload('personality', 'brand_look', val)}
                                                            onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'expression:brand_look'])}
                                                        />
                                                    )}
                                                    <p className="mt-3 text-xs text-white/50">Example: &quot;Bold, high-contrast visuals. Product shots on pure white. Typography is geometric and modern.&quot;</p>
                                                </div>
                                                <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
                                                    <h3 className="text-lg font-semibold text-white mb-2">Brand Voice</h3>
                                                    <p className="text-sm text-white/60 mb-4">How your brand sounds in copy, tone, and personality.</p>
                                                    {isAiPopulated(personality.voice_description) && <AiFieldBadge field={personality.voice_description} className="mb-2" />}
                                                    <textarea
                                                        value={unwrapValue(personality.voice_description) || ''}
                                                        onChange={(e) => updatePayload('personality', 'voice_description', e.target.value)}
                                                        rows={5}
                                                        placeholder="e.g. Friendly but professional. We use contractions and avoid jargon. Confident without being arrogant."
                                                        className="w-full rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-white placeholder-white/40 focus:ring-2 focus:ring-white/30 resize-none"
                                                    />
                                                    {effectiveSuggestions?.voice_description_suggestion && !unwrapValue(personality.voice_description) && !dismissedInlineSuggestions.includes('expression:voice') && (
                                                        <InlineSuggestionBlock
                                                            title="AI suggestion"
                                                            items={[String(effectiveSuggestions.voice_description_suggestion)]}
                                                            onApply={(val) => updatePayload('personality', 'voice_description', val)}
                                                            onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'expression:voice'])}
                                                        />
                                                    )}
                                                    <p className="mt-3 text-xs text-white/50">Example: &quot;Warm, approachable, and slightly playful. We speak like a knowledgeable friend, not a salesperson.&quot;</p>
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Tone keywords</label>
                                                <ChipInput
                                                    value={(unwrapValue(scoringRules.tone_keywords) || unwrapValue(personality.tone_keywords) || []).filter(v => typeof v === 'string' && v)}
                                                    onChange={(v) => updatePayload('scoring_rules', 'tone_keywords', v)}
                                                    placeholder="Add tone keyword"
                                                />
                                                {effectiveSuggestions?.tone_keywords_suggestion?.length > 0 && !(unwrapValue(scoringRules.tone_keywords) || []).length && !dismissedInlineSuggestions.includes('expression:tone') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggested tone keywords"
                                                        items={effectiveSuggestions.tone_keywords_suggestion}
                                                        onApply={(val) => {
                                                            const current = unwrapValue(scoringRules.tone_keywords) || []
                                                            if (!current.includes(val)) updatePayload('scoring_rules', 'tone_keywords', [...current.filter(v => typeof v === 'string'), val])
                                                        }}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'expression:tone'])}
                                                    />
                                                )}
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-white/80 mb-2">Traits</label>
                                                <ChipInput
                                                    value={(unwrapValue(personality.traits) || []).filter(v => typeof v === 'string')}
                                                    onChange={(v) => updatePayload('personality', 'traits', v)}
                                                    placeholder="Add trait"
                                                />
                                                {effectiveSuggestions?.traits_suggestion?.length > 0 && !(unwrapValue(personality.traits) || []).length && !dismissedInlineSuggestions.includes('expression:traits_ai') && (
                                                    <InlineSuggestionBlock
                                                        title="AI suggested traits"
                                                        items={effectiveSuggestions.traits_suggestion}
                                                        onApply={(val) => {
                                                            const current = unwrapValue(personality.traits) || []
                                                            if (!current.includes(val)) updatePayload('personality', 'traits', [...current.filter(v => typeof v === 'string'), val])
                                                        }}
                                                        onDismiss={() => setDismissedInlineSuggestions((p) => [...p, 'expression:traits_ai'])}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {currentStep === 'standards' && (
                                        <div className="space-y-8">
                                            <FieldCard title="Brand Logo">
                                                <p className="text-white/60 text-sm mb-4">Your primary brand logo. Used across the application, download pages, and brand materials.</p>
                                                {logoRef ? (
                                                    <div className="flex items-start gap-4">
                                                        <div className="w-32 h-32 rounded-xl border border-white/20 bg-white/5 p-3 flex items-center justify-center overflow-hidden flex-shrink-0">
                                                            {(logoRef.preview_url || logoRef.thumbnail_url) ? (
                                                                <img
                                                                    src={logoRef.preview_url || logoRef.thumbnail_url}
                                                                    alt="Logo"
                                                                    className="max-w-full max-h-full object-contain"
                                                                    onError={(e) => {
                                                                        if (e.target.src !== logoRef.preview_url && logoRef.preview_url) {
                                                                            e.target.src = logoRef.preview_url
                                                                        } else {
                                                                            e.target.style.display = 'none'
                                                                            e.target.parentElement.innerHTML = '<div class="text-white/30 text-xs text-center">Generating<br/>thumbnail…</div>'
                                                                        }
                                                                    }}
                                                                />
                                                            ) : (
                                                                <div className="text-white/30 text-xs text-center">Generating<br/>thumbnail…</div>
                                                            )}
                                                        </div>
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm text-white/80 truncate">{logoRef.original_filename || 'Logo'}</p>
                                                            <div className="flex gap-2 mt-3">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setAssetSelectorOpen('logo_reference')}
                                                                    className="px-3 py-1.5 rounded-lg border border-white/20 text-white/80 hover:bg-white/10 text-xs"
                                                                >
                                                                    Replace
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={handleDetachLogo}
                                                                    className="px-3 py-1.5 rounded-lg text-red-400 hover:text-red-300 text-xs"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => setAssetSelectorOpen('logo_reference')}
                                                        className="w-full py-8 rounded-xl border-2 border-dashed border-white/20 hover:border-white/40 hover:bg-white/5 transition-colors flex flex-col items-center gap-2"
                                                    >
                                                        <svg className="w-8 h-8 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                        </svg>
                                                        <span className="text-sm text-white/60">Select logo from your assets</span>
                                                        <span className="text-xs text-white/40">PNG, SVG, or high-res JPG recommended</span>
                                                    </button>
                                                )}
                                            </FieldCard>
                                            {logoRef && (
                                                <FieldCard title="Logo Usage Guidelines">
                                                    <p className="text-white/60 text-sm mb-4">Rules for how your logo should (and shouldn&apos;t) be used. These can be auto-generated or customized.</p>
                                                    <LogoUsageGuidelines
                                                        guidelines={payload.visual?.logo_usage_guidelines || null}
                                                        onChange={(g) => updatePayload('visual', 'logo_usage_guidelines', g)}
                                                        brandId={brand.id}
                                                        brandName={brand.name}
                                                        logoSrc={logoRef?.preview_url || logoRef?.thumbnail_url}
                                                        brandColors={{ primary: brandColors.primary_color || displayPrimary, secondary: brandColors.secondary_color || displaySecondary }}
                                                    />
                                                </FieldCard>
                                            )}
                                            <FieldCard title="Typography">
                                                {isAiPopulated(typography.primary_font) && <AiFieldBadge field={typography.primary_font} className="mb-2" />}
                                                <FontManager
                                                    fonts={unwrapValue(typography.fonts) || []}
                                                    onChange={(fonts) => {
                                                        updatePayload('typography', 'fonts', fonts)
                                                        const primary = fonts.find((f) => f.role === 'primary' || f.role === 'display')
                                                        const secondary = fonts.find((f) => f.role === 'secondary' || f.role === 'body')
                                                        if (primary) updatePayload('typography', 'primary_font', primary.name)
                                                        if (secondary) updatePayload('typography', 'secondary_font', secondary.name)
                                                    }}
                                                    suggestions={latestSuggestions?.fonts_suggestion || []}
                                                    onApplySuggestion={(fonts) => {
                                                        updatePayload('typography', 'fonts', fonts)
                                                        const primary = fonts.find((f) => f.role === 'primary' || f.role === 'display')
                                                        const secondary = fonts.find((f) => f.role === 'secondary' || f.role === 'body')
                                                        if (primary) updatePayload('typography', 'primary_font', primary.name)
                                                        if (secondary) updatePayload('typography', 'secondary_font', secondary.name)
                                                    }}
                                                />
                                                <div className="mt-4 pt-4 border-t border-white/10">
                                                    <label className="block text-xs text-white/60 mb-1">External Font URLs</label>
                                                    <ChipInput
                                                        value={(unwrapValue(typography.external_font_links) || []).filter((u) => typeof u === 'string' && u.startsWith('https://'))}
                                                        onChange={(v) => updatePayload('typography', 'external_font_links', v.filter((u) => typeof u === 'string' && u.trim().startsWith('https://')))}
                                                        placeholder="https://fonts.googleapis.com/… and press Enter"
                                                    />
                                                    <p className="mt-1 text-xs text-white/40">Google Fonts or self-hosted font CSS URLs. HTTPS only.</p>
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
                                                    {[
                                                        { key: 'primary_color', label: 'Primary Color', placeholder: '#6366f1' },
                                                        { key: 'secondary_color', label: 'Secondary Color', placeholder: '#8b5cf6' },
                                                        { key: 'accent_color', label: 'Accent Color', placeholder: '#06b6d4' },
                                                    ].map(({ key, label, placeholder }) => {
                                                        const colorVal = brandColors[key]
                                                        const hasColor = !!colorVal
                                                        return (
                                                            <div key={key}>
                                                                <label className="block text-sm font-medium text-white/80 mb-2">{label}</label>
                                                                <div className="flex gap-2">
                                                                    {hasColor ? (
                                                                        <input
                                                                            type="color"
                                                                            value={colorVal.startsWith('#') ? colorVal : '#' + colorVal}
                                                                            onChange={(e) => setBrandColors((c) => ({ ...c, [key]: e.target.value || null }))}
                                                                            className="h-10 w-12 rounded-lg border border-white/20 cursor-pointer bg-transparent flex-shrink-0"
                                                                            style={{ padding: 2 }}
                                                                        />
                                                                    ) : (
                                                                        <div
                                                                            className="h-10 w-12 rounded-lg border border-dashed border-white/20 bg-white/5 flex items-center justify-center flex-shrink-0 cursor-pointer"
                                                                            title="Click to set a color"
                                                                            onClick={() => setBrandColors((c) => ({ ...c, [key]: placeholder }))}
                                                                        >
                                                                            <span className="text-white/30 text-lg">+</span>
                                                                        </div>
                                                                    )}
                                                                    <input
                                                                        type="text"
                                                                        value={colorVal || ''}
                                                                        onChange={(e) => {
                                                                            const v = e.target.value.trim()
                                                                            setBrandColors((c) => ({ ...c, [key]: v ? (v.startsWith('#') ? v : '#' + v) : null }))
                                                                        }}
                                                                        placeholder={placeholder}
                                                                        className="flex-1 rounded-lg border border-white/20 bg-white/5 px-3 py-2 text-white text-sm placeholder-white/40"
                                                                    />
                                                                    {hasColor && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => setBrandColors((c) => ({ ...c, [key]: null }))}
                                                                            className="h-10 w-10 rounded-lg border border-white/20 bg-white/5 flex items-center justify-center text-white/40 hover:text-red-400 hover:border-red-400/30 transition-colors flex-shrink-0"
                                                                            title={`Clear ${label.toLowerCase()}`}
                                                                        >
                                                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" /></svg>
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        )
                                                    })}
                                                </div>
                                                {effectiveSuggestions?.brand_color_suggestions?.length > 0 && !dismissedInlineSuggestions.includes('standards:brand_colors') && (
                                                    <div className="mt-4 rounded-xl bg-indigo-900/30 border border-indigo-500/30 p-4">
                                                        <p className="text-sm font-medium text-indigo-300 mb-3">Brand colors from guidelines</p>
                                                        <div className="flex gap-3 flex-wrap">
                                                            {effectiveSuggestions.brand_color_suggestions.map((cs) => (
                                                                <button
                                                                    key={cs.key}
                                                                    type="button"
                                                                    onClick={() => setBrandColors((c) => ({ ...c, [cs.key]: cs.value }))}
                                                                    className="flex items-center gap-2 px-3 py-2 rounded-lg border border-white/20 hover:bg-white/10 text-sm text-white/80"
                                                                >
                                                                    <div className="w-6 h-6 rounded border border-white/30" style={{ backgroundColor: cs.value }} />
                                                                    <span>{cs.key.replace('_color', '').replace('_', ' ')}: {cs.value}</span>
                                                                    <span className="text-indigo-300 text-xs ml-1">Use</span>
                                                                </button>
                                                            ))}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => setDismissedInlineSuggestions((p) => [...p, 'standards:brand_colors'])}
                                                            className="mt-2 text-xs text-white/40 hover:text-white/60"
                                                        >
                                                            Dismiss
                                                        </button>
                                                    </div>
                                                )}
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
                
                </div>

                {/* Footer — always visible at bottom */}
                <footer
                    className="flex-shrink-0 px-4 sm:px-8 py-4 border-t border-white/10 bg-[#0B0B0D]/80 backdrop-blur-xl"
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
                                    {currentStep === 'processing' && !effectiveResearchFinalizedForGate && (
                                        <span className="text-sm text-amber-200/90">Wait for processing to finish</span>
                                    )}
                                    {(() => {
                                        const isDisabled = saving
                                        return (
                                            <button
                                                type="button"
                                                onClick={handleNext}
                                                disabled={isDisabled}
                                                className="px-6 py-2.5 rounded-xl font-medium text-white transition-colors disabled:opacity-50"
                                                style={{ backgroundColor: isDisabled ? '#4b5563' : displayAccent }}
                                            >
                                                {saving ? 'Saving…' : isLastDataStep ? 'Review' : 'Next'}
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
                <BuilderAssetSelectorModal
                    open={assetSelectorOpen === 'logo_reference'}
                    onClose={() => setAssetSelectorOpen(null)}
                    brandId={brand.id}
                    builderContext="logo_reference"
                    onSelect={(asset) => handleAssetAttach(asset, 'logo_reference')}
                    title="Select Brand Logo"
                />
            </div>
        </div>
    )
}

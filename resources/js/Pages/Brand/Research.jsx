/**
 * Brand Research — dedicated page for research inputs, pipeline status, and results.
 * Part of the lifecycle: Research -> Review -> Build -> Publish
 */

import { useState, useCallback, useEffect, useRef } from 'react'
import { router, usePage } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import BuilderAssetSelectorModal from '../../Components/BrandGuidelines/BuilderAssetSelectorModal'
import axios from 'axios'

function StatusBadge({ status, elapsed }) {
    const colors = {
        completed: 'bg-emerald-500/20 text-emerald-400',
        complete: 'bg-emerald-500/20 text-emerald-400',
        running: 'bg-amber-500/20 text-amber-400',
        processing: 'bg-amber-500/20 text-amber-400',
        pending: 'bg-white/10 text-white/50',
        not_started: 'bg-white/10 text-white/50',
        failed: 'bg-red-500/20 text-red-400',
        stuck: 'bg-red-500/20 text-red-400',
    }
    const label = {
        completed: 'Complete',
        complete: 'Complete',
        running: 'Running…',
        processing: 'Processing…',
        pending: 'Pending',
        not_started: 'Not started',
        failed: 'Failed',
        stuck: 'Stuck',
    }

    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors[status] || colors.pending}`}>
            {(status === 'running' || status === 'processing') && (
                <span className="mr-1.5 h-1.5 w-1.5 rounded-full bg-current animate-pulse" />
            )}
            {label[status] || status}
            {elapsed && <span className="ml-1.5 opacity-70">{elapsed}</span>}
        </span>
    )
}

function formatElapsed(seconds) {
    if (!seconds || seconds < 0) return null
    if (seconds < 60) return `${seconds}s`
    const mins = Math.floor(seconds / 60)
    if (mins < 60) return `${mins}m`
    const hours = Math.floor(mins / 60)
    const remainMins = mins % 60
    if (hours < 24) return remainMins > 0 ? `${hours}h ${remainMins}m` : `${hours}h`
    const days = Math.floor(hours / 24)
    const remainHours = hours % 24
    return remainHours > 0 ? `${days}d ${remainHours}h` : `${days}d`
}

function timeAgo(isoString) {
    if (!isoString) return null
    const seconds = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000)
    return formatElapsed(seconds) + ' ago'
}

function SectionCard({ title, children, className = '' }) {
    return (
        <div className={`rounded-2xl bg-white/[0.04] border border-white/[0.06] p-6 ${className}`}>
            {title && <h3 className="text-lg font-semibold text-white/90 mb-4">{title}</h3>}
            {children}
        </div>
    )
}

function ResultField({ label, value, source, confidence }) {
    if (!value || (Array.isArray(value) && value.length === 0)) return null
    const displayValue = Array.isArray(value) ? value.join(', ') : String(value)
    return (
        <div className="flex flex-col gap-1 py-2">
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-white/50 uppercase tracking-wider">{label}</span>
                {source && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-white/5 text-white/40">
                        {source}
                    </span>
                )}
                {confidence != null && (
                    <span className="text-[10px] text-white/30">{Math.round(confidence * 100)}%</span>
                )}
            </div>
            <span className="text-sm text-white/80">{displayValue}</span>
        </div>
    )
}

export default function Research({
    brand,
    version,
    inputs,
    runs,
    snapshots,
    status,
    pipelineHealth: initialPipelineHealth,
    results,
    modelPayload,
    brandResearchGate,
}) {
    const [websiteUrl, setWebsiteUrl] = useState(inputs.website_url || '')
    const [socialUrls, setSocialUrls] = useState(inputs.social_urls?.length ? inputs.social_urls : [''])
    const [analyzing, setAnalyzing] = useState(false)

    const initialHealthState = initialPipelineHealth?.state || 'idle'
    const shouldPoll = initialHealthState === 'processing' && !status.research_finalized

    const [polling, setPolling] = useState(shouldPoll)
    const [polledStatus, setPolledStatus] = useState(status)
    const [polledResults, setPolledResults] = useState(results)
    const [health, setHealth] = useState(initialPipelineHealth || { state: 'idle', error: null, can_retry: false })
    const [requiresAnalysis, setRequiresAnalysis] = useState(false)
    const [showPdfModal, setShowPdfModal] = useState(false)
    const [pdfAsset, setPdfAsset] = useState(inputs.pdf)
    const pollRef = useRef(null)

    const accentColor = brand.primary_color || '#6366f1'

    const effectiveStatus = polledStatus || status
    const effectiveResults = polledResults || results
    const isFinalized = effectiveStatus.research_finalized
    const isStuckOrFailed = health.state === 'stuck' || health.state === 'failed'

    // Polling
    useEffect(() => {
        if (!polling) {
            if (pollRef.current) clearInterval(pollRef.current)
            return
        }
        const poll = () => {
            axios.get(route('brands.brand-dna.builder.research-insights', { brand: brand.id }))
                .then(({ data }) => {
                    setPolledStatus({
                        pdf_complete: data.pipelineStatus?.text_extraction_complete ?? effectiveStatus.pdf_complete,
                        snapshot_ready: data.pipelineStatus?.snapshot_persisted ?? false,
                        suggestions_ready: data.pipelineStatus?.suggestions_ready ?? false,
                        research_finalized: data.researchFinalized ?? false,
                    })
                    if (data.latestSnapshot) {
                        setPolledResults({
                            snapshot: data.latestSnapshot,
                            suggestions: data.latestSuggestions ?? [],
                            coherence: data.latestCoherence,
                            alignment: data.latestAlignment,
                        })
                    }

                    if (data.pipeline_error_kind === 'stuck' || data.pipeline_error_kind === 'failed') {
                        setHealth({
                            state: data.pipeline_error_kind,
                            error: data.pipeline_error,
                            can_retry: data.can_retry ?? true,
                        })
                        setPolling(false)
                        return
                    }

                    if (data.researchFinalized) {
                        setHealth({ state: 'completed', error: null, can_retry: false })
                        setPolling(false)
                    }
                })
                .catch(() => {})
        }
        poll()
        pollRef.current = setInterval(poll, 4000)
        return () => { if (pollRef.current) clearInterval(pollRef.current) }
    }, [polling, brand.id])

    const handleAnalyze = useCallback(async () => {
        if (!brandResearchGate?.allowed) return

        const urls = [
            websiteUrl.trim(),
            ...socialUrls.filter(u => u.trim().startsWith('http')),
        ].filter(Boolean)

        setAnalyzing(true)
        setRequiresAnalysis(false)
        setHealth({ state: 'processing', error: null, can_retry: false })
        try {
            await axios.post(route('brands.research.analyze', { brand: brand.id }), {
                pdf_asset_id: pdfAsset?.id ?? null,
                website_url: websiteUrl.trim() || null,
                social_urls: socialUrls.filter(u => u.trim().startsWith('http')),
                material_asset_ids: [],
            })
            setPolling(true)
        } catch (err) {
            console.error('Analysis trigger failed', err)
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id, websiteUrl, socialUrls, pdfAsset, brandResearchGate])

    const handleRerun = useCallback(async () => {
        setAnalyzing(true)
        try {
            await axios.post(route('brands.research.rerun', { brand: brand.id }))
            setHealth({ state: 'processing', error: null, can_retry: false })
            setPolling(true)
        } catch (err) {
            console.error('Re-run failed', err)
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id])

    const handleRetry = useCallback(async () => {
        setAnalyzing(true)
        setHealth({ state: 'processing', error: null, can_retry: false })
        try {
            await axios.post(route('brands.research.rerun', { brand: brand.id }))
            setPolling(true)
        } catch (err) {
            console.error('Retry failed', err)
            setHealth(prev => ({ ...prev, state: 'failed', error: 'Retry request failed. Please try again.' }))
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id])

    const handleAdvanceToReview = useCallback(async () => {
        try {
            await axios.post(route('brands.research.advance-to-review', { brand: brand.id }))
            router.visit(route('brands.review.show', { brand: brand.id }))
        } catch (err) {
            console.error('Advance to review failed', err)
        }
    }, [brand.id])

    const handleUrlChange = useCallback((setter) => (e) => {
        setter(e.target.value)
        setRequiresAnalysis(true)
    }, [])

    const handleSocialUrlChange = useCallback((idx, value) => {
        setSocialUrls(prev => {
            const next = [...prev]
            next[idx] = value
            return next
        })
        setRequiresAnalysis(true)
    }, [])

    const addSocialUrl = useCallback(() => {
        setSocialUrls(prev => [...prev, ''])
    }, [])

    const removeSocialUrl = useCallback((idx) => {
        setSocialUrls(prev => prev.filter((_, i) => i !== idx))
    }, [])

    const snapshot = effectiveResults?.snapshot ?? {}
    const typography = snapshot.typography ?? {}

    // Snapshot data is flat — fields live at the root, not nested under identity/personality/visual
    const extracted = {
        mission: snapshot.mission || snapshot.identity?.mission,
        tagline: snapshot.tagline || snapshot.identity?.tagline,
        positioning: snapshot.positioning || snapshot.identity?.positioning,
        industry: snapshot.industry || snapshot.identity?.industry,
        target_audience: snapshot.target_audience || snapshot.identity?.target_audience,
        brand_bio: snapshot.brand_bio,
        archetype: snapshot.primary_archetype || snapshot.personality?.primary_archetype,
        tone_keywords: snapshot.tone_keywords || snapshot.personality?.tone_keywords || snapshot.scoring_rules?.tone_keywords,
        voice_description: snapshot.voice_description || snapshot.personality?.voice_description,
        brand_look: snapshot.brand_look || snapshot.personality?.brand_look,
        visual_style: snapshot.visual_style || snapshot.visual?.visual_style,
        photography_style: snapshot.photography_style || snapshot.visual?.photography_style,
        primary_colors: snapshot.primary_colors || snapshot.visual?.primary_colors || [],
        secondary_colors: snapshot.secondary_colors || snapshot.visual?.secondary_colors || [],
        primary_font: typography.primary_font,
        secondary_font: typography.secondary_font,
    }
    const hasExtractedData = Object.values(extracted).some(v => v && (!Array.isArray(v) || v.length > 0))

    const canContinue = isFinalized && !requiresAnalysis && !isStuckOrFailed
    const isProcessing = (polling || analyzing) && !isStuckOrFailed

    return (
        <>
            <AppHead title={`Research — ${brand.name}`} />
            <div className="min-h-screen bg-[#0B0B0D] relative">
                {/* Cinematic background — brand-driven radial gradients */}
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(ellipse at 20% 0%, ${accentColor}30, transparent 70%), radial-gradient(ellipse at 80% 100%, ${accentColor}20, transparent 60%), #0B0B0D`,
                    }}
                />
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 60% 30%, ${accentColor}12, transparent 50%)`,
                    }}
                />
                {/* Depth overlays */}
                <div className="fixed inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/20" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/40" />
                </div>
                {/* Film grain */}
                <div
                    className="fixed inset-0 opacity-[0.03] pointer-events-none"
                    style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E")`,
                    }}
                />

                <div className="relative z-10">
                <AppNav />

                <div className="max-w-5xl mx-auto px-6 pt-10 pb-32">
                    {/* Header */}
                    <div className="mb-10">
                        <div className="flex items-center gap-3 mb-2">
                            <span className="text-white/40 text-sm">v{version.version_number}</span>
                            <StatusBadge status={isStuckOrFailed ? health.state : (isFinalized ? 'complete' : version.research_status)} />
                            {health.elapsed_seconds != null && health.state !== 'idle' && health.state !== 'completed' && (
                                <span className="text-white/30 text-xs">
                                    {formatElapsed(health.elapsed_seconds)} elapsed
                                </span>
                            )}
                        </div>
                        <h1 className="text-3xl font-bold text-white tracking-tight">
                            Brand Research
                        </h1>
                        <p className="text-white/50 mt-2 text-base">
                            Upload reference materials and analyze your brand presence. Results feed into
                            the brand guidelines builder.
                        </p>
                    </div>

                    <div className="space-y-8">
                        {/* Section 1 — Inputs */}
                        <SectionCard title="Research Inputs">
                            <div className="space-y-6">
                                {/* PDF Upload */}
                                <div>
                                    <label className="block text-sm font-medium text-white/70 mb-2">
                                        Brand Guidelines PDF
                                    </label>
                                    {pdfAsset ? (
                                        <div className="flex items-center gap-3 p-3 rounded-lg bg-white/[0.03] border border-white/10">
                                            <svg className="w-5 h-5 text-white/40 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            <span className="text-sm text-white/70 truncate flex-1">{pdfAsset.filename}</span>
                                            <button
                                                onClick={() => setShowPdfModal(true)}
                                                className="text-xs text-white/40 hover:text-white/70 transition"
                                            >
                                                Replace
                                            </button>
                                        </div>
                                    ) : (
                                        <button
                                            onClick={() => setShowPdfModal(true)}
                                            className="w-full p-4 rounded-lg border-2 border-dashed border-white/10 hover:border-white/20 text-white/40 hover:text-white/60 transition text-sm"
                                        >
                                            Upload PDF
                                        </button>
                                    )}
                                </div>

                                {/* Website URL */}
                                <div>
                                    <label className="block text-sm font-medium text-white/70 mb-2">
                                        Website URL
                                    </label>
                                    <input
                                        type="url"
                                        value={websiteUrl}
                                        onChange={handleUrlChange(setWebsiteUrl)}
                                        placeholder="https://yourbrand.com"
                                        className="w-full px-4 py-2.5 rounded-lg bg-white/[0.04] border border-white/10 text-white/90 placeholder-white/30 text-sm focus:outline-none focus:border-white/20"
                                    />
                                </div>

                                {/* Social URLs */}
                                <div>
                                    <label className="block text-sm font-medium text-white/70 mb-2">
                                        Social Media URLs
                                    </label>
                                    <div className="space-y-2">
                                        {socialUrls.map((url, idx) => (
                                            <div key={idx} className="flex gap-2">
                                                <input
                                                    type="url"
                                                    value={url}
                                                    onChange={(e) => handleSocialUrlChange(idx, e.target.value)}
                                                    placeholder="https://instagram.com/yourbrand"
                                                    className="flex-1 px-4 py-2.5 rounded-lg bg-white/[0.04] border border-white/10 text-white/90 placeholder-white/30 text-sm focus:outline-none focus:border-white/20"
                                                />
                                                {socialUrls.length > 1 && (
                                                    <button
                                                        onClick={() => removeSocialUrl(idx)}
                                                        className="px-2 text-white/30 hover:text-red-400 transition"
                                                    >
                                                        ×
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                        <button
                                            onClick={addSocialUrl}
                                            className="text-xs text-white/40 hover:text-white/60 transition"
                                        >
                                            + Add social URL
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </SectionCard>

                        {/* Section 2 — Processing Status */}
                        <SectionCard title="Processing Status">
                            <div className="space-y-3">
                                {(() => {
                                    const pdfDone = effectiveStatus.pdf_complete
                                    const snapDone = effectiveStatus.snapshot_ready
                                    const sugDone = effectiveStatus.suggestions_ready

                                    const pdfStatus = pdfDone ? 'completed'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const snapStatus = snapDone ? 'completed'
                                        : !pdfDone ? 'pending'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const sugStatus = sugDone ? 'completed'
                                        : !snapDone ? 'pending'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    return (
                                        <>
                                            <div className="flex items-center justify-between py-2">
                                                <span className="text-sm text-white/70">PDF Extraction</span>
                                                <StatusBadge status={pdfStatus} />
                                            </div>
                                            <div className="flex items-center justify-between py-2">
                                                <span className="text-sm text-white/70">Snapshot Generation</span>
                                                <StatusBadge status={snapStatus} />
                                            </div>
                                            <div className="flex items-center justify-between py-2">
                                                <span className="text-sm text-white/70">AI Analysis</span>
                                                <StatusBadge status={sugStatus} />
                                            </div>
                                        </>
                                    )
                                })()}

                                {/* Elapsed time */}
                                {health.started_at && (health.state === 'processing' || isStuckOrFailed) && (
                                    <div className="pt-2 border-t border-white/5 flex items-center justify-between">
                                        <span className="text-xs text-white/40">
                                            Started {timeAgo(health.started_at)}
                                        </span>
                                        {health.last_activity_at && (
                                            <span className="text-xs text-white/30">
                                                Last activity {timeAgo(health.last_activity_at)}
                                            </span>
                                        )}
                                    </div>
                                )}

                                {/* Stuck / Failed alert */}
                                {isStuckOrFailed && (
                                    <div className={`mt-2 rounded-lg p-3 flex items-start gap-3 ${
                                        health.state === 'stuck'
                                            ? 'bg-amber-500/10 border border-amber-500/20'
                                            : 'bg-red-500/10 border border-red-500/20'
                                    }`}>
                                        <svg className={`w-5 h-5 flex-shrink-0 mt-0.5 ${health.state === 'stuck' ? 'text-amber-400' : 'text-red-400'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                        <div className="flex-1 min-w-0">
                                            <p className={`text-sm font-medium ${health.state === 'stuck' ? 'text-amber-200' : 'text-red-200'}`}>
                                                {health.state === 'stuck' ? 'Processing appears stuck' : 'Processing failed'}
                                            </p>
                                            {health.error && (
                                                <p className={`text-xs mt-0.5 ${health.state === 'stuck' ? 'text-amber-200/60' : 'text-red-200/60'}`}>
                                                    {health.error}
                                                </p>
                                            )}
                                        </div>
                                        {health.can_retry && (
                                            <button
                                                onClick={handleRetry}
                                                disabled={analyzing}
                                                className="flex-shrink-0 px-3 py-1.5 rounded-md text-xs font-medium text-white bg-white/10 hover:bg-white/20 transition disabled:opacity-50"
                                            >
                                                Retry
                                            </button>
                                        )}
                                    </div>
                                )}

                                {/* Real progress bar only when actively processing */}
                                {isProcessing && !isStuckOrFailed && (
                                    <div className="pt-2">
                                        <div className="h-1 w-full bg-white/10 rounded-full overflow-hidden">
                                            <motion.div
                                                className="h-full rounded-full"
                                                style={{ backgroundColor: accentColor }}
                                                initial={{ width: '5%' }}
                                                animate={{
                                                    width: effectiveStatus.suggestions_ready ? '100%'
                                                        : effectiveStatus.snapshot_ready ? '75%'
                                                        : effectiveStatus.pdf_complete ? '40%'
                                                        : '15%'
                                                }}
                                                transition={{ duration: 0.8, ease: 'easeOut' }}
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>
                        </SectionCard>

                        {/* Section 3 — Results */}
                        {effectiveStatus.snapshot_ready && hasExtractedData && (
                            <SectionCard title="What We Found">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                                    <ResultField label="Mission" value={extracted.mission} />
                                    <ResultField label="Industry" value={extracted.industry} />
                                    <ResultField label="Tagline" value={extracted.tagline} />
                                    <ResultField label="Target Audience" value={extracted.target_audience} />
                                    <ResultField label="Positioning" value={extracted.positioning} />
                                    <ResultField label="Brand Look" value={extracted.brand_look} />
                                    <ResultField label="Voice" value={extracted.voice_description} />
                                    <ResultField label="Visual Style" value={extracted.visual_style} />
                                    <ResultField label="Photography" value={extracted.photography_style} />
                                    {extracted.tone_keywords && (
                                        <div className="flex flex-col gap-1 py-2">
                                            <span className="text-xs font-medium text-white/50 uppercase tracking-wider">Tone</span>
                                            <div className="flex flex-wrap gap-1.5">
                                                {(Array.isArray(extracted.tone_keywords) ? extracted.tone_keywords : [extracted.tone_keywords]).map((t, i) => (
                                                    <span key={i} className="px-2 py-0.5 rounded-md bg-white/[0.06] text-xs text-white/60">{t}</span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Colors + Fonts */}
                                {(extracted.primary_colors.length > 0 || extracted.primary_font) && (
                                    <div className="mt-5 pt-4 border-t border-white/5 flex flex-wrap gap-6">
                                        {extracted.primary_colors.length > 0 && (
                                            <div>
                                                <span className="text-xs text-white/40 block mb-1.5">Colors</span>
                                                <div className="flex gap-1.5">
                                                    {[...extracted.primary_colors, ...extracted.secondary_colors].map((color, i) => (
                                                        <div
                                                            key={i}
                                                            className="w-7 h-7 rounded-lg border border-white/10"
                                                            style={{ backgroundColor: color }}
                                                            title={color}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {extracted.primary_font && (
                                            <div>
                                                <span className="text-xs text-white/40 block mb-1.5">Primary Font</span>
                                                <span className="text-sm text-white/70">{extracted.primary_font}</span>
                                            </div>
                                        )}
                                        {extracted.secondary_font && (
                                            <div>
                                                <span className="text-xs text-white/40 block mb-1.5">Secondary Font</span>
                                                <span className="text-sm text-white/70">{extracted.secondary_font}</span>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Suggestion count hint */}
                                {(effectiveResults?.suggestions?.items ?? effectiveResults?.suggestions)?.length > 0 && (
                                    <div className="mt-4 pt-3 border-t border-white/5 flex items-center gap-2">
                                        <svg className="w-4 h-4 text-white/30" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth={1.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                                        </svg>
                                        <span className="text-xs text-white/40">
                                            AI suggestions will be applied in the next step
                                        </span>
                                    </div>
                                )}
                            </SectionCard>
                        )}

                        {effectiveStatus.snapshot_ready && !hasExtractedData && (
                            <SectionCard title="Extracted Results">
                                <p className="text-sm text-white/40">
                                    Analysis completed but no brand data could be extracted. Try uploading a different PDF or adding a website URL.
                                </p>
                            </SectionCard>
                        )}

                        {/* Warnings */}
                        {requiresAnalysis && !isProcessing && (
                            <div className="rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-3">
                                <svg className="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                                <div>
                                    <p className="text-sm text-amber-200 font-medium">URLs changed but not analyzed</p>
                                    <p className="text-xs text-amber-200/60 mt-0.5">Run analysis to update research results before continuing.</p>
                                </div>
                            </div>
                        )}

                        {!brandResearchGate?.allowed && (
                            <div className="rounded-xl bg-white/[0.04] border border-white/10 p-4 text-center">
                                <p className="text-sm text-white/60">
                                    {brandResearchGate?.is_disabled
                                        ? 'AI brand research requires a paid plan.'
                                        : `Monthly research limit reached (${brandResearchGate?.usage}/${brandResearchGate?.cap}).`
                                    }
                                </p>
                            </div>
                        )}

                        {/* Pipeline Runs */}
                        {runs?.length > 0 && (
                            <SectionCard title="Pipeline Runs">
                                <div className="space-y-2">
                                    {runs.map(run => {
                                        const isRunStuck = run.status === 'processing' && run.updated_at
                                            && (Date.now() - new Date(run.updated_at).getTime()) > 5 * 60 * 1000
                                        const displayStatus = isRunStuck ? 'stuck' : run.status
                                        const elapsed = run.completed_at && run.created_at
                                            ? formatElapsed(Math.floor((new Date(run.completed_at) - new Date(run.created_at)) / 1000))
                                            : run.status === 'processing' && run.created_at
                                                ? formatElapsed(Math.floor((Date.now() - new Date(run.created_at).getTime()) / 1000))
                                                : null
                                        return (
                                            <div key={run.id} className="flex items-center justify-between py-2 text-sm">
                                                <div className="flex items-center gap-3">
                                                    <span className="text-white/50 font-mono text-xs">#{run.id}</span>
                                                    <span className="text-white/60">{run.extraction_mode}</span>
                                                    {run.pages_total > 0 && run.status === 'processing' && (
                                                        <span className="text-white/30 text-xs">
                                                            {run.pages_processed}/{run.pages_total} pages
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    {elapsed && (
                                                        <span className="text-white/30 text-xs">{elapsed}</span>
                                                    )}
                                                    <span className="text-white/40 text-xs">
                                                        {run.created_at ? new Date(run.created_at).toLocaleString() : ''}
                                                    </span>
                                                    <StatusBadge status={displayStatus} />
                                                </div>
                                            </div>
                                        )
                                    })}
                                </div>
                            </SectionCard>
                        )}
                    </div>
                </div>
                </div>{/* end z-10 content wrapper */}

                {/* Sticky Footer */}
                <div className="fixed bottom-0 inset-x-0 z-20 bg-[#0B0B0D]/80 backdrop-blur-xl border-t border-white/[0.06]">
                    <div className="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
                        <button
                            onClick={() => router.visit(route('brands.guidelines.index', { brand: brand.id }))}
                            className="px-4 py-2 text-sm text-white/50 hover:text-white/70 transition"
                        >
                            Back to Guidelines
                        </button>

                        <div className="flex items-center gap-3">
                            {isFinalized && !isStuckOrFailed && (
                                <button
                                    onClick={handleRerun}
                                    disabled={analyzing}
                                    className="px-4 py-2 rounded-lg text-sm text-white/60 hover:text-white/80 border border-white/10 hover:border-white/20 transition disabled:opacity-50"
                                >
                                    Re-run Analysis
                                </button>
                            )}

                            {isStuckOrFailed && health.can_retry && (
                                <button
                                    onClick={handleRetry}
                                    disabled={analyzing}
                                    className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-50"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    {analyzing ? 'Retrying…' : 'Retry Analysis'}
                                </button>
                            )}

                            {!isFinalized && !isStuckOrFailed && (
                                <button
                                    onClick={handleAnalyze}
                                    disabled={analyzing || !brandResearchGate?.allowed}
                                    className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-50"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    {analyzing ? 'Analyzing…' : 'Run Analysis'}
                                </button>
                            )}

                            <button
                                onClick={handleAdvanceToReview}
                                disabled={!canContinue}
                                className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-30"
                                style={{ backgroundColor: canContinue ? accentColor : undefined }}
                            >
                                {isStuckOrFailed ? 'Pipeline Error — Retry Above'
                                    : isProcessing ? 'Processing…'
                                    : requiresAnalysis ? 'Analysis Required'
                                    : 'Continue to Review →'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {showPdfModal && (
                <BuilderAssetSelectorModal
                    brandId={brand.id}
                    context="guidelines_pdf"
                    accept=".pdf"
                    title="Upload Brand Guidelines PDF"
                    onClose={() => setShowPdfModal(false)}
                    onSelect={(asset) => {
                        setPdfAsset({ id: asset.id, filename: asset.original_filename })
                        setShowPdfModal(false)
                        setRequiresAnalysis(true)
                    }}
                />
            )}
        </>
    )
}

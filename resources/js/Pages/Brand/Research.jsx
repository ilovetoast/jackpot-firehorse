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
import ConfirmDialog from '../../Components/ConfirmDialog'
import { normalizeWebsiteUrl } from '../../utils/websiteUrl'
import axios from 'axios'
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline'

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

function formatTypicalDurationSeconds(sec) {
    if (sec == null || sec < 1) return null
    const s = Math.round(sec)
    if (s < 60) return `~${s}s`
    const m = Math.floor(s / 60)
    const r = s % 60
    return r > 0 ? `~${m}m ${r}s` : `~${m}m`
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

/** Subtitle for PDF row from /research-insights `pdf` payload */
function formatPdfPipelineDetail(pdf) {
    if (!pdf || typeof pdf !== 'object') return null
    if (pdf.status === 'completed' || pdf.status === 'failed') return null
    const mode =
        pdf.extraction_mode === 'vision'
            ? 'Vision (page-by-page)'
            : pdf.extraction_mode === 'text'
              ? 'Text extraction'
              : 'PDF pipeline'
    if (pdf.pages_total > 0) {
        return `${mode} · page ${pdf.pages_processed ?? 0} of ${pdf.pages_total}`
    }
    if (pdf.stage && pdf.stage !== 'completed') {
        return `${mode} · ${pdf.stage}`
    }
    if (pdf.status === 'processing' || pdf.status === 'pending') {
        const pct = pdf.progress_percent != null ? ` · ~${pdf.progress_percent}%` : ' · running…'
        return `${mode}${pct}`
    }
    return null
}

/** What the system is doing after the snapshot exists but before research is fully finalized */
function formatEnrichmentPhase(pipelineStatus) {
    if (!pipelineStatus?.snapshot_persisted || pipelineStatus.research_finalized) return null
    if (!pipelineStatus.suggestions_ready) return 'Generating suggestions…'
    if (!pipelineStatus.coherence_ready) return 'Coherence scoring…'
    if (!pipelineStatus.alignment_ready) return 'Alignment scoring…'
    return 'Finalizing research…'
}

/** ResearchProgressService uses `complete`; StatusBadge prefers `completed` for labels */
function mapStageStatusForBadge(status) {
    if (status === 'complete') return 'completed'
    if (status === 'failed') return 'failed'
    if (status === 'processing') return 'processing'
    return 'pending'
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
    const { headlineAppearanceCatalog = [] } = usePage().props
    const [websiteUrl, setWebsiteUrl] = useState(inputs.website_url || '')
    const [analyzing, setAnalyzing] = useState(false)

    const initialHealthState = initialPipelineHealth?.state || 'idle'
    const shouldPoll = initialHealthState === 'processing' && !status.research_finalized

    const [polling, setPolling] = useState(shouldPoll)
    const [polledStatus, setPolledStatus] = useState(status)
    const [polledResults, setPolledResults] = useState(results)
    const [health, setHealth] = useState(initialPipelineHealth || { state: 'idle', error: null, can_retry: false })
    const [inputsDirty, setInputsDirty] = useState(false)
    const [showPdfModal, setShowPdfModal] = useState(false)
    const [showStartOverConfirm, setShowStartOverConfirm] = useState(false)
    /** After user clicks combined CTA, auto-advance to Review when research_finalized */
    const [pendingAdvanceAfterRun, setPendingAdvanceAfterRun] = useState(false)
    /** Rich status from GET research-insights (stages, PDF pages, snapshot/crawl) */
    const [pipelineLive, setPipelineLive] = useState(null)
    const [pdfAsset, setPdfAsset] = useState(inputs.pdf)
    const [pdfDragOver, setPdfDragOver] = useState(false)
    const [pdfUploading, setPdfUploading] = useState(false)
    const [pdfUploadName, setPdfUploadName] = useState('')
    const [pdfUploadError, setPdfUploadError] = useState(null)
    /** Collapsed by default — primary path is Continue / Review; re-run lives inside */
    const [pipelineRunsExpanded, setPipelineRunsExpanded] = useState(false)
    const pdfInputRef = useRef(null)
    const pollRef = useRef(null)

    const primaryColor = brand.primary_color || '#6366f1'
    const secondaryColor = brand.secondary_color || '#8b5cf6'

    const effectiveStatus = polledStatus || status
    const effectiveResults = polledResults || results
    const isFinalized = effectiveStatus.research_finalized
    const isStuckOrFailed = health.state === 'stuck' || health.state === 'failed'

    const applyResearchInsights = useCallback((data) => {
        setPolledStatus((prev) => ({
            pdf_complete: data.pipelineStatus?.text_extraction_complete ?? prev.pdf_complete,
            snapshot_ready: data.pipelineStatus?.snapshot_persisted ?? prev.snapshot_ready,
            suggestions_ready: data.pipelineStatus?.suggestions_ready ?? prev.suggestions_ready,
            research_finalized: data.researchFinalized ?? prev.research_finalized,
        }))
        setPipelineLive({
            processing_progress: data.processing_progress,
            pipeline_duration_estimate: data.pipeline_duration_estimate,
            pipeline_timing: data.pipeline_timing ?? null,
            pdf: data.pdf,
            runningSnapshotLite: data.runningSnapshotLite,
            crawlerRunning: data.crawlerRunning,
            pipelineStatus: data.pipelineStatus,
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
    }, [])

    useEffect(() => {
        document.documentElement.classList.add('scrollbar-cinematic')
        return () => document.documentElement.classList.remove('scrollbar-cinematic')
    }, [])

    /**
     * Inertia may reuse this page without remounting after "Start over" (new draft).
     * Reset all derived research UI so we don't show the previous draft's pipeline / results.
     */
    useEffect(() => {
        if (!version?.id) return
        setPolledStatus(status)
        setPolledResults(results)
        setPipelineLive(null)
        setHealth(initialPipelineHealth || { state: 'idle', error: null, can_retry: false })
        const shouldPollNow = (initialPipelineHealth?.state === 'processing') && !status.research_finalized
        setPolling(shouldPollNow)
        setPendingAdvanceAfterRun(false)
        setPdfAsset(inputs?.pdf ?? null)
        setWebsiteUrl(inputs?.website_url ?? '')
        setInputsDirty(false)
        setPdfUploadError(null)
        setAnalyzing(false)
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentionally only when draft version changes
    }, [version.id])

    // Hydrate detailed pipeline as soon as the page loads (even before polling interval)
    useEffect(() => {
        if (!brand?.id || status.research_finalized) return
        axios
            .get(route('brands.brand-dna.builder.research-insights', { brand: brand.id }))
            .then(({ data }) => {
                applyResearchInsights(data)
                const err = data.pipeline_error_kind
                const busy =
                    data.ingestionProcessing ||
                    data.crawlerRunning ||
                    data.pdf?.status === 'processing'
                if (!data.researchFinalized && err !== 'stuck' && err !== 'failed' && busy) {
                    setPolling(true)
                }
            })
            .catch(() => {})
    }, [brand.id, version.id, status.research_finalized, applyResearchInsights])

    // Polling
    useEffect(() => {
        if (!polling) {
            if (pollRef.current) clearInterval(pollRef.current)
            return
        }
        const poll = () => {
            axios
                .get(route('brands.brand-dna.builder.research-insights', { brand: brand.id }))
                .then(({ data }) => applyResearchInsights(data))
                .catch(() => {})
        }
        poll()
        pollRef.current = setInterval(poll, 4000)
        return () => { if (pollRef.current) clearInterval(pollRef.current) }
    }, [polling, brand.id, version.id, applyResearchInsights])

    const handleAnalyze = useCallback(async () => {
        if (!brandResearchGate?.allowed) return

        setAnalyzing(true)
        setPipelineLive(null)
        setInputsDirty(false)
        setHealth({ state: 'processing', error: null, can_retry: false })
        setPolledStatus(prev => ({
            ...prev,
            pdf_complete: false,
            snapshot_ready: false,
            suggestions_ready: false,
            research_finalized: false,
        }))
        try {
            const normalizedUrl = normalizeWebsiteUrl(websiteUrl)
            await axios.post(route('brands.research.analyze', { brand: brand.id }), {
                pdf_asset_id: pdfAsset?.id ?? null,
                website_url: normalizedUrl,
                social_urls: [],
                material_asset_ids: [],
            })
            if (normalizedUrl && normalizedUrl !== websiteUrl) {
                setWebsiteUrl(normalizedUrl)
            }
            setPolling(true)
        } catch (err) {
            console.error('Analysis trigger failed', err)
            setHealth({ state: 'failed', error: err?.response?.data?.message || 'Analysis request failed.', can_retry: true })
            setPendingAdvanceAfterRun(false)
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id, websiteUrl, pdfAsset, brandResearchGate])

    const handleRerun = useCallback(async () => {
        setAnalyzing(true)
        setPipelineLive(null)
        setHealth({ state: 'processing', error: null, can_retry: false })
        setPolledStatus(prev => ({
            ...prev,
            pdf_complete: false,
            snapshot_ready: false,
            suggestions_ready: false,
            research_finalized: false,
        }))
        try {
            await axios.post(route('brands.research.rerun', { brand: brand.id }))
            setPolling(true)
        } catch (err) {
            console.error('Re-run failed', err)
            setHealth({ state: 'failed', error: err?.response?.data?.message || 'Re-run request failed.', can_retry: true })
            setPendingAdvanceAfterRun(false)
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id])

    const handleRetry = useCallback(async () => {
        setAnalyzing(true)
        setPipelineLive(null)
        setHealth({ state: 'processing', error: null, can_retry: false })
        setPolledStatus(prev => ({
            ...prev,
            pdf_complete: false,
            snapshot_ready: false,
            suggestions_ready: false,
            research_finalized: false,
        }))
        try {
            await axios.post(route('brands.research.rerun', { brand: brand.id }))
            setPolling(true)
        } catch (err) {
            console.error('Retry failed', err)
            setHealth({ state: 'failed', error: 'Retry request failed. Please try again.', can_retry: true })
            setPendingAdvanceAfterRun(false)
        } finally {
            setAnalyzing(false)
        }
    }, [brand.id])

    const handlePdfFileDrop = useCallback(async (file) => {
        if (!file) return
        setPdfUploading(true)
        setPdfUploadName(file.name)
        setPdfUploadError(null)
        try {
            const { data: initData } = await axios.post('/app/uploads/initiate', {
                file_name: file.name,
                file_size: file.size,
                mime_type: file.type || 'application/pdf',
                brand_id: brand.id,
                builder_staged: true,
                builder_context: 'guidelines_pdf',
            })

            await fetch(initData.upload_url, {
                method: 'PUT',
                headers: { 'Content-Type': file.type || 'application/pdf' },
                body: file,
            }).then(r => { if (!r.ok) throw new Error(`Upload failed: ${r.status}`) })

            const { data: finalData } = await axios.post('/app/assets/upload/finalize', {
                manifest: [{
                    upload_key: initData.upload_key ?? `temp/uploads/${initData.upload_session_id}/original`,
                    expected_size: file.size,
                    resolved_filename: file.name,
                }],
            })
            const assetId = finalData.results?.[0]?.asset_id ?? finalData.results?.[0]?.id
            if (assetId) {
                setPdfAsset({ id: assetId, filename: file.name, size_bytes: file.size })
                setInputsDirty(true)
            }
        } catch (err) {
            const errData = err?.response?.data
            const msg = errData?.message || err?.message || 'Upload failed. Please try again.'
            setPdfUploadError(msg)
            console.error('[Research] PDF upload failed:', errData || err)
        } finally {
            setPdfUploading(false)
            setPdfUploadName('')
        }
    }, [brand.id])

    const handleUrlChange = useCallback((setter) => (e) => {
        setter(e.target.value)
        setInputsDirty(true)
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
        headline_treatment: typography.headline_treatment,
        headline_appearance_features: Array.isArray(typography.headline_appearance_features) ? typography.headline_appearance_features : [],
        heading_style: typography.heading_style,
    }
    const hasExtractedData = Object.values(extracted).some(v => v && (!Array.isArray(v) || v.length > 0))

    const inputsChangedAfterAnalysis = inputsDirty && isFinalized
    const canContinue = isFinalized && !inputsChangedAfterAnalysis && !isStuckOrFailed
    const isProcessing = (polling || analyzing) && !isStuckOrFailed
    const hasResearchInputs = Boolean(pdfAsset || normalizeWebsiteUrl(websiteUrl))

    /** Hide the whole Processing Status card until the user adds inputs or a run is active / finalized */
    const showProcessingStatusSection =
        hasResearchInputs ||
        isFinalized ||
        isProcessing ||
        isStuckOrFailed ||
        polling ||
        version.research_status === 'running'

    const canSkipResearch =
        Boolean(brandResearchGate?.allowed) &&
        !hasResearchInputs &&
        !isFinalized &&
        !isProcessing &&
        !isStuckOrFailed &&
        !polling &&
        version.research_status !== 'running' &&
        version.lifecycle_stage === 'research'

    const handleSkipToReview = useCallback(async () => {
        if (!canSkipResearch) return
        try {
            await axios.post(route('brands.research.advance-to-review', { brand: brand.id }), {
                skip_research: true,
            })
            router.visit(route('brands.review.show', { brand: brand.id }))
        } catch (err) {
            const msg = err?.response?.data?.error || err?.response?.data?.message || 'Could not continue without research.'
            console.error('[Research] Skip to review failed', err)
            alert(msg)
        }
    }, [brand.id, canSkipResearch])

    const handleAdvanceToReview = useCallback(async () => {
        try {
            await axios.post(route('brands.research.advance-to-review', { brand: brand.id }))
            router.visit(route('brands.review.show', { brand: brand.id }))
        } catch (err) {
            console.error('Advance to review failed', err)
        }
    }, [brand.id])

    // When combined flow finishes research, go to Review automatically
    useEffect(() => {
        if (!pendingAdvanceAfterRun) return
        if (isStuckOrFailed) return
        if (!canContinue) return
        setPendingAdvanceAfterRun(false)
        handleAdvanceToReview()
    }, [pendingAdvanceAfterRun, canContinue, isStuckOrFailed, handleAdvanceToReview])

    useEffect(() => {
        if (isStuckOrFailed && pendingAdvanceAfterRun) {
            setPendingAdvanceAfterRun(false)
        }
    }, [isStuckOrFailed, pendingAdvanceAfterRun])

    const handleAnalyzeAndContinue = useCallback(async () => {
        if (canContinue) {
            await handleAdvanceToReview()
            return
        }
        if (isStuckOrFailed) return
        if (!brandResearchGate?.allowed) return
        if (!hasResearchInputs) return

        setPendingAdvanceAfterRun(true)
        if (isFinalized && inputsChangedAfterAnalysis) {
            setAnalyzing(true)
            setPipelineLive(null)
            setHealth({ state: 'processing', error: null, can_retry: false })
            setPolledStatus((prev) => ({
                ...prev,
                pdf_complete: false,
                snapshot_ready: false,
                suggestions_ready: false,
                research_finalized: false,
            }))
            try {
                await axios.post(route('brands.research.rerun', { brand: brand.id }))
                setPolling(true)
            } catch (err) {
                console.error('Re-run failed', err)
                setHealth({ state: 'failed', error: err?.response?.data?.message || 'Re-run request failed.', can_retry: true })
                setPendingAdvanceAfterRun(false)
            } finally {
                setAnalyzing(false)
            }
            return
        }
        await handleAnalyze()
        // handleAnalyze clears analyzing; on axios failure it sets health — pending cleared by stuck/failed effect
    }, [
        canContinue,
        isStuckOrFailed,
        brandResearchGate?.allowed,
        hasResearchInputs,
        isFinalized,
        inputsChangedAfterAnalysis,
        brand.id,
        handleAdvanceToReview,
        handleAnalyze,
    ])

    const combinedPrimaryDisabled =
        isStuckOrFailed
            ? true
            : canContinue
              ? false
              : !brandResearchGate?.allowed || !hasResearchInputs || isProcessing || pendingAdvanceAfterRun

    const combinedPrimaryLabel = (() => {
        if (isStuckOrFailed && health.can_retry) {
            return 'Use Retry analysis above'
        }
        if (canContinue && !pendingAdvanceAfterRun) {
            return 'Continue to Review →'
        }
        if (pendingAdvanceAfterRun && (polling || analyzing)) {
            return analyzing ? 'Starting… then continuing to Review' : 'Analyzing… then continuing to Review'
        }
        if (isProcessing && !pendingAdvanceAfterRun) {
            return 'Analysis running…'
        }
        if (!hasResearchInputs) {
            return 'Add inputs to begin'
        }
        if (isFinalized && inputsChangedAfterAnalysis) {
            return 'Re-run analysis & continue to Review →'
        }
        return 'Run analysis & continue to Review →'
    })()

    return (
        <>
            <AppHead title={`Research — ${brand.name}`} />
            <div className="min-h-screen bg-[#0B0B0D] relative">
                {/* Cinematic background — matches Brand Overview glow (primary + secondary) */}
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 20% 20%, ${primaryColor}33, transparent), radial-gradient(circle at 80% 80%, ${secondaryColor}33, transparent), #0B0B0D`,
                    }}
                />
                {/* Left column radial accent (brand primary glow) */}
                <div
                    className="fixed inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 30% 40%, ${primaryColor}14, transparent 60%)`,
                    }}
                />
                {/* Depth overlays */}
                <div className="fixed inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/30" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
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
                            the brand guidelines builder — or continue without research and fill in the next
                            steps manually.
                        </p>
                    </div>

                    <div className="space-y-8">
                        {/* Section 1 — Inputs */}
                        <SectionCard title="Research Inputs">
                            <div className="space-y-6">
                                {(isProcessing || pendingAdvanceAfterRun) && (
                                    <div className="rounded-xl border border-cyan-500/20 bg-cyan-500/5 px-4 py-3">
                                        <p className="text-sm text-cyan-100/90">
                                            <span className="font-medium text-white">While a run is active,</span>{' '}
                                            the jobs below use the PDF and website as they were when analysis started. Changing the fields here does not
                                            reroute the current run — wait for it to finish, then use <span className="text-white/90">Re-run</span> with
                                            new inputs, or <span className="text-white/90">Start over</span> for a clean draft.
                                        </p>
                                        <p className="text-xs text-white/45 mt-2 leading-relaxed">
                                            Status rows update as each stage completes (PDF pipeline → research snapshot &amp; enrichment). They are not a
                                            single “all done” flip at the end. A floating “Processing thumbnails…” toast is usually the asset preview
                                            pipeline for your PDF, separate from this checklist.
                                        </p>
                                    </div>
                                )}
                                {/* PDF Upload with drag-and-drop */}
                                <div>
                                    <label className="block text-sm font-medium text-white/70 mb-2">
                                        Brand Guidelines PDF
                                    </label>
                                    {pdfAsset ? (
                                        <div>
                                            <div className="flex items-center gap-3 p-3 rounded-lg bg-white/[0.03] border border-white/10">
                                                <svg className="w-5 h-5 text-white/40 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                <span className="text-sm text-white/70 truncate flex-1">{pdfAsset.filename}</span>
                                                {pdfAsset.size_bytes && (
                                                    <span className="text-xs text-white/30 flex-shrink-0">
                                                        {(pdfAsset.size_bytes / 1024 / 1024).toFixed(1)} MB
                                                    </span>
                                                )}
                                                <button
                                                    onClick={() => setShowPdfModal(true)}
                                                    className="text-xs text-white/40 hover:text-white/70 transition"
                                                >
                                                    Replace
                                                </button>
                                            </div>
                                            {pdfAsset.size_bytes > 20 * 1024 * 1024 && (
                                                <p className="text-xs text-amber-300/70 mt-1.5 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Large file — visual analysis may take a bit longer to process
                                                </p>
                                            )}
                                        </div>
                                    ) : pdfUploading ? (
                                        <div className="w-full p-6 rounded-lg border-2 border-dashed border-indigo-400/30 bg-indigo-500/5 flex flex-col items-center gap-3">
                                            <div className="animate-spin rounded-full h-6 w-6 border-2 border-indigo-400 border-t-transparent" />
                                            <p className="text-sm text-white/50">Uploading {pdfUploadName}…</p>
                                        </div>
                                    ) : (
                                        <div
                                            className={`relative w-full rounded-lg border-2 border-dashed transition-all cursor-pointer ${
                                                pdfDragOver
                                                    ? 'border-indigo-400/50 bg-indigo-500/10'
                                                    : 'border-white/10 hover:border-white/20'
                                            }`}
                                            onDragOver={(e) => { e.preventDefault(); setPdfDragOver(true) }}
                                            onDragEnter={(e) => { e.preventDefault(); setPdfDragOver(true) }}
                                            onDragLeave={(e) => {
                                                if (e.currentTarget.contains(e.relatedTarget)) return
                                                setPdfDragOver(false)
                                            }}
                                            onDrop={(e) => {
                                                e.preventDefault()
                                                setPdfDragOver(false)
                                                const file = Array.from(e.dataTransfer.files).find(
                                                    f => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf')
                                                )
                                                if (file) handlePdfFileDrop(file)
                                            }}
                                            onClick={() => pdfInputRef.current?.click()}
                                        >
                                            <input
                                                ref={pdfInputRef}
                                                type="file"
                                                accept=".pdf,application/pdf"
                                                className="hidden"
                                                onChange={(e) => {
                                                    const file = e.target.files?.[0]
                                                    if (file) handlePdfFileDrop(file)
                                                    e.target.value = ''
                                                }}
                                            />
                                            <div className="flex flex-col items-center gap-2 py-6 px-4">
                                                <svg className={`w-8 h-8 transition ${pdfDragOver ? 'text-indigo-400' : 'text-white/20'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                                                </svg>
                                                <p className={`text-sm transition ${pdfDragOver ? 'text-indigo-300' : 'text-white/40'}`}>
                                                    {pdfDragOver ? 'Drop PDF here' : 'Drag & drop PDF or click to upload'}
                                                </p>
                                                <p className="text-xs text-white/20">or <button type="button" onClick={(e) => { e.stopPropagation(); setShowPdfModal(true) }} className="text-indigo-400/70 hover:text-indigo-400 underline underline-offset-2">browse library</button></p>
                                            </div>
                                        </div>
                                    )}
                                    {pdfUploadError && (
                                        <div className="mt-2 flex items-start gap-2 rounded-lg bg-red-500/10 border border-red-500/20 px-3 py-2.5">
                                            <svg className="w-4 h-4 text-red-400 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                            </svg>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm text-red-300">{pdfUploadError}</p>
                                            </div>
                                            <button type="button" onClick={() => setPdfUploadError(null)} className="text-red-400/60 hover:text-red-300 shrink-0">
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    )}
                                </div>

                                {/* Website URL */}
                                <div>
                                    <label className="block text-sm font-medium text-white/70 mb-2">
                                        Website URL
                                    </label>
                                    <input
                                        type="text"
                                        inputMode="url"
                                        autoComplete="url"
                                        value={websiteUrl}
                                        onChange={handleUrlChange(setWebsiteUrl)}
                                        onBlur={() => {
                                            const n = normalizeWebsiteUrl(websiteUrl)
                                            if (n && n !== websiteUrl) setWebsiteUrl(n)
                                        }}
                                        placeholder="yourbrand.com or https://yourbrand.com"
                                        className="w-full px-4 py-2.5 rounded-lg bg-white/[0.04] border border-white/10 text-white/90 placeholder-white/30 text-sm focus:outline-none focus:border-white/20"
                                    />
                                    <p className="mt-1.5 text-[11px] text-white/35">
                                        If you omit https://, we&apos;ll add it so the crawl can run.
                                    </p>
                                </div>

                            </div>
                        </SectionCard>

                        {/* Section 2 — Processing Status (only after inputs exist or a run is active / done) */}
                        {showProcessingStatusSection && (
                        <SectionCard title="Processing Status">
                            <div className="space-y-3">
                                {(() => {
                                    const pdfDone = effectiveStatus.pdf_complete
                                    const snapDone = effectiveStatus.snapshot_ready
                                    const sugDone = effectiveStatus.suggestions_ready
                                    const hasPdf = !!pdfAsset
                                    const hasUrls = !!normalizeWebsiteUrl(websiteUrl)
                                    const hasAnyInput = hasPdf || hasUrls

                                    const pdfStatus = !hasPdf ? null
                                        : pdfDone ? 'completed'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const urlStatus = !hasUrls ? null
                                        : snapDone ? 'completed'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const snapStatus = !hasAnyInput ? null
                                        : snapDone ? 'completed'
                                        : (hasPdf && !pdfDone) ? 'pending'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const sugStatus = !hasAnyInput ? null
                                        : sugDone ? 'completed'
                                        : !snapDone ? 'pending'
                                        : isStuckOrFailed ? health.state
                                        : isProcessing ? 'processing' : 'pending'

                                    const pdfDetail = formatPdfPipelineDetail(pipelineLive?.pdf)
                                    const pdfFailMessage =
                                        pipelineLive?.pdf?.status === 'failed'
                                            ? (pipelineLive.pdf.error_message || 'PDF processing failed')
                                            : null
                                    const crawlDetail = pipelineLive?.crawlerRunning
                                        ? (pipelineLive.runningSnapshotLite?.source_url
                                            ? `Snapshot in progress · ${pipelineLive.runningSnapshotLite.source_url}`
                                            : 'Snapshot / crawl in progress…')
                                        : null

                                    if (!hasAnyInput && !isFinalized) {
                                        return (
                                            <div className="py-4 text-center">
                                                <p className="text-sm text-white/40">Upload a PDF or add a URL above to begin research.</p>
                                            </div>
                                        )
                                    }

                                    return (
                                        <>
                                            {pdfStatus && (
                                                <div className="flex items-start justify-between gap-3 py-2">
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <span className="text-sm text-white/70">PDF Extraction</span>
                                                            <span className="text-xs text-white/30 truncate max-w-[200px]">{pdfAsset?.filename}</span>
                                                        </div>
                                                        {pdfDetail && (
                                                            <p className="text-[11px] text-cyan-200/70 mt-1">{pdfDetail}</p>
                                                        )}
                                                        {pdfFailMessage && (
                                                            <p className="text-[11px] text-red-400/90 mt-1">{pdfFailMessage}</p>
                                                        )}
                                                    </div>
                                                    <StatusBadge status={pdfStatus} />
                                                </div>
                                            )}
                                            {urlStatus && hasUrls && (
                                                <div className="flex items-start justify-between py-2 gap-3">
                                                    <div className="min-w-0">
                                                        <span className="text-sm text-white/70">Website crawl</span>
                                                        <p className="text-[11px] text-white/35 truncate max-w-[280px]">{normalizeWebsiteUrl(websiteUrl) || websiteUrl.trim()}</p>
                                                        {crawlDetail && (
                                                            <p className="text-[11px] text-cyan-200/70 mt-1 break-all">{crawlDetail}</p>
                                                        )}
                                                    </div>
                                                    <StatusBadge status={urlStatus} />
                                                </div>
                                            )}
                                            {hasUrls && (
                                                <p className="text-[11px] text-white/30 pt-1">
                                                    Website crawl runs as part of analysis. Some sites block automated access — results may vary.
                                                </p>
                                            )}
                                            {snapStatus && (
                                                <div className="flex items-center justify-between py-2">
                                                    <span className="text-sm text-white/70">Snapshot Generation</span>
                                                    <StatusBadge status={snapStatus} />
                                                </div>
                                            )}
                                            {sugStatus && (
                                                <div className="flex items-center justify-between py-2">
                                                    <span className="text-sm text-white/70">AI Analysis</span>
                                                    <StatusBadge status={sugStatus} />
                                                </div>
                                            )}
                                        </>
                                    )
                                })()}

                                {/* Expected window (when live pipeline UI is still idle — all stages pending) */}
                                {!isFinalized && pipelineLive?.pipeline_timing
                                    && !(pipelineLive?.processing_progress?.stages?.length > 0
                                        && pipelineLive.processing_progress.stages.some((s) => s.status !== 'pending')) && (
                                    <p className="text-[11px] text-white/40 mt-2 pt-2 border-t border-white/5 leading-relaxed">
                                        Expected duration (
                                        {pipelineLive.pipeline_timing.expectation_source === 'median'
                                            ? (pipelineLive.pipeline_duration_estimate?.match === 'similar_size'
                                                ? 'similar PDF size in your workspace'
                                                : 'same extraction mode in your workspace')
                                            : 'default for this extraction mode until enough runs finish in your workspace'}
                                        ):{' '}
                                        <span className="text-cyan-200/80">
                                            {formatTypicalDurationSeconds(pipelineLive.pipeline_timing.expected_seconds)}
                                        </span>
                                        {pipelineLive.pipeline_timing.expectation_source === 'median'
                                            && pipelineLive.pipeline_duration_estimate?.sample_count >= 2 && (
                                            <>
                                                {' · '}
                                                median of {pipelineLive.pipeline_duration_estimate.sample_count} completed runs
                                            </>
                                        )}
                                    </p>
                                )}

                                {/* Live pipeline — hide when all stages still pending (idle / after Start over) */}
                                {!isFinalized && pipelineLive?.processing_progress?.stages?.length > 0
                                    && pipelineLive.processing_progress.stages.some((s) => s.status !== 'pending') && (
                                    <div className="rounded-xl border border-white/10 bg-white/[0.02] p-3 space-y-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-xs font-medium text-white/50 uppercase tracking-wider">Live pipeline</p>
                                            {typeof pipelineLive.processing_progress.overall_percent === 'number' && (
                                                <span className="text-[11px] text-white/40 tabular-nums">
                                                    {Math.round(pipelineLive.processing_progress.overall_percent)}% overall
                                                </span>
                                            )}
                                        </div>
                                        <ul className="space-y-1.5">
                                            {pipelineLive.processing_progress.stages.map((s) => (
                                                <li key={s.key} className="flex items-start justify-between gap-2 text-xs">
                                                    <span className={`min-w-0 ${s.status === 'complete' ? 'text-white/75' : 'text-white/45'}`}>
                                                        {s.label}
                                                        {s.percent != null && s.status === 'processing' && (
                                                            <span className="text-white/30 ml-1">(~{s.percent}%)</span>
                                                        )}
                                                    </span>
                                                    <StatusBadge status={mapStageStatusForBadge(s.status)} />
                                                </li>
                                            ))}
                                        </ul>
                                        {pipelineLive.processing_progress.stages.some((s) => s.error) && (
                                            <div className="pt-1 space-y-0.5 border-t border-white/5">
                                                {pipelineLive.processing_progress.stages
                                                    .filter((s) => s.error)
                                                    .map((s) => (
                                                        <p key={`${s.key}-err`} className="text-[11px] text-red-400/85">
                                                            {s.label}: {s.error}
                                                        </p>
                                                    ))}
                                            </div>
                                        )}
                                        {formatEnrichmentPhase(pipelineLive.pipelineStatus) && (
                                            <p className="text-[11px] text-white/45 pt-1 border-t border-white/5">
                                                {formatEnrichmentPhase(pipelineLive.pipelineStatus)}
                                            </p>
                                        )}
                                        {(pipelineLive.pipeline_timing
                                            || (pipelineLive.pipeline_duration_estimate?.median_seconds != null
                                                && pipelineLive.pipeline_duration_estimate.sample_count >= 2)) && (
                                            <p className="text-[11px] text-white/40 pt-2 border-t border-white/5 leading-relaxed">
                                                {pipelineLive.pipeline_timing ? (
                                                    <>
                                                        Expected duration (
                                                        {pipelineLive.pipeline_timing.expectation_source === 'median'
                                                            ? (pipelineLive.pipeline_duration_estimate?.match === 'similar_size'
                                                                ? 'similar PDF size in your workspace'
                                                                : 'same extraction mode in your workspace')
                                                            : 'default for this extraction mode until enough runs finish in your workspace'}
                                                        ):{' '}
                                                        <span className="text-cyan-200/80">
                                                            {formatTypicalDurationSeconds(
                                                                pipelineLive.pipeline_timing.expected_seconds
                                                            )}
                                                        </span>
                                                        {pipelineLive.pipeline_timing.expectation_source === 'median'
                                                            && pipelineLive.pipeline_duration_estimate?.sample_count >= 2 && (
                                                            <>
                                                                {' · '}
                                                                median of {pipelineLive.pipeline_duration_estimate.sample_count} completed runs
                                                            </>
                                                        )}
                                                    </>
                                                ) : (
                                                    <>
                                                        Typical time (
                                                        {pipelineLive.pipeline_duration_estimate.match === 'similar_size'
                                                            ? 'similar PDF size in your workspace'
                                                            : 'same extraction mode in your workspace'}
                                                        ):{' '}
                                                        <span className="text-cyan-200/80">
                                                            {formatTypicalDurationSeconds(
                                                                pipelineLive.pipeline_duration_estimate.median_seconds
                                                            )}
                                                        </span>
                                                        {' · '}
                                                        median of {pipelineLive.pipeline_duration_estimate.sample_count} completed runs
                                                    </>
                                                )}
                                            </p>
                                        )}
                                    </div>
                                )}

                                {!isFinalized && !isStuckOrFailed && pipelineLive?.pipeline_timing?.slower_than_expected && (
                                    <div className="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-3 mt-3">
                                        <p className="text-sm font-medium text-amber-100">Taking longer than expected</p>
                                        <p className="text-xs text-amber-200/80 mt-1 leading-relaxed">
                                            This run is past the usual window
                                            {pipelineLive.pipeline_timing?.expected_seconds != null && (
                                                <>
                                                    {' '}
                                                    (~
                                                    {formatTypicalDurationSeconds(pipelineLive.pipeline_timing.expected_seconds)}
                                                    {pipelineLive.pipeline_timing.expectation_source === 'median' ? ' typical' : ' baseline'}
                                                    )
                                                </>
                                            )}
                                            . Large PDFs, website crawl, or queue load can add time — you can leave this page. If nothing changes for a long time, try Retry.
                                        </p>
                                    </div>
                                )}

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
                                                disabled={analyzing || polling}
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
                                                style={{ backgroundColor: primaryColor }}
                                                initial={{ width: '5%' }}
                                                animate={{
                                                    width: (() => {
                                                        const p = pipelineLive?.processing_progress?.overall_percent
                                                        if (typeof p === 'number' && !Number.isNaN(p)) {
                                                            return `${Math.min(100, Math.max(3, p))}%`
                                                        }
                                                        return effectiveStatus.suggestions_ready ? '100%'
                                                            : effectiveStatus.snapshot_ready ? '75%'
                                                            : effectiveStatus.pdf_complete ? '40%'
                                                            : '15%'
                                                    })(),
                                                }}
                                                transition={{ duration: 0.8, ease: 'easeOut' }}
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>
                        </SectionCard>
                        )}

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
                                    {extracted.headline_appearance_features?.length > 0 && (
                                        <div className="flex flex-col gap-1 py-2 md:col-span-2">
                                            <span className="text-xs font-medium text-white/50 uppercase tracking-wider">Headline appearance</span>
                                            <div className="flex flex-wrap gap-1.5">
                                                {extracted.headline_appearance_features.map((id) => {
                                                    const label = headlineAppearanceCatalog.find((o) => o.id === id)?.label || id
                                                    return (
                                                        <span key={id} className="px-2 py-0.5 rounded-md bg-cyan-500/15 text-xs text-cyan-200/90 border border-cyan-500/20">
                                                            {label}
                                                        </span>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}
                                    <ResultField label="Headline treatment notes" value={extracted.headline_treatment} />
                                    <ResultField label="Heading style" value={extracted.heading_style} />
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

                        {/* Warning: only when inputs changed AFTER a previous successful analysis */}
                        {inputsChangedAfterAnalysis && !isProcessing && (
                            <div className="rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-3">
                                <svg className="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                </svg>
                                <div>
                                    <p className="text-sm text-amber-200 font-medium">Inputs changed since last analysis</p>
                                    <p className="text-xs text-amber-200/60 mt-0.5">Re-run analysis to update research results before continuing.</p>
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

                        {/* Pipeline Runs — collapsed by default; re-run lives here (not in footer) */}
                        {runs?.length > 0 && (
                            <div className="rounded-2xl bg-white/[0.04] border border-white/[0.06] overflow-hidden">
                                <button
                                    type="button"
                                    onClick={() => setPipelineRunsExpanded((e) => !e)}
                                    className="w-full flex items-center justify-between gap-3 px-6 py-4 text-left hover:bg-white/[0.02] transition"
                                    aria-expanded={pipelineRunsExpanded}
                                >
                                    <div className="flex items-center gap-2 min-w-0">
                                        {pipelineRunsExpanded ? (
                                            <ChevronDownIcon className="h-5 w-5 text-white/40 flex-shrink-0" aria-hidden />
                                        ) : (
                                            <ChevronRightIcon className="h-5 w-5 text-white/40 flex-shrink-0" aria-hidden />
                                        )}
                                        <span className="text-lg font-semibold text-white/90">Pipeline Runs</span>
                                        <span className="text-xs text-white/35 tabular-nums">
                                            ({runs.length})
                                        </span>
                                    </div>
                                    {!pipelineRunsExpanded && runs.length > 0 && (() => {
                                        const latest = runs.reduce((best, r) => (!best || r.id > best.id ? r : best), null)
                                        const short = latest
                                            ? `#${latest.id} ${latest.extraction_mode || ''}`.trim()
                                            : ''
                                        return short ? (
                                            <span className="text-xs text-white/35 truncate max-w-[55%] hidden sm:inline">
                                                {short}
                                            </span>
                                        ) : null
                                    })()}
                                </button>
                                <AnimatePresence initial={false}>
                                    {pipelineRunsExpanded && (
                                        <motion.div
                                            initial={{ opacity: 0 }}
                                            animate={{ opacity: 1 }}
                                            exit={{ opacity: 0 }}
                                            transition={{ duration: 0.15 }}
                                        >
                                            <div className="px-6 pb-6 pt-0 border-t border-white/[0.06]">
                                                <div className="space-y-2 pt-4">
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

                                                {isFinalized && !isStuckOrFailed && (
                                                    <div className="mt-4 pt-4 border-t border-white/[0.06]">
                                                        <p className="text-xs text-white/35 mb-3">
                                                            Need to refresh research without leaving this step? Re-run replaces the current pipeline run.
                                                        </p>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setPendingAdvanceAfterRun(false)
                                                                handleRerun()
                                                            }}
                                                            disabled={analyzing || polling || pendingAdvanceAfterRun}
                                                            className="w-full sm:w-auto px-4 py-2 rounded-lg text-sm text-white/60 hover:text-white/80 border border-white/10 hover:border-white/20 transition disabled:opacity-50"
                                                        >
                                                            {(analyzing || polling) ? 'Re-running…' : 'Re-run only (stay here)'}
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </motion.div>
                                    )}
                                </AnimatePresence>
                            </div>
                        )}
                    </div>
                </div>
                </div>{/* end z-10 content wrapper */}

                {/* Sticky Footer */}
                <div className="fixed bottom-0 inset-x-0 z-20 bg-[#0B0B0D]/80 backdrop-blur-xl border-t border-white/[0.06]">
                    <div className="max-w-5xl mx-auto px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                onClick={() => router.visit(route('brands.guidelines.index', { brand: brand.id }))}
                                className="px-4 py-2 text-sm text-white/50 hover:text-white/70 transition"
                            >
                                Back to Guidelines
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowStartOverConfirm(true)}
                                className="px-4 py-2 text-sm text-white/40 hover:text-white/65 transition"
                            >
                                Start over
                            </button>
                        </div>

                        <div className="flex flex-wrap items-center justify-end gap-3">
                            {isStuckOrFailed && health.can_retry && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPendingAdvanceAfterRun(true)
                                        handleRetry()
                                    }}
                                    disabled={analyzing || polling}
                                    className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-50"
                                    style={{ backgroundColor: primaryColor }}
                                >
                                    {(analyzing || polling) ? 'Retrying…' : 'Retry & continue to Review'}
                                </button>
                            )}

                            {!(isStuckOrFailed && health.can_retry) && (
                                <>
                                    {canSkipResearch && (
                                        <button
                                            type="button"
                                            onClick={handleSkipToReview}
                                            className="px-4 py-2.5 rounded-lg text-sm font-medium text-white/75 border border-white/15 hover:border-white/30 hover:bg-white/[0.06] transition order-first sm:order-none"
                                        >
                                            Continue without research →
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={handleAnalyzeAndContinue}
                                        disabled={combinedPrimaryDisabled}
                                        className="px-5 py-2.5 rounded-lg text-sm font-medium text-white transition disabled:opacity-40 min-w-[14rem] sm:min-w-0"
                                        style={{
                                            backgroundColor:
                                                combinedPrimaryDisabled && !canContinue
                                                    ? undefined
                                                    : primaryColor,
                                        }}
                                        aria-busy={Boolean(pendingAdvanceAfterRun && (polling || analyzing))}
                                    >
                                        {combinedPrimaryLabel}
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <ConfirmDialog
                    open={showStartOverConfirm}
                    onClose={() => setShowStartOverConfirm(false)}
                    onConfirm={() => {
                        setShowStartOverConfirm(false)
                        // Clear immediately so stale pipeline UI doesn't persist until Inertia finishes redirect
                        setPipelineLive(null)
                        setPolling(false)
                        setPendingAdvanceAfterRun(false)
                        setPolledStatus({
                            pdf_complete: false,
                            snapshot_ready: false,
                            suggestions_ready: false,
                            research_finalized: false,
                        })
                        setPolledResults({
                            snapshot: {},
                            suggestions: [],
                            coherence: null,
                            alignment: null,
                        })
                        setHealth({ state: 'idle', error: null, can_retry: false })
                        setPdfAsset(null)
                        setWebsiteUrl('')
                        setInputsDirty(false)
                        setPdfUploadError(null)
                        router.post(route('brands.brand-dna.builder.start', { brand: brand.id }))
                    }}
                    title="Start over?"
                    message="Your current draft will be replaced with a fresh one and you’ll return to research. This cannot be undone."
                    confirmText="Start over"
                    cancelText="Cancel"
                    variant="warning"
                />
            </div>

            <BuilderAssetSelectorModal
                open={showPdfModal}
                brandId={brand.id}
                builderContext="guidelines_pdf"
                title="Upload Brand Guidelines PDF"
                onClose={() => setShowPdfModal(false)}
                onSelect={(asset) => {
                    setPdfAsset({ id: asset.id, filename: asset.original_filename, size_bytes: asset.size_bytes })
                    setShowPdfModal(false)
                    setInputsDirty(true)
                }}
            />
        </>
    )
}

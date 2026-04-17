/**
 * Brand Guidelines processing progress dashboard.
 * Shows overall progress, stage checklist, and activity text.
 * Pipeline is a 3-stage single-pass flow: Upload → Analyze (Claude) → Finalize.
 */
import { useState, useEffect } from 'react'
import { Link, router } from '@inertiajs/react'
import { motion } from 'framer-motion'
import SlotReelLoader from '../SlotReelLoader'
import PoweredByJackpot from '../PoweredByJackpot'

const STAGE_TO_MESSAGES = {
    text_extraction: ['Uploading and preparing your PDF'],
    analyzing: ['Sending document to AI for analysis', 'Extracting brand identity signals', 'Detecting colors and typography', 'Interpreting brand strategy'],
    finalizing: ['Compiling brand intelligence report', 'Generating suggestions and insights'],
}

function formatTypicalDurationSeconds(sec) {
    if (sec == null || sec < 1) return null
    const s = Math.round(sec)
    if (s < 60) return `~${s}s`
    const m = Math.floor(s / 60)
    const r = s % 60
    return r > 0 ? `~${m}m ${r}s` : `~${m}m`
}

export default function ProcessingProgressPanel({
    processingProgress = {},
    accentColor = '#06b6d4',
    brandId = null,
    researchFinalized = false,
    ingestionProcessing = false,
    pipelineError = null,
    durationEstimate = null,
    /** From GET research-insights: elapsed vs expected (median or default baseline) */
    pipelineTiming = null,
}) {
    let {
        overall_percent = 0,
        current_stage = 'text_extraction',
        stages = [],
    } = processingProgress

    if (researchFinalized && !ingestionProcessing && stages.length > 0) {
        stages = stages.map((s) => ({ ...s, status: 'complete', percent: 100 }))
        overall_percent = 100
        current_stage = 'finalizing'
    }

    const hasFailed = pipelineError || stages.some((s) => s.status === 'failed')
    const failedStage = stages.find((s) => s.status === 'failed')

    const [activityIndex, setActivityIndex] = useState(0)
    const [pipelineOpen, setPipelineOpen] = useState(false)
    const [elapsedSec, setElapsedSec] = useState(0)
    const stageMessages = STAGE_TO_MESSAGES[current_stage] || STAGE_TO_MESSAGES.analyzing
    const messages = stageMessages.length > 0 ? stageMessages : STAGE_TO_MESSAGES.analyzing

    useEffect(() => {
        if (researchFinalized || hasFailed) return
        const t0 = Date.now()
        const id = setInterval(() => {
            setElapsedSec(Math.floor((Date.now() - t0) / 1000))
        }, 1000)
        return () => clearInterval(id)
    }, [researchFinalized, hasFailed])

    const elapsedLabel =
        elapsedSec < 60
            ? `${elapsedSec}s`
            : elapsedSec < 3600
              ? `${Math.floor(elapsedSec / 60)}m ${elapsedSec % 60}s`
              : `${Math.floor(elapsedSec / 3600)}h ${Math.floor((elapsedSec % 3600) / 60)}m`

    const showSlowNotice =
        (pipelineTiming?.slower_than_expected === true)
        || (pipelineTiming == null && elapsedSec >= 120 && !researchFinalized && !hasFailed)

    // Time-based progress simulation for stages that are "processing" with no real intermediate updates.
    // Logarithmic curve: fast early progress, slows down, asymptotes at ~92%.
    const [analyzeStartTime] = useState(() => Date.now())
    const [simulatedAnalyzePercent, setSimulatedAnalyzePercent] = useState(15)

    const analyzingStage = stages.find((s) => s.key === 'analyzing')
    const isAnalyzing = analyzingStage?.status === 'processing'

    useEffect(() => {
        if (!isAnalyzing || researchFinalized || hasFailed) return
        const tick = () => {
            const elapsed = (Date.now() - analyzeStartTime) / 1000
            // Logarithmic fill: 15% at 0s, ~50% at 30s, ~70% at 60s, ~85% at 120s, max ~92%
            const progress = Math.min(92, 15 + 77 * (1 - Math.exp(-elapsed / 60)))
            setSimulatedAnalyzePercent(Math.round(progress))
        }
        tick()
        const id = setInterval(tick, 2000)
        return () => clearInterval(id)
    }, [isAnalyzing, analyzeStartTime, researchFinalized, hasFailed])

    // Enhance stages with simulated progress
    const enhancedStages = stages.map((s) => {
        if (s.key === 'analyzing' && s.status === 'processing') {
            return { ...s, percent: simulatedAnalyzePercent }
        }
        return s
    })

    // Recompute overall percent with simulated analyzing progress
    const STAGE_WEIGHTS = { text_extraction: 15, analyzing: 65, finalizing: 20 }
    let computedOverall = 0
    for (const s of enhancedStages) {
        const w = STAGE_WEIGHTS[s.key] || 0
        if (s.status === 'complete') {
            computedOverall += w
        } else if (s.status === 'processing') {
            computedOverall += Math.round(w * (s.percent / 100))
            break
        } else {
            break
        }
    }
    const displayOverall = researchFinalized ? 100 : Math.min(99, computedOverall)

    useEffect(() => {
        if (researchFinalized || hasFailed) return
        const id = setInterval(() => {
            setActivityIndex((i) => (i + 1) % messages.length)
        }, 2500)
        return () => clearInterval(id)
    }, [messages.length, researchFinalized, hasFailed])

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-8 w-full">
            {/* Error banner */}
            {hasFailed && (
                <div className="rounded-xl border border-red-500/40 bg-red-500/10 px-5 py-4 mb-6">
                    <p className="text-base font-semibold text-red-200">
                        Pipeline failed
                    </p>
                    <p className="text-sm text-white/70 mt-1">
                        {pipelineError || failedStage?.error || 'An error occurred during processing. Try uploading again or check your AI provider settings.'}
                    </p>
                    {brandId && (
                        <button
                            type="button"
                            onClick={() => router.visit(typeof route === 'function' ? route('brands.brand-guidelines.builder', { brand: brandId, step: 'background' }) : `/app/brands/${brandId}/brand-guidelines/builder?step=background`)}
                            className="mt-3 px-4 py-2 rounded-lg text-sm font-medium text-white bg-red-500/30 hover:bg-red-500/40 transition-colors"
                        >
                            Back to Background
                        </button>
                    )}
                </div>
            )}

            {/* Jackpot slot reel processing animation */}
            {!hasFailed && !researchFinalized && (
                <div className="flex justify-center mb-6">
                    <SlotReelLoader size="md" label={messages[activityIndex]} />
                </div>
            )}
            {researchFinalized && !hasFailed && (
                <div className="flex justify-center mb-6">
                    <SlotReelLoader landed size="md" />
                </div>
            )}

            <h3 className="text-lg font-semibold text-white mb-1">Processing your Brand Guidelines</h3>
            <p className="text-white/50 text-sm mb-2">
                {hasFailed
                    ? 'Processing encountered an error.'
                    : 'Your brand research is processing in the background.'}
            </p>
            {!hasFailed && (
                <>
                    <p className="text-white/70 text-sm mb-2">
                        Large PDFs or busy servers can take several minutes. Status updates every few seconds — if the step doesn&apos;t change for a while, it may still be working (especially vision passes on image-heavy pages).
                    </p>
                    <p className="text-white/55 text-xs mb-4 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span>Elapsed: {elapsedLabel}</span>
                        <span className="text-white/35">·</span>
                        <span>You can leave this page — we&apos;ll notify you when it&apos;s ready.</span>
                    </p>
                    {(pipelineTiming
                        || (durationEstimate?.median_seconds != null && durationEstimate.sample_count >= 2)) && (
                        <p className="text-white/50 text-xs mb-4 leading-relaxed">
                            {pipelineTiming ? (
                                <>
                                    Expected duration (
                                    {pipelineTiming.expectation_source === 'median'
                                        ? (durationEstimate?.match === 'similar_size'
                                            ? 'similar PDF size in your workspace'
                                            : 'same extraction mode in your workspace')
                                        : 'default for this extraction mode until enough runs finish in your workspace'}
                                    ):{' '}
                                    <span className="text-cyan-200/90 font-medium">
                                        {formatTypicalDurationSeconds(pipelineTiming.expected_seconds)}
                                    </span>
                                    {pipelineTiming.expectation_source === 'median' && durationEstimate?.sample_count >= 2 && (
                                        <>
                                            {' · '}
                                            median of {durationEstimate.sample_count} completed runs
                                        </>
                                    )}
                                </>
                            ) : (
                                <>
                                    Typical time (
                                    {durationEstimate.match === 'similar_size'
                                        ? 'similar PDF size in your workspace'
                                        : 'same extraction mode in your workspace'}
                                    ):{' '}
                                    <span className="text-cyan-200/90 font-medium">
                                        {formatTypicalDurationSeconds(durationEstimate.median_seconds)}
                                    </span>
                                    {' · '}
                                    median of {durationEstimate.sample_count} completed runs
                                </>
                            )}
                        </p>
                    )}
                </>
            )}
            {showSlowNotice && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 mb-6">
                    <p className="text-sm font-medium text-amber-100">
                        {pipelineTiming?.slower_than_expected ? 'Taking longer than expected' : 'Still working…'}
                    </p>
                    <p className="text-xs text-amber-200/80 mt-1 leading-relaxed">
                        {pipelineTiming?.slower_than_expected ? (
                            <>
                                This run is past the usual window
                                {pipelineTiming.expected_seconds != null && (
                                    <>
                                        {' '}
                                        (~
                                        {formatTypicalDurationSeconds(pipelineTiming.expected_seconds)}
                                        {pipelineTiming.expectation_source === 'median' ? ' typical' : ' baseline'}
                                        )
                                    </>
                                )}
                                . Large PDFs or queue load can add time — you don&apos;t need to stay on this screen.
                            </>
                        ) : (
                            <>
                                This is taking longer than usual. On staging, AI queues can be slow. You don&apos;t need to stay on this screen — check back in a few minutes or use Browse assets below.
                            </>
                        )}
                    </p>
                </div>
            )}

            {/* Overall progress bar */}
            {!hasFailed && (
                <div className="mb-6">
                    <div className="flex items-center justify-between text-sm mb-2">
                        <span className="text-white/70">Overall progress</span>
                        <span className="text-white font-medium">{displayOverall}%</span>
                    </div>
                    <div className="h-3 rounded-full bg-white/10 overflow-hidden relative">
                        <motion.div
                            className="h-full rounded-full absolute inset-y-0 left-0"
                            style={{
                                background: `linear-gradient(90deg, ${accentColor}88, ${accentColor})`,
                            }}
                            initial={{ width: 0 }}
                            animate={{ width: `${Math.max(displayOverall, 2)}%` }}
                            transition={{ duration: 0.5, ease: 'easeOut' }}
                        />
                        {overall_percent < 100 && !researchFinalized && (
                            <motion.div
                                className="absolute inset-0 rounded-full"
                                style={{ background: 'linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.08) 50%, transparent 100%)' }}
                                animate={{ opacity: [0.5, 1, 0.5] }}
                                transition={{ duration: 2, repeat: Infinity, ease: 'easeInOut' }}
                            />
                        )}
                    </div>
                </div>
            )}

            {/* Current activity text */}
            {!hasFailed && (
                <p className="text-sm text-cyan-300/90 mb-6 min-h-[1.25rem]">
                    {researchFinalized ? 'Research complete' : messages[activityIndex]}
                </p>
            )}

            {/* Stage list — collapsed by default (technical detail) */}
            <div className="mb-6">
                <button
                    type="button"
                    onClick={() => setPipelineOpen((o) => !o)}
                    className="flex w-full items-center justify-between gap-2 rounded-lg border border-white/15 bg-white/[0.04] px-4 py-3 text-left text-sm font-medium text-white/80 hover:bg-white/[0.07] transition-colors"
                >
                    <span>Pipeline details</span>
                    <svg
                        className={`h-5 w-5 text-white/50 transition-transform ${pipelineOpen ? 'rotate-180' : ''}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        strokeWidth={2}
                        stroke="currentColor"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                {pipelineOpen && (
            <div className="space-y-4 mt-4 pl-1">
                {enhancedStages.map((stage) => (
                    <div key={stage.key} className="flex items-center gap-4">
                        <div
                            className={`flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center ${
                                stage.status === 'complete'
                                    ? 'bg-emerald-500/20 text-emerald-400'
                                    : stage.status === 'failed'
                                    ? 'bg-red-500/20 text-red-400'
                                    : stage.status === 'processing'
                                    ? 'bg-cyan-500/20 text-cyan-400'
                                    : 'bg-white/10 text-white/50'
                            }`}
                        >
                            {stage.status === 'complete' ? (
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                            ) : stage.status === 'failed' ? (
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            ) : stage.status === 'processing' ? (
                                <motion.div
                                    className="w-3 h-3 rounded-full bg-cyan-400"
                                    animate={{ opacity: [0.5, 1, 0.5] }}
                                    transition={{ duration: 1.2, repeat: Infinity }}
                                />
                            ) : (
                                <div className="w-3 h-3 rounded-full bg-white/30" />
                            )}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p
                                className={`text-sm font-medium ${
                                    stage.status === 'complete'
                                        ? 'text-emerald-400'
                                        : stage.status === 'failed'
                                        ? 'text-red-400'
                                        : stage.status === 'processing'
                                        ? 'text-cyan-300'
                                        : 'text-white/60'
                                }`}
                            >
                                {stage.label}
                            </p>
                            {stage.status === 'failed' && stage.error && (
                                <p className="text-xs text-red-300/70 mt-0.5">{stage.error}</p>
                            )}
                            {(stage.status === 'processing' || stage.status === 'pending' || stage.status === 'complete') && (
                                <div className="mt-1.5 h-1 rounded-full bg-white/10 overflow-hidden relative">
                                    {stage.status === 'complete' ? (
                                        <div
                                            className="h-full rounded-full"
                                            style={{ width: '100%', background: accentColor }}
                                        />
                                    ) : stage.status === 'processing' ? (
                                        <>
                                            <motion.div
                                                className="h-full rounded-full absolute inset-y-0 left-0"
                                                style={{ background: accentColor }}
                                                initial={{ width: '5%' }}
                                                animate={{ width: `${Math.max(stage.percent || 15, 15)}%` }}
                                                transition={{ duration: 1.2, ease: 'easeOut' }}
                                            />
                                            <motion.div
                                                className="absolute inset-0 rounded-full"
                                                style={{ background: 'linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.15) 50%, transparent 100%)' }}
                                                animate={{ x: ['-100%', '200%'] }}
                                                transition={{ duration: 2, repeat: Infinity, ease: 'linear' }}
                                            />
                                        </>
                                    ) : (
                                        <div className="h-full rounded-full bg-white/10" style={{ width: 0 }} />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
                )}
            </div>

            {/* Finalizing state message */}
            {current_stage === 'finalizing' && overall_percent >= 85 && !researchFinalized && !hasFailed && (
                <div className="rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-3 mb-6">
                    <p className="text-sm text-cyan-200 font-medium">Finalizing research insights…</p>
                    <p className="text-xs text-white/60 mt-1">
                        We&apos;ve analyzed the document and are compiling the final brand intelligence report.
                    </p>
                </div>
            )}

            {/* Completion */}
            {researchFinalized ? (
                <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-4 mb-6">
                    <p className="text-sm font-semibold text-emerald-200">Brand research is ready</p>
                    <p className="text-xs text-white/70 mt-1">
                        Your insights are compiled and ready to review.
                    </p>
                    {brandId && (
                        <button
                            type="button"
                            onClick={() => router.visit(typeof route === 'function' ? route('brands.brand-guidelines.builder', { brand: brandId, step: 'research-summary' }) : `/app/brands/${brandId}/brand-guidelines/builder?step=research-summary`)}
                            className="mt-3 px-4 py-2.5 rounded-xl text-sm font-medium text-white transition-colors"
                            style={{ backgroundColor: accentColor }}
                            autoFocus
                        >
                            Continue to Research Summary
                        </button>
                    )}
                </div>
            ) : !hasFailed && (
                <p className="text-sm text-white/50 mb-6">
                    Your progress is saved. You cannot proceed to the next step until processing is complete.
                </p>
            )}

            {/* Actions */}
            {!researchFinalized && !hasFailed && (
                <div className="flex flex-wrap gap-3">
                    <Link
                        href={typeof route === 'function' ? route('assets.index') : '/app/assets'}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-white/90 hover:text-white bg-white/10 hover:bg-white/15 border border-white/20 transition-colors"
                    >
                        Browse assets
                    </Link>
                    <span className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm text-white/60">
                        Stay on this page
                    </span>
                </div>
            )}

            <div className="mt-6 pt-4 border-t border-white/5 flex justify-center">
                <PoweredByJackpot variant="inline" className="opacity-50" />
            </div>
        </div>
    )
}

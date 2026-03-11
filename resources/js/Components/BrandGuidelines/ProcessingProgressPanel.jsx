/**
 * Brand Guidelines processing progress dashboard.
 * Shows overall progress, stage checklist, page counts, and activity text.
 * Users can leave the page and will be notified when research is ready.
 * Only status === 'processing' stages animate; pending/complete have no animation.
 */
import { useState, useEffect } from 'react'
import { Link, router } from '@inertiajs/react'
import { motion } from 'framer-motion'

const ACTIVITY_MESSAGES = [
    'Extracting text from your guidelines',
    'Rendering PDF pages',
    'Analyzing page layouts',
    'Detecting colors and typography',
    'Looking for strategy signals',
    'Merging research insights',
    'Finalizing brand intelligence',
]

const STAGE_TO_MESSAGES = {
    text_extraction: ['Extracting text from your guidelines'],
    page_rendering: ['Rendering PDF pages'],
    visual_extraction: ['Analyzing page layouts', 'Detecting colors and typography', 'Looking for strategy signals'],
    fusion: ['Merging research insights'],
    finalizing: ['Finalizing brand intelligence', 'Compiling the final brand intelligence report'],
}

export default function ProcessingProgressPanel({
    processingProgress = {},
    accentColor = '#06b6d4',
    brandId = null,
    researchFinalized = false,
    ingestionProcessing = false,
}) {
    let {
        overall_percent = 0,
        current_stage = 'text_extraction',
        stages = [],
        pages = {},
    } = processingProgress

    // When research is finalized and not processing, force all stages to complete (no fake pending)
    if (researchFinalized && !ingestionProcessing && stages.length > 0) {
        stages = stages.map((s) => ({ ...s, status: 'complete', percent: 100 }))
        overall_percent = 100
        current_stage = 'finalizing'
    }

    const [activityIndex, setActivityIndex] = useState(0)
    const stageMessages = STAGE_TO_MESSAGES[current_stage] || ACTIVITY_MESSAGES
    const messages = stageMessages.length > 0 ? stageMessages : ACTIVITY_MESSAGES

    // Only animate activity text when NOT finalized (stop all animation when complete)
    useEffect(() => {
        if (researchFinalized) return
        const id = setInterval(() => {
            setActivityIndex((i) => (i + 1) % messages.length)
        }, 2500)
        return () => clearInterval(id)
    }, [messages.length, researchFinalized])

    const hasPages = pages?.total > 0
    const pagesAnalyzed = pages?.extracted ?? pages?.classified ?? 0
    const pagesTotal = pages?.total ?? 0

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-8 w-full">
            <h3 className="text-lg font-semibold text-white mb-1">Processing your Brand Guidelines</h3>
            <p className="text-white/50 text-sm mb-4">
                Your brand research is processing in the background.
            </p>
            <p className="text-white/70 text-sm mb-6">
                You can leave this page and continue using the platform — we&apos;ll notify you when it&apos;s ready.
            </p>

            {/* Overall progress bar — no shimmer when complete or finalized */}
            <div className="mb-6">
                <div className="flex items-center justify-between text-sm mb-2">
                    <span className="text-white/70">Overall progress</span>
                    <span className="text-white font-medium">{overall_percent}%</span>
                </div>
                <div className="h-3 rounded-full bg-white/10 overflow-hidden relative">
                    <motion.div
                        className="h-full rounded-full absolute inset-y-0 left-0"
                        style={{
                            background: `linear-gradient(90deg, ${accentColor}88, ${accentColor})`,
                        }}
                        initial={{ width: 0 }}
                        animate={{ width: `${Math.max(overall_percent, 2)}%` }}
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

            {/* Current activity text — static when finalized */}
            <p className="text-sm text-cyan-300/90 mb-6 min-h-[1.25rem]">
                {researchFinalized ? 'Research complete' : messages[activityIndex]}
            </p>

            {/* Stage list */}
            <div className="space-y-4 mb-6">
                {stages.map((stage) => (
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
                            {stage.status === 'processing' && stage.percent > 0 && stage.percent < 100 && (
                                <p className="text-xs text-white/50 mt-0.5">{stage.percent}%</p>
                            )}
                            {(stage.status === 'processing' || stage.status === 'pending' || stage.status === 'complete') && (
                                <div className="mt-1.5 h-1 rounded-full bg-white/10 overflow-hidden">
                                    {stage.status === 'complete' ? (
                                        <div
                                            className="h-full rounded-full"
                                            style={{ width: '100%', background: accentColor }}
                                        />
                                    ) : stage.status === 'processing' && stage.percent > 0 && stage.percent < 100 ? (
                                        <motion.div
                                            className="h-full rounded-full"
                                            style={{ background: accentColor }}
                                            initial={{ width: 0 }}
                                            animate={{ width: `${stage.percent}%` }}
                                            transition={{ duration: 0.4 }}
                                        />
                                    ) : stage.status === 'processing' ? (
                                        <motion.div
                                            className="h-full rounded-full bg-cyan-400/60"
                                            initial={{ width: '10%' }}
                                            animate={{ width: ['10%', '90%', '10%'] }}
                                            transition={{ duration: 1.8, repeat: Infinity, ease: 'easeInOut' }}
                                        />
                                    ) : (
                                        <div className="h-full rounded-full bg-white/10" style={{ width: 0 }} />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {/* Page progress summary */}
            {hasPages && (
                <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 mb-6">
                    <p className="text-sm text-white/80">
                        Pages analyzed: <span className="font-medium text-cyan-300">{pagesAnalyzed}</span> / {pagesTotal}
                    </p>
                    {pagesTotal > 0 && (pages?.classified ?? 0) !== (pages?.extracted ?? 0) && (
                        <p className="text-xs text-white/50 mt-1">
                            Pages classified: {pages?.classified ?? 0} / {pagesTotal}
                        </p>
                    )}
                </div>
            )}

            {/* Finalizing state message — only when not yet complete */}
            {current_stage === 'finalizing' && overall_percent >= 85 && !researchFinalized && (
                <div className="rounded-xl border border-cyan-500/30 bg-cyan-500/10 px-4 py-3 mb-6">
                    <p className="text-sm text-cyan-200 font-medium">Finalizing research insights…</p>
                    <p className="text-xs text-white/60 mt-1">
                        We&apos;ve analyzed the document and are compiling the final brand intelligence report.
                    </p>
                </div>
            )}

            {/* Inline completion when research is ready */}
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
            ) : (
                <p className="text-sm text-white/50 mb-6">
                    Your progress is saved. You cannot proceed to the next step until processing is complete.
                </p>
            )}

            {/* Actions: Browse assets / Stay on this page — only when not finalized */}
            {!researchFinalized && (
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
        </div>
    )
}

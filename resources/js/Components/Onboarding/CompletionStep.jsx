import { useEffect, useState } from 'react'
import { motion } from 'framer-motion'
import { CheckCircleIcon, SparklesIcon, ArrowPathIcon } from '@heroicons/react/24/outline'

const NEXT_STEPS = [
    { label: 'Upload more assets', desc: 'Build your full library' },
    { label: 'Invite your team', desc: 'Add collaborators to the workspace' },
    { label: 'Complete Brand DNA', desc: 'Define strategy, positioning, and expression' },
    { label: 'Add typography', desc: 'Upload fonts and set type styles' },
    { label: 'Create collections', desc: 'Organize assets into curated sets' },
]

export default function CompletionStep({ brandName, brandColor = '#6366f1', progress, onFinish }) {
    const [showContent, setShowContent] = useState(false)
    const [showNextSteps, setShowNextSteps] = useState(false)

    const enrichmentStatus = progress?.enrichment_processing_status
    const hasEnrichmentProcessing = enrichmentStatus === 'queued' || enrichmentStatus === 'processing'
    const hasOptionalRemaining = progress && !progress.recommended_completion_met

    useEffect(() => {
        const t1 = setTimeout(() => setShowContent(true), 400)
        const t2 = setTimeout(() => setShowNextSteps(true), 1200)
        return () => { clearTimeout(t1); clearTimeout(t2) }
    }, [])

    const enrichmentLabel = enrichmentStatus === 'queued'
        ? 'Queued for processing'
        : (progress?.enrichment_processing_detail || 'Building your brand profile')

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.6 }}
            className="flex flex-col items-center text-center max-w-lg mx-auto"
        >
            {/* Celebration glow */}
            <motion.div
                initial={{ scale: 0.8, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                transition={{ type: 'spring', stiffness: 200, damping: 20, delay: 0.1 }}
                className="relative mb-8"
            >
                <div
                    className="absolute inset-0 rounded-full blur-3xl"
                    style={{
                        background: `radial-gradient(circle, ${brandColor}30, transparent 70%)`,
                        transform: 'scale(3)',
                    }}
                />
                <div
                    className="relative h-20 w-20 rounded-2xl flex items-center justify-center"
                    style={{
                        background: `linear-gradient(135deg, ${brandColor}30, ${brandColor}10)`,
                        boxShadow: `0 0 48px ${brandColor}20, inset 0 1px 0 ${brandColor}30`,
                    }}
                >
                    <motion.div
                        initial={{ scale: 0 }}
                        animate={{ scale: 1 }}
                        transition={{ type: 'spring', stiffness: 300, damping: 15, delay: 0.3 }}
                    >
                        <CheckCircleIcon className="h-10 w-10" style={{ color: brandColor }} />
                    </motion.div>
                </div>
            </motion.div>

            {showContent && (
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                >
                    <h1 className="text-3xl sm:text-4xl font-semibold tracking-tight text-white/95 leading-tight">
                        Your workspace is ready
                    </h1>
                    <p className="mt-3 text-base text-white/45 leading-relaxed max-w-md mx-auto">
                        {brandName || 'Your brand'} is set up and ready for your team.
                        {hasOptionalRemaining
                            ? ' The essentials are in place — a few optional improvements are still available.'
                            : " You're off to a great start."}
                    </p>
                </motion.div>
            )}

            {showNextSteps && (
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4 }}
                    className="w-full mt-10"
                >
                    {/* Background processing notice */}
                    {hasEnrichmentProcessing && (
                        <div
                            className="flex items-center gap-3 rounded-xl px-4 py-3 mb-6 border"
                            style={{
                                borderColor: `${brandColor}25`,
                                backgroundColor: `${brandColor}08`,
                            }}
                        >
                            <ArrowPathIcon className="h-5 w-5 shrink-0 animate-spin" style={{ color: `${brandColor}99` }} />
                            <div className="text-left">
                                <p className="text-sm font-medium text-white/70">
                                    {enrichmentLabel}
                                </p>
                                <p className="text-xs text-white/40">
                                    Results will appear in Brand DNA, Research, and Overview insights.
                                </p>
                            </div>
                        </div>
                    )}

                    <p className="text-xs font-semibold uppercase tracking-wider text-white/30 mb-4">
                        Optional next steps
                    </p>
                    <div className="space-y-2">
                        {NEXT_STEPS.slice(0, 3).map((step, idx) => (
                            <motion.div
                                key={step.label}
                                initial={{ opacity: 0, x: -6 }}
                                animate={{ opacity: 1, x: 0 }}
                                transition={{ delay: idx * 0.08, duration: 0.25 }}
                                className="flex items-center gap-3 rounded-xl px-4 py-2.5 bg-white/[0.03] border border-white/[0.06] text-left"
                            >
                                <SparklesIcon className="h-4 w-4 shrink-0 text-white/20" />
                                <div>
                                    <p className="text-sm text-white/60">{step.label}</p>
                                    <p className="text-xs text-white/25">{step.desc}</p>
                                </div>
                            </motion.div>
                        ))}
                    </div>

                    <div className="mt-8">
                        <button
                            type="button"
                            onClick={onFinish}
                            className="px-8 py-3.5 rounded-xl text-base font-semibold text-white transition-all duration-300 hover:brightness-110"
                            style={{
                                background: `linear-gradient(135deg, ${brandColor}, ${brandColor}dd)`,
                                boxShadow: `0 4px 24px ${brandColor}30`,
                            }}
                        >
                            Go to your workspace
                        </button>
                    </div>
                </motion.div>
            )}
        </motion.div>
    )
}

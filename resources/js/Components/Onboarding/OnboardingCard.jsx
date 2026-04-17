import { useMemo, useState, useCallback } from 'react'
import { router } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import {
    CheckCircleIcon,
    SparklesIcon,
    ArrowPathIcon,
    ExclamationTriangleIcon,
    XMarkIcon,
    SwatchIcon,
    PhotoIcon,
    DocumentTextIcon,
    GlobeAltIcon,
    BuildingOfficeIcon,
} from '@heroicons/react/24/outline'
import { CheckCircleIcon as CheckCircleSolid } from '@heroicons/react/24/solid'
import { ensureDarkModeContrast } from '../../utils/colorUtils'

const STEP_ICONS = {
    brand_name: SwatchIcon,
    brand_mark: PhotoIcon,
    primary_color: SwatchIcon,
    starter_assets: PhotoIcon,
    recommended_assets: PhotoIcon,
    guidelines: DocumentTextIcon,
    industry: BuildingOfficeIcon,
}

function ProgressBar({ percent, brandColor }) {
    return (
        <div className="h-1.5 w-full rounded-full bg-white/[0.08] overflow-hidden">
            <motion.div
                className="h-full rounded-full"
                style={{
                    background: `linear-gradient(90deg, ${brandColor}, ${brandColor}cc)`,
                    boxShadow: `0 0 12px ${brandColor}40`,
                }}
                initial={{ width: 0 }}
                animate={{ width: `${percent}%` }}
                transition={{ duration: 0.6, ease: 'easeOut' }}
            />
        </div>
    )
}

function ChecklistItem({ item, brandColor }) {
    return (
        <div className="flex items-center gap-2.5 py-1">
            {item.done ? (
                <CheckCircleSolid className="h-4 w-4 shrink-0" style={{ color: brandColor }} />
            ) : (
                <div
                    className="h-4 w-4 shrink-0 rounded-full border"
                    style={{ borderColor: item.required ? 'rgba(255,255,255,0.2)' : 'rgba(255,255,255,0.1)' }}
                />
            )}
            <span className={`text-sm ${item.done ? 'text-white/40 line-through' : 'text-white/70'}`}>
                {item.label}
            </span>
            {item.detail && !item.done && (
                <span className="text-[10px] text-white/25 ml-auto">{item.detail}</span>
            )}
            {!item.required && !item.done && !item.detail && (
                <span className="text-[10px] text-white/25 uppercase tracking-wider ml-auto">Optional</span>
            )}
        </div>
    )
}

async function postJson(url) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: '{}',
    })
    if (!res.ok) throw new Error(`${res.status}`)
    return res.json()
}

/**
 * Onboarding card for the Overview/Tasks page.
 *
 * States:
 *   A  — Blocking: not activated, not dismissed       → prominent card
 *   A' — Dismissed: cinematic skipped, not activated   → same card + permanent dismiss button
 *   C  — Enrichment processing (queued/processing)     → status card
 *   C' — Enrichment failed                             → amber warning
 *   B  — Activated, optional steps remaining            → light card
 */
export default function OnboardingCard({ progress, checklist, brandColor: rawBrandColor = '#6366f1', brand, onStatusChange }) {
    const [dismissing, setDismissing] = useState(false)
    const [hidden, setHidden] = useState(false)

    const brandColor = useMemo(() => {
        const userDefinedFallback =
            (brand?.accent_color_user_defined && brand?.accent_color) ||
            (brand?.secondary_color_user_defined && brand?.secondary_color) ||
            null
        return ensureDarkModeContrast(rawBrandColor, userDefinedFallback || '#6366f1')
    }, [rawBrandColor, brand?.accent_color, brand?.secondary_color, brand?.accent_color_user_defined, brand?.secondary_color_user_defined])

    const handlePermanentDismiss = useCallback(async () => {
        if (dismissing) return
        setDismissing(true)
        try {
            const result = await postJson('/app/onboarding/dismiss-card')
            if (onStatusChange && result?.progress) onStatusChange(result.progress)
            setHidden(true)
        } catch {
            setDismissing(false)
        }
    }, [dismissing, onStatusChange])

    if (!progress || hidden) return null

    const isBlocking = progress.is_blocking
    const isActivated = progress.is_activated
    const isCompleted = progress.is_completed
    const isDismissed = progress.is_dismissed
    const isCardDismissed = progress.is_card_dismissed
    const enrichmentStatus = progress.enrichment_processing_status
    const hasProcessing = enrichmentStatus === 'queued' || enrichmentStatus === 'processing'
    const enrichmentFailed = enrichmentStatus === 'failed'

    // Card permanently hidden by user
    if (isCardDismissed && !hasProcessing) return null

    // Fully complete, no active enrichment
    if (isCompleted && !hasProcessing && !enrichmentFailed) return null

    const requiredItems = checklist?.filter(i => i.required) || []
    const optionalItems = checklist?.filter(i => !i.required && !i.done) || []

    const handleResume = () => {
        router.visit('/app/onboarding')
    }

    // Whether to show the permanent dismiss button.
    // Shows when: dismissed cinematic but not yet activated and card not yet permanently dismissed.
    const showPermanentDismiss = isDismissed && !isActivated && !isCardDismissed

    // ── State A / A': Blocking or Dismissed but not activated ──────
    if (isBlocking || (isDismissed && !isActivated)) {
        const requiredDone = requiredItems.filter(i => i.done).length
        return (
            <AnimatePresence>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -8, transition: { duration: 0.25 } }}
                    transition={{ duration: 0.35 }}
                >
                    <div
                        className="overflow-hidden rounded-2xl border backdrop-blur-md"
                        style={{
                            borderColor: `${brandColor}30`,
                            background: `linear-gradient(135deg, ${brandColor}12, rgba(12, 12, 14, 0.6))`,
                            boxShadow: `0 0 40px ${brandColor}08, inset 0 1px 0 ${brandColor}15`,
                        }}
                    >
                        <div className="px-5 py-5 sm:px-6 sm:py-5">
                            <div className="flex items-start gap-4">
                                <div
                                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
                                    style={{ backgroundColor: `${brandColor}18` }}
                                >
                                    <SparklesIcon className="h-5 w-5" style={{ color: brandColor }} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="text-[11px] font-bold uppercase tracking-wider" style={{ color: `${brandColor}cc` }}>
                                        Getting started
                                    </p>
                                    <h3 className="mt-1 text-[17px] font-semibold leading-snug text-white">
                                        Finish setting up your brand workspace
                                    </h3>
                                    <p className="mt-1.5 text-sm text-white/45 leading-relaxed">
                                        Complete the essentials so your team can start using the library.
                                    </p>

                                    <div className="mt-4">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-xs text-white/40">
                                                {progress.activation_percent}% complete
                                            </span>
                                            <span className="text-xs text-white/30">
                                                {requiredDone} of {requiredItems.length} required
                                            </span>
                                        </div>
                                        <ProgressBar percent={progress.activation_percent} brandColor={brandColor} />
                                    </div>

                                    <div className="mt-4 space-y-0.5">
                                        {requiredItems.map(item => (
                                            <ChecklistItem key={item.key} item={item} brandColor={brandColor} />
                                        ))}
                                    </div>

                                    <div className="mt-5 flex items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={handleResume}
                                            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-300 hover:brightness-110"
                                            style={{
                                                background: `linear-gradient(135deg, ${brandColor}, ${brandColor}dd)`,
                                                boxShadow: `0 4px 16px ${brandColor}30`,
                                            }}
                                        >
                                            Resume setup
                                        </button>

                                        {showPermanentDismiss && (
                                            <button
                                                type="button"
                                                onClick={handlePermanentDismiss}
                                                disabled={dismissing}
                                                className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-medium text-white/50 bg-white/[0.06] border border-white/[0.08] hover:bg-white/[0.10] hover:text-white/70 hover:border-white/[0.15] transition-all duration-200 disabled:opacity-40"
                                            >
                                                <XMarkIcon className="h-3.5 w-3.5" />
                                                {dismissing ? 'Dismissing…' : 'Dismiss'}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>
            </AnimatePresence>
        )
    }

    // ── State C: Enrichment Processing ─────────────────────────────
    if (hasProcessing) {
        const statusLabel = enrichmentStatus === 'queued'
            ? 'Queued for processing'
            : (progress.enrichment_processing_detail || 'Building your brand profile')

        return (
            <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35 }}
            >
                <div
                    className="overflow-hidden rounded-xl border border-white/[0.08] bg-white/[0.035] backdrop-blur-sm"
                    style={{ boxShadow: `0 0 24px ${brandColor}08` }}
                >
                    <div className="px-4 py-3.5">
                        <div className="flex items-start gap-3">
                            <div
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                                style={{ backgroundColor: `${brandColor}18` }}
                            >
                                <ArrowPathIcon className="h-4 w-4 animate-spin" style={{ color: brandColor }} />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-semibold uppercase tracking-wide text-white/45">
                                    Brand profile
                                </p>
                                <h3 className="mt-1 text-[15px] font-semibold leading-snug text-white/80">
                                    We're building your brand profile
                                </h3>
                                <p className="mt-1 text-[13px] leading-relaxed text-white/45">
                                    {statusLabel} — results will appear in Brand DNA and Research.
                                </p>

                                {optionalItems.length > 0 && (
                                    <div className="mt-3 space-y-0.5">
                                        {optionalItems.slice(0, 3).map(item => (
                                            <ChecklistItem key={item.key} item={item} brandColor={brandColor} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </motion.div>
        )
    }

    // ── State C (failed): Enrichment failed ────────────────────────
    if (enrichmentFailed) {
        return (
            <motion.div
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.35 }}
            >
                <div className="overflow-hidden rounded-xl border border-amber-500/15 bg-white/[0.035] backdrop-blur-sm">
                    <div className="px-4 py-3.5">
                        <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-500/10">
                                <ExclamationTriangleIcon className="h-4 w-4 text-amber-400/80" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-xs font-semibold uppercase tracking-wide text-white/45">
                                    Brand profile
                                </p>
                                <p className="mt-1 text-[13px] leading-relaxed text-white/55">
                                    {progress.enrichment_processing_detail || 'We hit a problem processing your materials.'}
                                    {' '}You can re-upload guidelines or add a website URL in Brand Settings.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </motion.div>
        )
    }

    // ── State B: Activated, Recommended Steps Remaining ────────────
    if (isActivated && optionalItems.length > 0) {
        return (
            <AnimatePresence>
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, y: -8, transition: { duration: 0.25 } }}
                    transition={{ duration: 0.35 }}
                >
                    <div
                        className="overflow-hidden rounded-xl border border-white/[0.08] bg-white/[0.035] backdrop-blur-sm"
                        style={{ boxShadow: `0 0 24px ${brandColor}08` }}
                    >
                        <div className="px-4 py-3.5">
                            <div className="flex items-start gap-3">
                                <div
                                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                                    style={{ backgroundColor: `${brandColor}18` }}
                                >
                                    <CheckCircleIcon className="h-4 w-4" style={{ color: brandColor }} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-white/45">
                                        Brand setup
                                    </p>
                                    <p className="mt-1 text-[13px] leading-relaxed text-white/60">
                                        Keep building your brand hub — a few optional improvements remain.
                                    </p>

                                    <div className="mt-2.5">
                                        <ProgressBar percent={progress.completion_percent} brandColor={brandColor} />
                                    </div>

                                    <div className="mt-2.5 space-y-0.5">
                                        {optionalItems.slice(0, 4).map(item => (
                                            <ChecklistItem key={item.key} item={item} brandColor={brandColor} />
                                        ))}
                                    </div>

                                    <div className="mt-4 flex items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={handleResume}
                                            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold text-white/90 transition-all duration-200 hover:brightness-110"
                                            style={{
                                                backgroundColor: `${brandColor}cc`,
                                                boxShadow: `0 2px 8px ${brandColor}20`,
                                            }}
                                        >
                                            Resume setup
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handlePermanentDismiss}
                                            disabled={dismissing}
                                            className="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-medium text-white/40 hover:text-white/60 hover:bg-white/[0.06] transition-all duration-200 disabled:opacity-40"
                                        >
                                            <XMarkIcon className="h-3.5 w-3.5" />
                                            {dismissing ? 'Dismissing…' : 'Dismiss'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </motion.div>
            </AnimatePresence>
        )
    }

    return null
}

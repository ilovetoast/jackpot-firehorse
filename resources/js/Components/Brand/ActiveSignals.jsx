import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import {
    SparklesIcon,
    DocumentTextIcon,
    ClockIcon,
    CloudArrowUpIcon,
    ChevronRightIcon,
    LightBulbIcon,
    ArrowRightIcon,
} from '@heroicons/react/24/outline'

const ICON_MAP = {
    sparkles: SparklesIcon,
    document: DocumentTextIcon,
    clock: ClockIcon,
    upload: CloudArrowUpIcon,
}

/** Map signal context.category to insight type for matching */
function signalCategoryToInsightType(signal) {
    const cat = signal?.context?.category
    if (cat === 'ai_suggestions') return 'suggestions'
    if (['ai_tags', 'ai_categories', 'metadata', 'activity', 'rights'].includes(cat)) return cat
    return null
}

function formatInsightsUpdatedLabel(iso) {
    if (!iso) return null
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return null
        return d.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        })
    } catch {
        return null
    }
}

/**
 * ActiveSignals — "What Needs Attention" structured block.
 * When insights are provided, matches by type and shows "Why it matters" under each signal.
 * Each item clickable, high-priority glow, subtle dividers.
 */
export default function ActiveSignals({
    signals = [],
    insights = [],
    brandColor = '#6366f1',
    permissions = {},
    insightsUpdatedAt = null,
}) {
    const visible = signals.filter((s) => {
        if (!s.permission) return true
        return permissions[s.permission] !== false
    })

    if (visible.length === 0) return null

    const primaryHref = visible[0]?.href
    const updatedLabel = formatInsightsUpdatedLabel(insightsUpdatedAt)

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4 }}
        >
            <div
                className="
                    rounded-xl overflow-hidden
                    bg-white/[0.06] backdrop-blur-md
                    ring-1 ring-white/[0.12]
                    shadow-[0_0_24px_rgba(0,0,0,0.2)]
                    transition-all duration-200 ease-out
                    hover:bg-white/[0.08] hover:ring-white/[0.18]
                    hover:shadow-[0_0_28px_rgba(0,0,0,0.25)]
                "
                style={{
                    boxShadow: `0 0 20px ${brandColor}15`,
                }}
            >
                <div className="px-5 py-4">
                    <div className="flex items-center justify-between gap-3 mb-3">
                        <span className="text-xs font-semibold uppercase tracking-wider text-white/50">
                            ⚡ What Needs Attention
                        </span>
                        {updatedLabel && (
                            <span
                                className="shrink-0 text-[10px] font-medium tabular-nums tracking-wide text-white/20"
                                title="When this block was last computed"
                            >
                                {updatedLabel}
                            </span>
                        )}
                    </div>
                    <ul className="divide-y divide-white/[0.06]">
                        {visible.map((signal, i) => {
                            const Icon = ICON_MAP[signal.icon] || SparklesIcon
                            const isHigh = signal.priority === 'high'
                            const insightType = signalCategoryToInsightType(signal)
                            const matchedInsight = insightType && (insights || []).find((ins) => ins.type === insightType)
                            const content = (
                                <>
                                    <Icon className={`w-4 h-4 shrink-0 ${isHigh ? 'text-amber-400/80' : 'text-white/50'}`} />
                                    <span className={isHigh ? 'text-white/95 font-medium' : 'text-white/85'}>
                                        {signal.label}
                                    </span>
                                </>
                            )
                            return (
                                <motion.li
                                    key={i}
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: i * 0.05, duration: 0.3 }}
                                    className={`
                                        py-2.5 first:pt-0 last:pb-0
                                        ${isHigh ? 'animate-pulse-subtle' : ''}
                                    `}
                                >
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-3">
                                            {signal.href ? (
                                                <Link
                                                    href={signal.href}
                                                    className="flex items-center gap-3 w-full rounded-md px-2 py-1 -mx-2 -my-1 transition-all duration-200 hover:bg-white/[0.06]"
                                                >
                                                    {content}
                                                </Link>
                                            ) : (
                                                <div className="flex items-center gap-3">
                                                    {content}
                                                </div>
                                            )}
                                        </div>
                                        {matchedInsight?.text && (
                                            <div className="flex items-start gap-2 pl-6 -mt-0.5">
                                                <LightBulbIcon className="w-3.5 h-3.5 text-amber-400/40 shrink-0 mt-0.5" />
                                                <span className="text-sm italic text-white/45">
                                                    {matchedInsight.href ? (
                                                        <Link
                                                            href={matchedInsight.href}
                                                            className="hover:text-white/65 transition-colors inline-flex items-center gap-1"
                                                        >
                                                            {matchedInsight.text}
                                                            <ArrowRightIcon className="w-3 h-3" />
                                                        </Link>
                                                    ) : (
                                                        matchedInsight.text
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </motion.li>
                            )
                        })}
                    </ul>
                    {primaryHref && (
                        <Link
                            href={primaryHref}
                            className="
                                mt-4 inline-flex items-center gap-1.5 text-sm font-medium
                                text-white/90 hover:text-white
                                transition-colors duration-200
                            "
                        >
                            Review Now
                            <ChevronRightIcon className="w-4 h-4" />
                        </Link>
                    )}
                </div>
            </div>
        </motion.div>
    )
}

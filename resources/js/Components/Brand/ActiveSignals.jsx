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
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'
import { hexToRgba, pickProminentAccentColor, resolveOverviewIconColor } from '../../utils/colorUtils'

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
    if (cat === 'upload_approvals') return 'upload_approvals'
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
    /** Brand accent (e.g. workspace accent); used with primary/secondary to pick a vivid “action required” frame. */
    accentBrandColor = null,
    secondaryBrandColor = null,
    /** Resolved readable icon color on dark cards; when omitted, derived from brandColor only. */
    iconAccentColor = null,
    permissions = {},
    insightsUpdatedAt = null,
    /** When true and managers see the Creators teaser, creator-only pending uploads are shown there — not duplicated here. */
    creatorModuleEnabled = false,
    canManageCreatorsDashboard = false,
}) {
    const iconFill = iconAccentColor ?? resolveOverviewIconColor(brandColor)
    const actionHighlightHex = pickProminentAccentColor(brandColor, accentBrandColor, secondaryBrandColor)
    const actionIconTint = resolveOverviewIconColor(actionHighlightHex, {
        secondary: secondaryBrandColor,
        accent: accentBrandColor,
    })

    const visible = signals.filter((s) => {
        if (!s.permission) return true
        return permissions[s.permission] !== false
    })

    if (visible.length === 0) return null

    const pendingApprovalSignals = visible.filter((s) => s.context?.category === 'upload_approvals')
    const otherSignals = visible.filter((s) => s.context?.category !== 'upload_approvals')
    const primaryApproval = pendingApprovalSignals[0]
    const listPrimaryHref = otherSignals[0]?.href
    const updatedLabel = formatInsightsUpdatedLabel(insightsUpdatedAt)

    const ctx = primaryApproval?.context
    const prostaffPending = Number(ctx?.prostaff_pending ?? 0)
    const teamPending = Number(ctx?.team_pending ?? 0)
    const creatorHandledInTeaser =
        prostaffPending > 0 &&
        teamPending === 0 &&
        creatorModuleEnabled &&
        canManageCreatorsDashboard

    const teamUploadHref = '/app/insights/review?workspace=uploads'
    const teamUploadLabel =
        teamPending === 1 ? '1 team upload awaits your approval' : `${teamPending} team uploads await your approval`
    const uploadBannerTitle =
        prostaffPending > 0 && teamPending > 0
            ? 'Action required — team upload approvals'
            : 'Action required — upload approvals'
    const uploadBannerLinkLabel =
        prostaffPending > 0 && teamPending > 0 ? 'Open team approval queue' : 'Open approval queue'

    const showTeamFocusedUploadBanner =
        Boolean(primaryApproval?.href) && !creatorHandledInTeaser && teamPending > 0
    const showCreatorOnlyLegacyBanner =
        Boolean(primaryApproval?.href) && !creatorHandledInTeaser && teamPending === 0 && prostaffPending > 0
    /** Context missing split counts (e.g. stale cache) — keep full banner + backend href/label. */
    const showUnsplitUploadBanner =
        Boolean(primaryApproval?.href) &&
        !creatorHandledInTeaser &&
        teamPending === 0 &&
        prostaffPending === 0

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4 }}
            className="space-y-3"
        >
            {showTeamFocusedUploadBanner ? (
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35 }}
                >
                    <Link
                        href={teamUploadHref}
                        className="block overflow-hidden rounded-xl border px-4 py-3.5 backdrop-blur-md transition duration-200 hover:brightness-[1.04]"
                        style={{
                            borderColor: hexToRgba(actionHighlightHex, 0.48),
                            background: `linear-gradient(to bottom right, ${hexToRgba(actionHighlightHex, 0.2)}, rgba(12, 12, 14, 0.42))`,
                            boxShadow: `0 0 32px ${hexToRgba(actionHighlightHex, 0.14)}, inset 0 1px 0 ${hexToRgba(actionHighlightHex, 0.22)}`,
                        }}
                    >
                        <div className="flex items-start gap-3">
                            <div
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                style={{
                                    backgroundColor: hexToRgba(actionHighlightHex, 0.22),
                                    color: actionIconTint,
                                }}
                            >
                                <ExclamationTriangleIcon className="h-5 w-5" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p
                                    className="text-[11px] font-bold uppercase tracking-wider"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.92) }}
                                >
                                    {uploadBannerTitle}
                                </p>
                                <p className="mt-1.5 text-[15px] font-semibold leading-snug text-white">
                                    {teamUploadLabel}
                                </p>
                                {prostaffPending > 0 && teamPending > 0 ? (
                                    <p className="mt-1.5 text-xs leading-snug text-white/50">
                                        Creator program uploads are called out in the Creators card above.
                                    </p>
                                ) : null}
                                <p
                                    className="mt-2 inline-flex items-center gap-1 text-sm font-semibold"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.96) }}
                                >
                                    {uploadBannerLinkLabel}
                                    <ChevronRightIcon className="h-4 w-4" />
                                </p>
                            </div>
                        </div>
                    </Link>
                </motion.div>
            ) : null}

            {showCreatorOnlyLegacyBanner ? (
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35 }}
                >
                    <Link
                        href={primaryApproval.href}
                        className="block overflow-hidden rounded-xl border px-4 py-3.5 backdrop-blur-md transition duration-200 hover:brightness-[1.04]"
                        style={{
                            borderColor: hexToRgba(actionHighlightHex, 0.48),
                            background: `linear-gradient(to bottom right, ${hexToRgba(actionHighlightHex, 0.2)}, rgba(12, 12, 14, 0.42))`,
                            boxShadow: `0 0 32px ${hexToRgba(actionHighlightHex, 0.14)}, inset 0 1px 0 ${hexToRgba(actionHighlightHex, 0.22)}`,
                        }}
                    >
                        <div className="flex items-start gap-3">
                            <div
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                style={{
                                    backgroundColor: hexToRgba(actionHighlightHex, 0.22),
                                    color: actionIconTint,
                                }}
                            >
                                <ExclamationTriangleIcon className="h-5 w-5" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p
                                    className="text-[11px] font-bold uppercase tracking-wider"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.92) }}
                                >
                                    Action required — upload approvals
                                </p>
                                <p className="mt-1.5 text-[15px] font-semibold leading-snug text-white">
                                    {primaryApproval.label}
                                </p>
                                <p
                                    className="mt-2 inline-flex items-center gap-1 text-sm font-semibold"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.96) }}
                                >
                                    Open approval queue
                                    <ChevronRightIcon className="h-4 w-4" />
                                </p>
                            </div>
                        </div>
                    </Link>
                </motion.div>
            ) : null}

            {showUnsplitUploadBanner ? (
                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.35 }}
                >
                    <Link
                        href={primaryApproval.href}
                        className="block overflow-hidden rounded-xl border px-4 py-3.5 backdrop-blur-md transition duration-200 hover:brightness-[1.04]"
                        style={{
                            borderColor: hexToRgba(actionHighlightHex, 0.48),
                            background: `linear-gradient(to bottom right, ${hexToRgba(actionHighlightHex, 0.2)}, rgba(12, 12, 14, 0.42))`,
                            boxShadow: `0 0 32px ${hexToRgba(actionHighlightHex, 0.14)}, inset 0 1px 0 ${hexToRgba(actionHighlightHex, 0.22)}`,
                        }}
                    >
                        <div className="flex items-start gap-3">
                            <div
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                style={{
                                    backgroundColor: hexToRgba(actionHighlightHex, 0.22),
                                    color: actionIconTint,
                                }}
                            >
                                <ExclamationTriangleIcon className="h-5 w-5" aria-hidden />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p
                                    className="text-[11px] font-bold uppercase tracking-wider"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.92) }}
                                >
                                    Action required — upload approvals
                                </p>
                                <p className="mt-1.5 text-[15px] font-semibold leading-snug text-white">
                                    {primaryApproval.label}
                                </p>
                                <p
                                    className="mt-2 inline-flex items-center gap-1 text-sm font-semibold"
                                    style={{ color: hexToRgba(actionHighlightHex, 0.96) }}
                                >
                                    Open approval queue
                                    <ChevronRightIcon className="h-4 w-4" />
                                </p>
                            </div>
                        </div>
                    </Link>
                </motion.div>
            ) : null}

            {otherSignals.length > 0 ? (
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
                        {otherSignals.map((signal, i) => {
                            const Icon = ICON_MAP[signal.icon] || SparklesIcon
                            const isHigh = signal.priority === 'high'
                            const insightType = signalCategoryToInsightType(signal)
                            const matchedInsight = insightType && (insights || []).find((ins) => ins.type === insightType)
                            const content = (
                                <>
                                    <div
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                        style={{
                                            backgroundColor: isHigh ? 'rgba(251, 191, 36, 0.12)' : `${brandColor}18`,
                                        }}
                                    >
                                        <Icon
                                            className={`h-3.5 w-3.5 shrink-0 ${isHigh ? 'text-amber-400/85' : ''}`}
                                            style={!isHigh ? { color: iconFill } : undefined}
                                        />
                                    </div>
                                    <span className={isHigh ? 'text-white/95 font-medium' : 'text-white/85'}>
                                        {signal.label}
                                    </span>
                                </>
                            )
                            return (
                                <motion.li
                                    key={`${signal.label}-${i}`}
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: i * 0.05, duration: 0.3 }}
                                    className={`
                                        py-2.5 first:pt-0 last:pb-0
                                        ${isHigh ? 'animate-pulse-subtle' : ''}
                                    `}
                                >
                                    <div className="flex flex-col gap-1">
                                        <div className="flex items-center gap-2.5">
                                            {signal.href ? (
                                                <Link
                                                    href={signal.href}
                                                    className="flex items-center gap-2.5 w-full rounded-md px-2 py-1 -mx-2 -my-1 transition-all duration-200 hover:bg-white/[0.06]"
                                                >
                                                    {content}
                                                </Link>
                                            ) : (
                                                <div className="flex items-center gap-2.5">
                                                    {content}
                                                </div>
                                            )}
                                        </div>
                                        {matchedInsight?.text && (
                                            <div className="flex items-start gap-2 pl-11 -mt-0.5">
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
                    {listPrimaryHref ? (
                        <Link
                            href={listPrimaryHref}
                            className="
                                mt-4 inline-flex items-center gap-1.5 text-sm font-medium
                                text-white/90 hover:text-white
                                transition-colors duration-200
                            "
                        >
                            Review Now
                            <ChevronRightIcon className="w-4 h-4" />
                        </Link>
                    ) : null}
                </div>
            </div>
            ) : null}
        </motion.div>
    )
}

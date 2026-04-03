import { motion } from 'framer-motion'
import {
    ArrowTrendingUpIcon,
    PlusIcon,
    CheckIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'
import { summarizeMomentum } from '../../utils/summarizeMomentum'
import { resolveOverviewIconColor } from '../../utils/colorUtils'

const ICON_MAP = {
    'arrow-up': ArrowTrendingUpIcon,
    plus: PlusIcon,
    check: CheckIcon,
    user: UserGroupIcon,
}

/**
 * RecentMomentum — aggregated meaningful activity (not passive logs).
 * Max 4 items. Slightly lower visual weight than signals.
 */
export default function RecentMomentum({ data = {}, brandColor = '#6366f1', iconAccentColor = null }) {
    const iconFill = iconAccentColor ?? resolveOverviewIconColor(brandColor)
    const items = summarizeMomentum(data)

    if (items.length === 0) return null

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.15 }}
        >
            <div
                className="rounded-2xl overflow-hidden border border-white/[0.06] bg-white/[0.035] backdrop-blur-sm px-4 py-3.5 sm:px-5"
                style={{ boxShadow: `0 0 20px ${brandColor}0c` }}
            >
                <div className="flex items-center gap-2.5 mb-2.5">
                    <div
                        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                        style={{ backgroundColor: `${brandColor}1c` }}
                    >
                        <ArrowTrendingUpIcon className="h-3.5 w-3.5" style={{ color: iconFill }} />
                    </div>
                    <span className="text-xs font-semibold uppercase tracking-wider text-white/40">
                        Recent momentum
                    </span>
                </div>
                <ul className="space-y-2">
                    {items.map((item, i) => {
                        const Icon = ICON_MAP[item.icon] || ArrowTrendingUpIcon
                        return (
                            <motion.li
                                key={i}
                                initial={{ opacity: 0, y: 6 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.05 + i * 0.05, duration: 0.3 }}
                                className="flex items-center gap-2.5 text-[13px] text-white/55"
                            >
                                <div
                                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/[0.04]"
                                    style={{ boxShadow: `inset 0 0 0 1px ${brandColor}14` }}
                                >
                                    <Icon className="h-3.5 w-3.5 shrink-0" style={{ color: iconFill }} />
                                </div>
                                <span>{item.label}</span>
                                {item.trend != null && (
                                    <span className={`text-xs ${item.trend >= 0 ? 'text-green-400/80' : 'text-red-400/80'}`}>
                                        {item.trend >= 0 ? '+' : ''}{item.trend}%
                                    </span>
                                )}
                            </motion.li>
                        )
                    })}
                </ul>
            </div>
        </motion.div>
    )
}

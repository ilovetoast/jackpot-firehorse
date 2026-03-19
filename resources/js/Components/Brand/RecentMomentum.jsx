import { motion } from 'framer-motion'
import {
    ArrowTrendingUpIcon,
    PlusIcon,
    CheckIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'
import { summarizeMomentum } from '../../utils/summarizeMomentum'

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
export default function RecentMomentum({ data = {} }) {
    const items = summarizeMomentum(data)

    if (items.length === 0) return null

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.15 }}
        >
            <div className="rounded-xl overflow-hidden bg-white/[0.03] backdrop-blur-sm ring-1 ring-white/[0.06] px-5 py-4">
                <div className="flex items-center gap-2 mb-3">
                    <span className="text-xs font-semibold uppercase tracking-wider text-white/40">
                        🕒 Recent Momentum
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
                                className="flex items-center gap-3 text-sm text-white/55"
                            >
                                <Icon className="w-4 h-4 text-white/40 shrink-0" />
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

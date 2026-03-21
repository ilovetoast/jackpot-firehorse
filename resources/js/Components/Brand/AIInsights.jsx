import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { LightBulbIcon, ArrowRightIcon } from '@heroicons/react/24/outline'

/**
 * AIInsights — LLM-generated or rule-based human-readable insights.
 * Supports priority styling (high → brighter, medium → default, low → dim).
 * Optional href for clickable insights linking to Analytics or Assets.
 */
export default function AIInsights({ insights = [] }) {
    const display = (insights || [])
        .slice(0, 2)
        .map((item) =>
            typeof item === 'string'
                ? { text: item, priority: 'medium', href: null }
                : {
                      text: item.text ?? '',
                      priority: item.priority ?? 'medium',
                      href: item.href ?? null,
                  }
        )
        .filter((item) => item.text)

    if (display.length === 0) return null

    const priorityStyles = {
        high: 'text-white/70',
        medium: 'text-white/45',
        low: 'text-white/30',
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.1 }}
        >
            <div className="rounded-xl overflow-hidden bg-white/[0.02] backdrop-blur-sm px-4 py-3 sm:px-5">
                <div className="flex items-center gap-2">
                    <span className="text-[10px] font-medium uppercase tracking-wider text-white/25">
                        💡 Insights
                    </span>
                </div>
                {/* Cap height on small screens so the hero doesn’t push actions below the fold */}
                <ul
                    className="mt-2 space-y-1.5 max-sm:max-h-[min(36vh,200px)] max-sm:overflow-y-auto max-sm:overscroll-y-contain max-sm:[scrollbar-width:thin] sm:mt-2 sm:max-h-none sm:overflow-visible"
                >
                        {display.map((item, i) => {
                            const style = priorityStyles[item.priority] ?? priorityStyles.medium
                            const inner = (
                                <>
                                    <LightBulbIcon className="w-3.5 h-3.5 text-amber-400/40 shrink-0 mt-0.5" />
                                    <span className={`${style} flex-1`}>{item.text}</span>
                                    {item.href && (
                                        <ArrowRightIcon className="w-3.5 h-3.5 text-white/25 shrink-0 mt-0.5" />
                                    )}
                                </>
                            )
                            return (
                                <motion.li
                                    key={i}
                                    initial={{ opacity: 0, x: -4 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.05 + i * 0.05, duration: 0.3 }}
                                    className={`flex items-start gap-2 text-sm italic ${item.href ? 'cursor-pointer hover:opacity-95' : ''}`}
                                >
                                    {item.href ? (
                                        <Link href={item.href} className="flex items-start gap-2 w-full">
                                            {inner}
                                        </Link>
                                    ) : (
                                        <span className="flex items-start gap-2">{inner}</span>
                                    )}
                                </motion.li>
                            )
                        })}
                </ul>
            </div>
        </motion.div>
    )
}

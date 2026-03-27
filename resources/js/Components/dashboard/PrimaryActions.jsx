import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import {
    GlobeAltIcon,
    ChartBarIcon,
} from '@heroicons/react/24/outline'

const ICON_MAP = {
    portal: GlobeAltIcon,
    insights: ChartBarIcon,
}

const ALL_ACTIONS = [
    {
        key: 'portal',
        title: 'Brand Settings',
        description: 'All brand settings',
        // Scoped to the `brand` prop (workspace brand you’re viewing); same tenant — not the agency switch.
        hrefFn: (brand) => (brand?.id ? `/app/brands/${brand.id}/edit#brand-portal` : null),
        permission: 'canManageBrand',
    },
    {
        key: 'insights',
        title: 'Insights',
        description: 'Track usage and engagement',
        href: '/app/insights/overview',
        permission: 'canViewAnalytics',
    },
]

export default function PrimaryActions({ permissions = {}, brand = null, brandColor = '#6366f1' }) {
    const actions = ALL_ACTIONS.filter((action) => {
        if (action.always) return true
        if (action.permission) return permissions[action.permission] === true
        return false
    }).map((action) => ({
        ...action,
        href: action.hrefFn ? action.hrefFn(brand) : action.href,
    })).filter((action) => action.href)

    if (actions.length === 0) {
        return (
            <div className="text-center text-white/60 mt-20">
                No available actions for this workspace.
            </div>
        )
    }

    return (
        <div className="grid grid-cols-1 gap-3 w-full sm:grid-cols-2 sm:gap-4">
            {actions.map((a, i) => {
                const Icon = ICON_MAP[a.key]
                return (
                    <motion.div
                        key={a.key}
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: i * 0.06, duration: 0.4 }}
                    >
                        <Link
                            href={a.href}
                            className={`
                                group flex items-center gap-4 min-h-[80px]
                                w-full rounded-xl px-5 py-4
                                bg-gradient-to-br from-white/[0.06] to-white/[0.02]
                                backdrop-blur-sm
                                ring-1 ring-white/[0.08]
                                transition-all duration-200 ease-out
                                hover:scale-[1.02]
                            `}
                            style={{
                                boxShadow: '0 0 0 1px rgba(255,255,255,0.05)',
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.boxShadow = `0 0 20px ${brandColor}26`
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.05)'
                            }}
                        >
                            {Icon && (
                                <div className="shrink-0 rounded-xl flex items-center justify-center bg-white/10 group-hover:bg-white/[0.18] transition-colors duration-200 w-10 h-10">
                                    <Icon className="h-5 w-5 text-white/70 group-hover:text-white transition-colors duration-200" />
                                </div>
                            )}
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold text-white text-sm">
                                    {a.title}
                                </h3>
                                <p className="text-xs text-white/45 leading-relaxed mt-0.5">
                                    {a.description}
                                </p>
                            </div>
                            <svg className="text-white/20 group-hover:text-white/50 transition-colors shrink-0 w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </Link>
                    </motion.div>
                )
            })}
        </div>
    )
}

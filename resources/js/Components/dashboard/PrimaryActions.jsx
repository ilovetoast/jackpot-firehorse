import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import {
    GlobeAltIcon,
    ChartBarIcon,
    PhotoIcon,
    RectangleStackIcon,
    ArrowDownTrayIcon,
    ArrowTrendingUpIcon,
} from '@heroicons/react/24/outline'
import { resolveOverviewIconColor } from '../../utils/colorUtils'

const ICON_MAP = {
    portal: GlobeAltIcon,
    insights: ChartBarIcon,
    assets: PhotoIcon,
    executions: RectangleStackIcon,
    creator_downloads: ArrowDownTrayIcon,
    creator_progress: ArrowTrendingUpIcon,
}

const ALL_ACTIONS = [
    {
        key: 'portal',
        getTitle: (b) => `${brandDisplayName(b)} Management`,
        description: 'All brand settings',
        // Scoped to the `brand` prop (workspace brand you’re viewing); same tenant — not the agency switch.
        hrefFn: (brand) => (brand?.id ? `/app/brands/${brand.id}/edit#brand-portal` : null),
        permission: 'canManageBrand',
    },
    {
        key: 'insights',
        getTitle: (b) => `${brandDisplayName(b)} Insights`,
        description: 'Track usage and engagement',
        href: '/app/insights/overview',
        permission: 'canViewAnalytics',
    },
]

const QUICK_LINKS = [
    {
        key: 'assets',
        title: 'Asset Library',
        description: 'Parts and pieces of the brand',
        href: '/app/assets',
        permission: 'canViewBrandAssets',
    },
    {
        key: 'executions',
        title: 'Executions',
        description: 'Finalized Deliverables',
        href: '/app/executions',
        permission: 'canViewBrandExecutions',
    },
]

/** Prostaff creators on cinematic overview — scoped downloads + progress dashboard. */
const CREATOR_QUICK_LINKS = [
    {
        key: 'creator_downloads',
        title: 'My downloads',
        description: 'ZIP links you made—preparing, ready, or expired',
        permission: 'showCreatorOverviewQuickLinks',
        hrefFn: (brand) => {
            if (!brand?.id) return null
            return typeof route === 'function'
                ? route('downloads.index', { scope: 'mine', brand_id: brand.id })
                : `/app/downloads?scope=mine&brand_id=${encodeURIComponent(brand.id)}`
        },
    },
    {
        key: 'creator_progress',
        title: 'Creator progress',
        description: 'Upload pipeline, targets, and how you compare',
        permission: 'showCreatorOverviewQuickLinks',
        hrefFn: () =>
            typeof route === 'function'
                ? route('overview.creator-progress')
                : '/app/overview/creator-progress',
    },
]

function brandDisplayName(brand) {
    const n = brand?.name
    return typeof n === 'string' && n.trim() !== '' ? n.trim() : 'Brand'
}

function ActionCardLink({ href, Icon, title, description, brandColor, iconFill, delayIndex }) {
    const wellBg = `${brandColor}1a`
    const hoverGlow = `${brandColor}33`
    return (
        <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: delayIndex * 0.06, duration: 0.4 }}
        >
            <Link
                href={href}
                className={`
                    group flex items-center gap-3 min-h-[72px]
                    w-full rounded-2xl px-4 py-3.5
                    bg-gradient-to-br from-white/[0.07] to-white/[0.02]
                    backdrop-blur-sm
                    ring-1 ring-white/[0.1]
                    transition-all duration-200 ease-out
                    hover:scale-[1.01]
                `}
                style={{
                    boxShadow: `0 0 0 1px rgba(255,255,255,0.04), 0 8px 24px rgba(0,0,0,0.12)`,
                }}
                onMouseEnter={(e) => {
                    e.currentTarget.style.boxShadow = `0 0 0 1px ${hoverGlow}, 0 10px 28px rgba(0,0,0,0.18), 0 0 24px ${brandColor}22`
                }}
                onMouseLeave={(e) => {
                    e.currentTarget.style.boxShadow =
                        '0 0 0 1px rgba(255,255,255,0.04), 0 8px 24px rgba(0,0,0,0.12)'
                }}
            >
                {Icon && (
                    <div
                        className="shrink-0 flex h-9 w-9 items-center justify-center rounded-xl transition-colors duration-200 group-hover:brightness-110"
                        style={{ backgroundColor: wellBg }}
                    >
                        <Icon className="h-[18px] w-[18px]" style={{ color: iconFill }} />
                    </div>
                )}
                <div className="min-w-0 flex-1">
                    <h3 className="font-semibold text-white text-[13px] leading-snug">{title}</h3>
                    <p className="text-[11px] text-white/45 leading-relaxed mt-0.5">{description}</p>
                </div>
                <svg
                    className="text-white/[0.18] group-hover:text-white/45 transition-colors shrink-0 w-4 h-4"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </Link>
        </motion.div>
    )
}

export default function PrimaryActions({
    permissions = {},
    brand = null,
    brandColor = '#71717a',
    iconAccentColor = null,
    authUserId = null,
}) {
    const iconFill = iconAccentColor ?? resolveOverviewIconColor(brandColor)

    const actions = ALL_ACTIONS.filter((action) => {
        if (action.always) return true
        if (action.permission) return permissions[action.permission] === true
        return false
    })
        .map((action) => ({
            ...action,
            href: action.hrefFn ? action.hrefFn(brand) : action.href,
            title: action.getTitle(brand),
        }))
        .filter((action) => action.href)

    const quickLinks = [
        ...QUICK_LINKS.filter((link) => permissions[link.permission] === true),
        ...CREATOR_QUICK_LINKS.filter((link) => permissions[link.permission] === true)
            .map((link) => ({
                ...link,
                href: link.hrefFn ? link.hrefFn(brand, authUserId) : link.href,
            }))
            .filter((link) => link.href),
    ]

    if (actions.length === 0) {
        if (quickLinks.length === 0) {
            return null
        }
        return (
            <div className="grid grid-cols-1 gap-3 w-full sm:grid-cols-2 sm:gap-4 mt-8 sm:mt-12">
                {quickLinks.map((a, i) => {
                    const Icon = ICON_MAP[a.key]
                    return (
                        <ActionCardLink
                            key={a.key}
                            href={a.href}
                            Icon={Icon}
                            title={a.title}
                            description={a.description}
                            brandColor={brandColor}
                            iconFill={iconFill}
                            delayIndex={i}
                        />
                    )
                })}
            </div>
        )
    }

    return (
        <div className="grid grid-cols-1 gap-3 w-full sm:grid-cols-2 sm:gap-4">
            {actions.map((a, i) => {
                const Icon = ICON_MAP[a.key]
                return (
                    <ActionCardLink
                        key={a.key}
                        href={a.href}
                        Icon={Icon}
                        title={a.title}
                        description={a.description}
                        brandColor={brandColor}
                        iconFill={iconFill}
                        delayIndex={i}
                    />
                )
            })}
        </div>
    )
}

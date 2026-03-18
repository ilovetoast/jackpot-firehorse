import { useState, useEffect } from 'react'
import { Link } from '@inertiajs/react'
import {
    PhotoIcon,
    SwatchIcon,
    RectangleGroupIcon,
    GlobeAltIcon,
    UsersIcon,
    ChartBarIcon,
} from '@heroicons/react/24/outline'

const ICON_MAP = {
    assets: PhotoIcon,
    guidelines: SwatchIcon,
    collections: RectangleGroupIcon,
    portal: GlobeAltIcon,
    team: UsersIcon,
    analytics: ChartBarIcon,
}

const ALL_ACTIONS = [
    {
        key: 'assets',
        title: 'Assets',
        description: 'Browse and manage your brand assets',
        href: '/app/assets',
        always: true,
    },
    {
        key: 'guidelines',
        title: 'Brand Guidelines',
        description: 'View identity, voice, and rules',
        href: '/app/brand-guidelines',
        always: true,
    },
    {
        key: 'collections',
        title: 'Collections',
        description: 'Organize and share grouped assets',
        href: '/app/collections',
        always: true,
    },
    {
        key: 'portal',
        title: 'Brand Portal',
        description: 'Manage public experience',
        hrefFn: (brand) => brand ? `/app/brands/${brand.id}/edit#brand-portal` : null,
        permission: 'canManageBrand',
    },
    {
        key: 'team',
        title: 'Team',
        description: 'Manage users and permissions',
        href: '/app/companies/team',
        permission: 'canManageTeam',
    },
    {
        key: 'analytics',
        title: 'Analytics',
        description: 'Track usage and engagement',
        href: '/app/analytics',
        permission: 'canViewAnalytics',
    },
]

export default function PrimaryActions({ permissions = {}, brand = null }) {
    const [compact, setCompact] = useState(false)

    useEffect(() => {
        const check = () => setCompact(window.innerHeight < 820)
        check()
        window.addEventListener('resize', check, { passive: true })
        return () => window.removeEventListener('resize', check)
    }, [])

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
        <div className={compact ? 'grid grid-cols-2 gap-2' : 'space-y-3'}>
            {actions.map((a, i) => {
                const Icon = ICON_MAP[a.key]
                return (
                    <Link
                        key={a.key}
                        href={a.href}
                        className={`
                            group flex items-center
                            w-full
                            bg-white/[0.04] backdrop-blur-sm
                            ring-1 ring-white/[0.08]
                            transition-all duration-300 ease-out
                            hover:bg-white/[0.09]
                            hover:ring-white/[0.16]
                            hover:shadow-[0_10px_40px_rgba(0,0,0,0.4)]
                            animate-fadeInUp-d${compact ? Math.min(Math.floor(i / 2) + 1, 4) : Math.min(i + 1, 4)}
                            ${compact
                                ? 'gap-3 rounded-xl px-4 py-3'
                                : 'gap-5 rounded-2xl px-6 py-4'
                            }
                        `}
                    >
                        {Icon && (
                            <div className={`shrink-0 rounded-xl flex items-center justify-center bg-white/10 group-hover:bg-white/[0.18] transition-colors duration-300 ${compact ? 'w-8 h-8' : 'w-10 h-10'}`}>
                                <Icon className={`text-white/70 group-hover:text-white transition-colors duration-300 ${compact ? 'h-4 w-4' : 'h-5 w-5'}`} />
                            </div>
                        )}
                        <div className="min-w-0 flex-1">
                            <h3 className={`font-semibold text-white ${compact ? 'text-xs' : 'text-sm'}`}>
                                {a.title}
                            </h3>
                            {!compact && (
                                <p className="text-xs text-white/45 leading-relaxed mt-0.5">
                                    {a.description}
                                </p>
                            )}
                        </div>
                        <svg className={`text-white/20 group-hover:text-white/50 transition-colors shrink-0 ${compact ? 'w-3 h-3' : 'w-4 h-4'}`} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </Link>
                )
            })}
        </div>
    )
}

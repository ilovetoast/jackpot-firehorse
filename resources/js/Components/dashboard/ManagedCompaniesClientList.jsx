import { motion } from 'framer-motion'
import { router } from '@inertiajs/react'
import { BuildingOffice2Icon, ChevronRightIcon } from '@heroicons/react/24/outline'
import { showWorkspaceSwitchingOverlay } from '../../utils/workspaceSwitchOverlay'

function ManagedCompanyCard({ client, index, theme, brandColor }) {
    const brands = Array.isArray(client.brands) ? client.brands : []
    const isDark = theme === 'dark'

    const openWorkspace = (brandId) => {
        showWorkspaceSwitchingOverlay('company')
        const body = { redirect: '/app/overview' }
        if (brandId != null) {
            body.brand_id = brandId
        }
        router.post(`/app/companies/${client.id}/switch`, body, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                window.location.href = '/app/overview'
            },
            onError: () => {
                window.location.href = '/app/overview'
            },
        })
    }

    const cardClass = isDark
        ? 'flex w-full flex-col gap-3 rounded-xl bg-gradient-to-br from-white/[0.07] to-white/[0.02] px-5 py-4 text-left ring-1 ring-white/[0.1] backdrop-blur-sm transition-all duration-200 hover:scale-[1.01] focus-within:ring-2 focus-within:ring-white/30'
        : 'flex w-full flex-col gap-3 rounded-xl border border-gray-200 bg-white px-5 py-4 text-left shadow-sm transition-all duration-200 hover:border-indigo-200 hover:shadow-md focus-within:ring-2 focus-within:ring-indigo-500'

    const rowClass = isDark
        ? 'group flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-left transition hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30'
        : 'group flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-left transition hover:bg-indigo-50/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500'

    const labelClass = isDark ? 'text-[10px] font-medium uppercase tracking-wider text-white/35' : 'text-[10px] font-medium uppercase tracking-wider text-gray-400'

    const brandNameClass = isDark ? 'text-sm font-medium text-white' : 'text-sm font-medium text-gray-900'

    const chevronClass = isDark
        ? 'h-5 w-5 shrink-0 text-white/30 transition group-hover:text-white/60'
        : 'h-5 w-5 shrink-0 text-gray-400 transition group-hover:text-indigo-600'

    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.04 + index * 0.04, duration: 0.35 }}
            className={cardClass}
            style={isDark ? { boxShadow: '0 0 0 1px rgba(255,255,255,0.06)' } : undefined}
            onMouseEnter={(e) => {
                if (isDark) {
                    e.currentTarget.style.boxShadow = `0 0 24px ${brandColor}33`
                }
            }}
            onMouseLeave={(e) => {
                if (isDark) {
                    e.currentTarget.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.06)'
                }
            }}
        >
            <div className="flex items-start gap-4">
                <div
                    className={
                        isDark
                            ? 'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-lg'
                            : 'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-lg'
                    }
                >
                    🏢
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <BuildingOffice2Icon
                            className={isDark ? 'h-4 w-4 shrink-0 text-white/35' : 'h-4 w-4 shrink-0 text-gray-400'}
                            aria-hidden
                        />
                        <h3
                            className={
                                isDark
                                    ? 'font-semibold text-white text-sm leading-snug'
                                    : 'font-semibold text-gray-900 text-sm leading-snug'
                            }
                        >
                            {client.name}
                        </h3>
                    </div>

                    {brands.length > 0 ? (
                        <div className="mt-3 space-y-0.5">
                            {brands.length > 1 && <p className={`${labelClass} mb-1.5 px-2`}>Brands</p>}
                            {brands.map((b) => (
                                <button
                                    key={b.id}
                                    type="button"
                                    onClick={() => openWorkspace(b.id)}
                                    className={rowClass}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className={brandNameClass}>{b.name}</span>
                                        {b.is_default ? (
                                            <span
                                                className={
                                                    isDark ? 'ml-2 text-xs text-white/40' : 'ml-2 text-xs text-gray-400'
                                                }
                                            >
                                                default
                                            </span>
                                        ) : null}
                                    </span>
                                    <ChevronRightIcon className={chevronClass} aria-hidden />
                                </button>
                            ))}
                        </div>
                    ) : (
                        <div className="mt-3 border-t border-gray-100 pt-3 dark:border-white/10">
                            <button type="button" onClick={() => openWorkspace(null)} className={rowClass}>
                                <span className={isDark ? 'text-sm text-white/80' : 'text-sm text-gray-700'}>
                                    Open company workspace
                                </span>
                                <ChevronRightIcon className={chevronClass} aria-hidden />
                            </button>
                            <p className={isDark ? 'mt-1 px-2 text-xs text-white/40' : 'mt-1 px-2 text-xs text-gray-400'}>
                                Default brand will load
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </motion.div>
    )
}

/**
 * Grid of client companies; each brand is a one-click row with chevron (no dropdown).
 * @param {'dark'|'light'} theme
 */
export default function ManagedCompaniesClientList({ clients = [], brandColor = '#6366f1', theme = 'dark' }) {
    if (!clients.length) {
        return null
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.12 }}
            className="w-full"
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {clients.map((c, i) => (
                    <ManagedCompanyCard key={c.id} client={c} index={i} theme={theme} brandColor={brandColor} />
                ))}
            </div>
        </motion.div>
    )
}

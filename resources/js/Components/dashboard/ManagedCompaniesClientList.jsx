import { useState, useMemo, useCallback } from 'react'
import { motion } from 'framer-motion'
import { router } from '@inertiajs/react'
import { BuildingOffice2Icon, ChevronDownIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'
import { showWorkspaceSwitchingOverlay } from '../../utils/workspaceSwitchOverlay'
import ReadinessScoreDots from '../agency/ReadinessScoreDots'
import AgencyReadinessChecklist from '../agency/AgencyReadinessChecklist'
import { agencyNavigateToBrandPath } from '../../utils/agencyBrandNavigation'
import { effortGlyph, resolveReadinessTaskPath } from '../../utils/readinessTasks'

function BrandLogoBlock({ brand, isDark, name }) {
    const src = isDark ? brand.logo_url || brand.logo_dark_url : brand.logo_url || brand.logo_dark_url
    const accent = brand.primary_color && /^#[0-9A-Fa-f]{6}$/.test(brand.primary_color) ? brand.primary_color : null

    if (src) {
        return (
            <div className="relative flex min-h-[132px] w-full items-center justify-center px-6 py-7 sm:min-h-[160px]">
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.12]"
                    style={
                        accent
                            ? {
                                  background: `radial-gradient(ellipse 80% 70% at 50% 50%, ${accent}, transparent 72%)`,
                              }
                            : undefined
                    }
                />
                <img
                    src={src}
                    alt={`${name} logo`}
                    className="relative z-[1] max-h-24 w-full max-w-[min(100%,220px)] object-contain sm:max-h-28"
                    loading="lazy"
                    decoding="async"
                />
            </div>
        )
    }

    return (
        <div className="flex min-h-[132px] w-full flex-col items-center justify-center gap-2 px-6 py-7 sm:min-h-[160px]">
            <span
                className={
                    isDark
                        ? 'flex h-14 w-14 items-center justify-center rounded-2xl bg-white/[0.08] text-lg font-semibold text-white/80 ring-1 ring-white/10'
                        : 'flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-lg font-semibold text-indigo-700 ring-1 ring-indigo-100'
                }
                aria-hidden
            >
                {name.slice(0, 2).toUpperCase()}
            </span>
            <span className={isDark ? 'text-[11px] text-white/35' : 'text-[11px] text-gray-400'}>No logo yet</span>
        </div>
    )
}

function ManagedCompanyCard({
    client,
    index,
    theme,
    brandColor,
    showReadiness = false,
    expandedBrandKey,
    onExpandedBrandKeyChange,
    readinessDotsPulseKey,
}) {
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

    const companyShell = isDark
        ? 'overflow-hidden rounded-2xl bg-gradient-to-br from-white/[0.08] to-white/[0.02] ring-1 ring-white/[0.12] backdrop-blur-sm'
        : 'overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm ring-1 ring-black/[0.04]'

    const toggleExpand = (e, brandId) => {
        e.stopPropagation()
        const key = `${client.id}-${brandId}`
        const expanded = expandedBrandKey === key
        onExpandedBrandKeyChange(expanded ? null : key)
    }

    const muted = isDark ? 'text-white/50' : 'text-gray-600'
    const labelMuted = isDark ? 'text-white/40' : 'text-gray-500'

    return (
        <motion.article
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.04 + index * 0.04, duration: 0.35 }}
            className={companyShell}
            style={isDark ? { boxShadow: '0 0 0 1px rgba(255,255,255,0.06)' } : undefined}
            onMouseEnter={(e) => {
                if (isDark) {
                    e.currentTarget.style.boxShadow = `0 0 32px ${brandColor}28`
                }
            }}
            onMouseLeave={(e) => {
                if (isDark) {
                    e.currentTarget.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.06)'
                }
            }}
        >
            <header
                className={
                    isDark
                        ? 'border-b border-white/10 px-6 py-5 sm:px-8 sm:py-6'
                        : 'border-b border-gray-100 px-6 py-5 sm:px-8 sm:py-6'
                }
            >
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <p
                            className={
                                isDark
                                    ? 'text-[11px] font-medium uppercase tracking-wider text-white/40'
                                    : 'text-[11px] font-medium uppercase tracking-wider text-gray-400'
                            }
                        >
                            Client company
                        </p>
                        <h3
                            className={
                                isDark
                                    ? 'mt-1 text-2xl font-semibold tracking-tight text-white sm:text-[1.65rem]'
                                    : 'mt-1 text-2xl font-semibold tracking-tight text-gray-900 sm:text-[1.65rem]'
                            }
                        >
                            {client.name}
                        </h3>
                    </div>
                    {brands.length > 0 && (
                        <span
                            className={
                                isDark
                                    ? 'shrink-0 rounded-full bg-white/[0.06] px-3 py-1 text-xs font-medium text-white/70 ring-1 ring-white/10'
                                    : 'shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-200/80'
                            }
                        >
                            {brands.length} brand{brands.length !== 1 ? 's' : ''}
                        </span>
                    )}
                </div>
            </header>

            <div className={isDark ? 'px-4 py-5 sm:px-6 sm:py-7' : 'px-4 py-5 sm:px-6 sm:py-7'}>
                {brands.length > 0 ? (
                    <div className="flex flex-col gap-5">
                        {brands.map((b) => {
                            const r = b.readiness
                            const score = r?.readiness_score ?? 0
                            const tasks = Array.isArray(r?.readiness_tasks) ? r.readiness_tasks : []
                            const tooltip = r?.readiness_tooltip || ''
                            const expanded = expandedBrandKey === `${client.id}-${b.id}`
                            const accent =
                                b.primary_color && /^#[0-9A-Fa-f]{6}$/.test(b.primary_color) ? b.primary_color : brandColor
                            const actions = b.actions || {}
                            const refAlert = r?.reference_alert
                            const cardDomId = `managed-brand-${client.id}-${b.id}`

                            return (
                                <div
                                    key={b.id}
                                    id={cardDomId}
                                    className={
                                        isDark
                                            ? 'overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02]'
                                            : 'overflow-hidden rounded-2xl border border-gray-200/90 bg-gray-50/30'
                                    }
                                >
                                    <div className="flex flex-col lg:flex-row">
                                        <div
                                            className={
                                                isDark
                                                    ? 'relative shrink-0 border-b border-white/10 bg-black/20 lg:w-[min(100%,280px)] lg:border-b-0 lg:border-r lg:border-white/10'
                                                    : 'relative shrink-0 border-b border-gray-200 bg-white lg:w-[min(100%,280px)] lg:border-b-0 lg:border-r lg:border-gray-200'
                                            }
                                        >
                                            <BrandLogoBlock brand={b} isDark={isDark} name={b.name} />
                                        </div>

                                        <div className="flex min-w-0 flex-1 flex-col justify-center gap-4 p-5 sm:p-6 lg:p-7">
                                            <div className="flex flex-wrap items-start justify-between gap-4">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h4
                                                            className={
                                                                isDark
                                                                    ? 'text-lg font-semibold leading-snug text-white sm:text-xl'
                                                                    : 'text-lg font-semibold leading-snug text-gray-900 sm:text-xl'
                                                            }
                                                        >
                                                            {b.name}
                                                        </h4>
                                                        {b.is_default ? (
                                                            <span
                                                                className={
                                                                    isDark
                                                                        ? 'rounded-md bg-white/[0.08] px-2 py-0.5 text-xs font-medium text-white/65 ring-1 ring-white/10'
                                                                        : 'rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-800 ring-1 ring-indigo-100'
                                                                }
                                                            >
                                                                Default
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <p className={`mt-1.5 flex items-center gap-2 text-sm ${labelMuted}`}>
                                                        <BuildingOffice2Icon className="h-4 w-4 shrink-0 opacity-70" aria-hidden />
                                                        <span className="truncate">{client.name}</span>
                                                    </p>
                                                </div>

                                                {showReadiness && r && (
                                                    <div className="flex shrink-0 flex-col items-end gap-1 sm:items-end">
                                                        <div className="flex items-center gap-2.5">
                                                            <ReadinessScoreDots
                                                                score={score}
                                                                title={tooltip}
                                                                pulseKey={readinessDotsPulseKey}
                                                            />
                                                            <span
                                                                className={
                                                                    isDark
                                                                        ? 'text-base font-medium tabular-nums text-white/90'
                                                                        : 'text-base font-medium tabular-nums text-gray-900'
                                                                }
                                                                title={tooltip}
                                                            >
                                                                {score}/5
                                                            </span>
                                                        </div>
                                                        <span className={`text-xs ${muted}`}>Readiness</span>
                                                    </div>
                                                )}
                                            </div>

                                            {showReadiness && r && tasks.length > 0 && !expanded && (
                                                <p className={`text-sm leading-relaxed ${muted}`}>
                                                    {tasks.length} checklist task{tasks.length !== 1 ? 's' : ''} — expand below
                                                    to review, or open the workspace to work in context.
                                                </p>
                                            )}

                                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                                <button
                                                    type="button"
                                                    onClick={() => openWorkspace(b.id)}
                                                    className={
                                                        isDark
                                                            ? 'inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white ring-1 ring-white/15 transition hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/35 sm:w-auto'
                                                            : 'inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 sm:w-auto'
                                                    }
                                                    style={
                                                        isDark
                                                            ? {
                                                                  background: `linear-gradient(135deg, ${accent}33, rgba(255,255,255,0.06))`,
                                                                  boxShadow: `0 0 24px ${accent}22`,
                                                              }
                                                            : undefined
                                                    }
                                                >
                                                    Open workspace
                                                </button>
                                                {showReadiness && r && (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => toggleExpand(e, b.id)}
                                                        className={
                                                            isDark
                                                                ? 'inline-flex w-full items-center justify-center gap-2 rounded-xl bg-white/[0.04] px-4 py-3 text-sm font-medium text-white/85 ring-1 ring-white/10 transition hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/30 sm:w-auto'
                                                                : 'inline-flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-800 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 sm:w-auto'
                                                        }
                                                        aria-expanded={expanded}
                                                    >
                                                        <ChevronDownIcon
                                                            className={`h-4 w-4 shrink-0 transition ${expanded ? 'rotate-180' : ''}`}
                                                        />
                                                        {expanded ? 'Hide readiness details' : 'View readiness details'}
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {showReadiness && expanded && r && (
                                        <div
                                            className={
                                                isDark
                                                    ? 'border-t border-white/10 bg-black/25 px-5 pb-6 pt-4 sm:px-7'
                                                    : 'border-t border-gray-100 bg-white px-5 pb-6 pt-4 sm:px-7'
                                            }
                                        >
                                            {refAlert && refAlert.current < refAlert.min && (
                                                <p className="mt-1 text-sm text-amber-200/90">
                                                    ⚠️ Weak reference set ({refAlert.current}/{refAlert.min})
                                                </p>
                                            )}

                                            {tasks.length > 0 && (
                                                <ul className={`mt-3 space-y-1.5 text-sm ${isDark ? 'text-white/80' : 'text-gray-800'}`}>
                                                    {tasks.slice(0, 3).map((t, ti) => (
                                                        <li key={ti}>
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    agencyNavigateToBrandPath(
                                                                        client.id,
                                                                        b.id,
                                                                        resolveReadinessTaskPath(t.action, actions)
                                                                    )
                                                                }
                                                                className={
                                                                    isDark
                                                                        ? 'flex w-full items-start gap-2 rounded-lg py-1.5 text-left text-white/85 hover:bg-white/[0.06]'
                                                                        : 'flex w-full items-start gap-2 rounded-lg py-1.5 text-left text-gray-800 hover:bg-gray-50'
                                                                }
                                                            >
                                                                <span className={isDark ? 'text-white/45' : 'text-gray-400'}>•</span>
                                                                <span className="flex-1">{t.label}</span>
                                                                <span
                                                                    className={isDark ? 'shrink-0 text-white/40' : 'shrink-0 text-gray-400'}
                                                                    title={t.effort}
                                                                >
                                                                    {effortGlyph(t.effort)}
                                                                </span>
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}

                                            {refAlert && refAlert.current < refAlert.min && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        agencyNavigateToBrandPath(
                                                            client.id,
                                                            b.id,
                                                            resolveReadinessTaskPath('references_promote', actions)
                                                        )
                                                    }
                                                    className="mt-3 inline-flex w-full items-center justify-center gap-1 rounded-xl bg-amber-500/15 px-3 py-2.5 text-sm font-medium text-amber-100 ring-1 ring-amber-500/30 hover:bg-amber-500/25 sm:w-auto"
                                                >
                                                    Promote assets
                                                    <ArrowTopRightOnSquareIcon className="h-4 w-4 opacity-80" />
                                                </button>
                                            )}

                                            <AgencyReadinessChecklist
                                                tenantId={client.id}
                                                brand={b}
                                                readiness={r}
                                                actions={actions}
                                                theme={theme}
                                            />

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        agencyNavigateToBrandPath(client.id, b.id, actions.guidelines_builder_path)
                                                    }
                                                    className={
                                                        isDark
                                                            ? 'inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-2 text-xs font-medium text-white/90 ring-1 ring-white/15 hover:bg-white/15'
                                                            : 'inline-flex items-center gap-1 rounded-lg bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-800 ring-1 ring-indigo-200 hover:bg-indigo-100'
                                                    }
                                                >
                                                    Guidelines
                                                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => agencyNavigateToBrandPath(client.id, b.id, actions.assets_path)}
                                                    className={
                                                        isDark
                                                            ? 'inline-flex items-center gap-1 rounded-lg bg-white/10 px-3 py-2 text-xs font-medium text-white/90 ring-1 ring-white/15 hover:bg-white/15'
                                                            : 'inline-flex items-center gap-1 rounded-lg bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-800 ring-1 ring-indigo-200 hover:bg-indigo-100'
                                                    }
                                                >
                                                    Assets
                                                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        agencyNavigateToBrandPath(
                                                            client.id,
                                                            b.id,
                                                            actions.reference_materials_path || actions.assets_path
                                                        )
                                                    }
                                                    className={
                                                        isDark
                                                            ? 'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-medium text-white/90 ring-1 ring-white/20 hover:bg-white/10'
                                                            : 'inline-flex items-center gap-1 rounded-lg bg-white px-3 py-2 text-xs font-medium text-gray-800 ring-1 ring-gray-200 hover:bg-gray-50'
                                                    }
                                                    style={isDark ? { boxShadow: `0 0 12px ${brandColor}22` } : undefined}
                                                >
                                                    References
                                                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )
                        })}
                    </div>
                ) : (
                    <div className={isDark ? 'border-t border-white/10 pt-5' : 'border-t border-gray-100 pt-5'}>
                        <button
                            type="button"
                            onClick={() => openWorkspace(null)}
                            className={
                                isDark
                                    ? 'inline-flex w-full items-center justify-center rounded-xl bg-white/[0.06] px-5 py-3 text-sm font-semibold text-white ring-1 ring-white/12 hover:bg-white/[0.1] sm:w-auto'
                                    : 'inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-500 sm:w-auto'
                            }
                        >
                            Open company workspace
                        </button>
                        <p className={`mt-3 text-sm ${muted}`}>Default brand will load.</p>
                    </div>
                )}
            </div>
        </motion.article>
    )
}

/**
 * Full-width stacked client companies; each brand shows logo, readiness, and actions.
 * @param {'dark'|'light'} theme
 * @param {boolean} props.showReadiness — agency dashboard: readiness + expandable checklist
 */
export default function ManagedCompaniesClientList({
    clients = [],
    brandColor = '#6366f1',
    theme = 'dark',
    showReadiness = false,
    readinessSummary = null,
    brandsReadiness = [],
    readinessAnimateKey = 0,
}) {
    const [expandedBrandKey, setExpandedBrandKey] = useState(null)
    const [fixNextPulse, setFixNextPulse] = useState(0)

    const readinessDotsPulseKey = readinessAnimateKey * 1000 + fixNextPulse

    const lowestRow = useMemo(() => {
        if (!brandsReadiness.length) {
            return null
        }
        return brandsReadiness.reduce((best, row) => {
            const sb = row.brand?.readiness?.readiness_score ?? 0
            const bb = best.brand?.readiness?.readiness_score ?? 0
            return sb < bb ? row : best
        }, brandsReadiness[0])
    }, [brandsReadiness])

    const handleFixNext = useCallback(() => {
        if (!lowestRow) {
            return
        }
        const key = `${lowestRow.tenant_id}-${lowestRow.brand.id}`
        setExpandedBrandKey(key)
        setFixNextPulse((p) => p + 1)
        requestAnimationFrame(() => {
            document.getElementById(`managed-brand-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        })
    }, [lowestRow])

    if (!clients.length) {
        return null
    }

    const s = readinessSummary || {}
    const n = s.brand_count ?? brandsReadiness.length

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: 0.12 }}
            className="w-full"
        >
            {showReadiness && brandsReadiness.length > 0 && n > 0 && (
                <div className="mb-8 space-y-4">
                    <div
                        className={
                            theme === 'dark'
                                ? 'rounded-xl border border-white/10 bg-white/[0.03] px-4 py-4 text-sm text-white/70 sm:px-5'
                                : 'rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 text-sm text-gray-700 sm:px-5'
                        }
                    >
                        <p
                            className={
                                theme === 'dark'
                                    ? 'text-[10px] font-medium uppercase tracking-wider text-white/40'
                                    : 'text-[10px] font-medium uppercase tracking-wider text-gray-500'
                            }
                        >
                            Across {n} brand{n !== 1 ? 's' : ''}
                        </p>
                        <ul className="mt-2 space-y-1">
                            <li>
                                • Missing references:{' '}
                                <span className={theme === 'dark' ? 'tabular-nums text-white/85' : 'tabular-nums text-gray-900'}>
                                    {s.brands_missing_references ?? 0}
                                </span>{' '}
                                brand{(s.brands_missing_references ?? 0) !== 1 ? 's' : ''}
                            </li>
                            <li>
                                • Missing typography:{' '}
                                <span className={theme === 'dark' ? 'tabular-nums text-white/85' : 'tabular-nums text-gray-900'}>
                                    {s.brands_missing_typography ?? 0}
                                </span>{' '}
                                brand{(s.brands_missing_typography ?? 0) !== 1 ? 's' : ''}
                            </li>
                            <li>
                                • Missing assets (under 10):{' '}
                                <span className={theme === 'dark' ? 'tabular-nums text-white/85' : 'tabular-nums text-gray-900'}>
                                    {s.brands_missing_assets ?? 0}
                                </span>{' '}
                                brand{(s.brands_missing_assets ?? 0) !== 1 ? 's' : ''}
                            </li>
                        </ul>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={handleFixNext}
                            className={
                                theme === 'dark'
                                    ? 'inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2.5 text-sm font-semibold text-white ring-1 ring-white/15 hover:bg-white/15'
                                    : 'inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500'
                            }
                            style={{ boxShadow: theme === 'dark' ? `0 0 20px ${brandColor}22` : undefined }}
                        >
                            Fix next brand
                            <ArrowTopRightOnSquareIcon className="h-4 w-4 opacity-70" />
                        </button>
                        {lowestRow && (
                            <span className={theme === 'dark' ? 'text-xs text-white/40' : 'text-xs text-gray-500'}>
                                Lowest: {lowestRow.brand?.name} ({lowestRow.brand?.readiness?.readiness_score ?? 0}/5)
                            </span>
                        )}
                    </div>
                </div>
            )}

            <div className="flex flex-col gap-10">
                {clients.map((c, i) => (
                    <ManagedCompanyCard
                        key={c.id}
                        client={c}
                        index={i}
                        theme={theme}
                        brandColor={brandColor}
                        showReadiness={showReadiness}
                        expandedBrandKey={expandedBrandKey}
                        onExpandedBrandKeyChange={setExpandedBrandKey}
                        readinessDotsPulseKey={readinessDotsPulseKey}
                    />
                ))}
            </div>
        </motion.div>
    )
}

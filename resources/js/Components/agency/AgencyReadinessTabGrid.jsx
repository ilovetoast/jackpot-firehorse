import { useMemo, useState, useCallback } from 'react'
import { motion } from 'framer-motion'
import { ArrowTopRightOnSquareIcon, ChevronDownIcon } from '@heroicons/react/24/outline'
import ReadinessScoreDots from './ReadinessScoreDots'
import AgencyReadinessChecklist from './AgencyReadinessChecklist'
import { agencyNavigateToBrandPath } from '../../utils/agencyBrandNavigation'
import { effortGlyph, resolveReadinessTaskPath } from '../../utils/readinessTasks'

/**
 * Readiness tab: task-first cards, cross-brand summary, optional reference alert, expandable checklist.
 */
export default function AgencyReadinessTabGrid({
    brandsReadiness = [],
    brandColor = '#6366f1',
    readinessSummary = null,
    readinessAnimateKey = 0,
}) {
    const [expandedKey, setExpandedKey] = useState(null)
    const [fixNextPulse, setFixNextPulse] = useState(0)

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
        setExpandedKey(key)
        setFixNextPulse((p) => p + 1)
        requestAnimationFrame(() => {
            document.getElementById(`readiness-card-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        })
    }, [lowestRow])

    if (!brandsReadiness.length) {
        return (
            <p className="text-sm text-white/50">
                No client brands linked yet. Link companies from{' '}
                <span className="text-white/70">Company settings → Agencies</span>.
            </p>
        )
    }

    const s = readinessSummary || {}
    const n = s.brand_count ?? brandsReadiness.length

    return (
        <div className="space-y-4">
            {n > 0 && (
                <div className="rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-white/70">
                    <p className="text-[10px] font-medium uppercase tracking-wider text-white/40">Across {n} brand{n !== 1 ? 's' : ''}</p>
                    <ul className="mt-2 space-y-1">
                        <li>
                            • Missing references:{' '}
                            <span className="tabular-nums text-white/85">{s.brands_missing_references ?? 0}</span> brand
                            {(s.brands_missing_references ?? 0) !== 1 ? 's' : ''}
                        </li>
                        <li>
                            • Missing typography:{' '}
                            <span className="tabular-nums text-white/85">{s.brands_missing_typography ?? 0}</span> brand
                            {(s.brands_missing_typography ?? 0) !== 1 ? 's' : ''}
                        </li>
                        <li>
                            • Missing assets (under 10):{' '}
                            <span className="tabular-nums text-white/85">{s.brands_missing_assets ?? 0}</span> brand
                            {(s.brands_missing_assets ?? 0) !== 1 ? 's' : ''}
                        </li>
                    </ul>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={handleFixNext}
                    className="inline-flex items-center gap-2 rounded-lg bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/15 hover:bg-white/15"
                    style={{ boxShadow: `0 0 20px ${brandColor}22` }}
                >
                    Fix next brand
                    <ArrowTopRightOnSquareIcon className="h-4 w-4 opacity-70" />
                </button>
                {lowestRow && (
                    <span className="text-xs text-white/40">
                        Lowest: {lowestRow.brand?.name} ({lowestRow.brand?.readiness?.readiness_score ?? 0}/5)
                    </span>
                )}
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {brandsReadiness.map((row, i) => {
                    const b = row.brand
                    const r = b.readiness || {}
                    const score = r.readiness_score ?? 0
                    const tasks = Array.isArray(r.readiness_tasks) ? r.readiness_tasks : []
                    const actions = b.actions || {}
                    const tooltip = r.readiness_tooltip || ''
                    const refAlert = r.reference_alert
                    const cardKey = `${row.tenant_id}-${b.id}`
                    const expanded = expandedKey === cardKey

                    return (
                        <motion.div
                            key={cardKey}
                            id={`readiness-card-${cardKey}`}
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.02 * i, duration: 0.25 }}
                            className="rounded-xl bg-gradient-to-br from-white/[0.07] to-white/[0.02] px-4 py-3 ring-1 ring-white/10 backdrop-blur-sm"
                            style={{ boxShadow: '0 0 0 1px rgba(255,255,255,0.06)' }}
                        >
                            <p className="text-[10px] font-medium uppercase tracking-wider text-white/35">{row.tenant_name}</p>
                            <p className="mt-1 truncate text-sm font-semibold text-white">{b.name}</p>
                            <div className="mt-2 flex items-center gap-2">
                                <ReadinessScoreDots
                                    score={score}
                                    title={tooltip}
                                    pulseKey={readinessAnimateKey * 1000 + fixNextPulse}
                                />
                                <span className="text-xs tabular-nums text-white/70" title={tooltip}>
                                    {score}/5
                                </span>
                            </div>

                            {refAlert && refAlert.current < refAlert.min && (
                                <p className="mt-2 text-xs text-amber-200/90">
                                    ⚠️ Weak reference set ({refAlert.current}/{refAlert.min})
                                </p>
                            )}

                            {tasks.length > 0 && (
                                <ul className="mt-2 space-y-1 text-xs text-white/75">
                                    {tasks.slice(0, 3).map((t, ti) => (
                                        <li key={ti}>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    agencyNavigateToBrandPath(
                                                        row.tenant_id,
                                                        b.id,
                                                        resolveReadinessTaskPath(t.action, actions)
                                                    )
                                                }
                                                className="flex w-full items-start gap-1.5 rounded-md py-0.5 text-left text-white/80 hover:bg-white/[0.06] hover:text-white"
                                            >
                                                <span className="text-white/50">•</span>
                                                <span className="flex-1">{t.label}</span>
                                                <span className="shrink-0 text-white/40" title={t.effort}>
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
                                            row.tenant_id,
                                            b.id,
                                            resolveReadinessTaskPath('references_promote', actions)
                                        )
                                    }
                                    className="mt-2 inline-flex w-full items-center justify-center gap-1 rounded-md bg-amber-500/15 px-2 py-1.5 text-xs font-medium text-amber-100 ring-1 ring-amber-500/30 hover:bg-amber-500/25"
                                >
                                    Promote assets
                                    <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-80" />
                                </button>
                            )}

                            <div className="mt-2 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setExpandedKey(expanded ? null : cardKey)}
                                    className="inline-flex flex-1 items-center justify-center gap-1 rounded-md bg-white/5 px-2 py-1.5 text-xs font-medium text-white/80 ring-1 ring-white/10 hover:bg-white/10"
                                    aria-expanded={expanded}
                                >
                                    <ChevronDownIcon className={`h-3.5 w-3.5 transition ${expanded ? 'rotate-180' : ''}`} />
                                    {expanded ? 'Hide checklist' : 'Checklist'}
                                </button>
                            </div>

                            {expanded && (
                                <div className="mt-2 border-t border-white/10 pt-2">
                                    <AgencyReadinessChecklist
                                        tenantId={row.tenant_id}
                                        brand={b}
                                        readiness={r}
                                        actions={actions}
                                        theme="dark"
                                    />
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                agencyNavigateToBrandPath(row.tenant_id, b.id, actions.guidelines_builder_path)
                                            }
                                            className="inline-flex items-center gap-1 rounded-md bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/15 hover:bg-white/15"
                                        >
                                            Guidelines
                                            <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => agencyNavigateToBrandPath(row.tenant_id, b.id, actions.assets_path)}
                                            className="inline-flex items-center gap-1 rounded-md bg-white/10 px-2.5 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/15 hover:bg-white/15"
                                        >
                                            Assets
                                            <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                agencyNavigateToBrandPath(
                                                    row.tenant_id,
                                                    b.id,
                                                    actions.reference_materials_path || actions.assets_path
                                                )
                                            }
                                            className="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/20 hover:bg-white/10"
                                            style={{ boxShadow: `0 0 12px ${brandColor}22` }}
                                        >
                                            References
                                            <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                                        </button>
                                    </div>
                                </div>
                            )}
                        </motion.div>
                    )
                })}
            </div>
        </div>
    )
}

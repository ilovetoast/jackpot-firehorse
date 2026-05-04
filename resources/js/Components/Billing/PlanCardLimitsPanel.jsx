import { useEffect, useState } from 'react'
import {
    RECOGNIZED_PLAN_LIMIT_REASONS,
    buildConfigurablePlanLimitRows,
} from '../../utils/planLimitEligibility'

function cn(...parts) {
    return parts.filter(Boolean).join(' ')
}

/**
 * Collapsible per-plan limits from config (plan.limits + versioning).
 *
 * @param {object} props
 * @param {object} props.plan — Billing page plan object (includes limits, max_versions_per_asset)
 * @param {boolean} props.autoExpand — e.g. user landed with ?reason=
 * @param {string} props.reason — query param reason (e.g. max_upload_size)
 * @param {string} props.queryCurrentPlan — query param current_plan key
 * @param {string|null} props.firstSolverPlanId — plan id that solves the limit for contextual styling
 */
export default function PlanCardLimitsPanel({
    plan,
    autoExpand = false,
    reason = '',
    queryCurrentPlan = '',
    firstSolverPlanId = null,
}) {
    const recognized = Boolean(reason && RECOGNIZED_PLAN_LIMIT_REASONS.has(reason))
    const [open, setOpen] = useState(Boolean(autoExpand && recognized))

    useEffect(() => {
        if (autoExpand && recognized) {
            setOpen(true)
        }
    }, [autoExpand, recognized])

    const rows = buildConfigurablePlanLimitRows(plan)

    const rowMeta = (row) => {
        if (!recognized || reason !== 'max_upload_size' || row.key !== 'max_upload_size_mb') {
            return { mode: null }
        }
        const isCurrentRow = plan.id === queryCurrentPlan
        const isSolverRow = firstSolverPlanId && plan.id === firstSolverPlanId
        if (isCurrentRow) {
            return { mode: 'hit' }
        }
        if (isSolverRow) {
            return { mode: 'solve' }
        }
        return { mode: null }
    }

    return (
        <div className="mb-5 border border-gray-200 rounded-lg bg-white/80">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-semibold text-gray-700 hover:bg-gray-50 rounded-lg"
                aria-expanded={open}
            >
                <span>Plan limits</span>
                <span className="text-gray-400">{open ? '−' : '+'}</span>
            </button>
            {open ? (
                <ul className="border-t border-gray-100 px-3 py-2 space-y-1.5">
                    {rows.map((row) => {
                        const meta = rowMeta(row)
                        return (
                            <li
                                key={row.key}
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-2 rounded-md px-2 py-1.5 text-xs',
                                    meta.mode === 'hit' && 'border border-amber-200 bg-amber-50/90 text-amber-950',
                                    meta.mode === 'solve' && 'border border-emerald-200 bg-emerald-50/90 text-emerald-950',
                                    !meta.mode && 'text-gray-700',
                                )}
                            >
                                <span className="font-medium text-gray-800">
                                    {row.label}: <span className="font-normal text-gray-600">{row.valueLabel}</span>
                                </span>
                                {meta.mode === 'hit' ? (
                                    <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">
                                        Limit reached
                                    </span>
                                ) : null}
                                {meta.mode === 'solve' ? (
                                    <span className="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">
                                        Solves this
                                    </span>
                                ) : null}
                            </li>
                        )
                    })}
                </ul>
            ) : null}
        </div>
    )
}

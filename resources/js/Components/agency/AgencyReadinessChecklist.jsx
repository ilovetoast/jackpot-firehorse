import { ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'
import { agencyNavigateToBrandPath } from '../../utils/agencyBrandNavigation'
import { resolveReadinessTaskPath } from '../../utils/readinessTasks'

const LABELS = {
    has_identity_basics: 'Logo & colors',
    has_typography: 'Typography',
    has_sufficient_assets: 'Assets (10+)',
    has_sufficient_references: 'References (tier 2–3)',
    has_photography_guidelines: 'Photography',
}

/**
 * Single-brand checklist with action-first buttons for gaps.
 */
export default function AgencyReadinessChecklist({
    tenantId,
    brand,
    readiness,
    actions,
    theme = 'dark',
}) {
    const isDark = theme === 'dark'
    const crit = readiness?.criteria || {}
    const counts = readiness?.counts || {}

    const go = (path) => agencyNavigateToBrandPath(tenantId, brand.id, path)

    const criterionToAction = {
        has_identity_basics: 'guidelines_identity',
        has_typography: 'guidelines_typography',
        has_photography_guidelines: 'guidelines_photography',
        has_sufficient_assets: 'assets',
        has_sufficient_references: 'references',
    }

    const actionFor = (key) => {
        if (!actions) return null
        const action = criterionToAction[key]
        if (!action) return null
        const path = resolveReadinessTaskPath(action, actions)
        const label =
            key === 'has_sufficient_assets'
                ? 'Assets'
                : key === 'has_sufficient_references'
                  ? 'References'
                  : 'Guidelines'
        return { label, path }
    }

    const order = [
        'has_identity_basics',
        'has_typography',
        'has_sufficient_assets',
        'has_sufficient_references',
        'has_photography_guidelines',
    ]

    const textMuted = isDark ? 'text-white/45' : 'text-gray-500'
    const textOk = isDark ? 'text-emerald-300/90' : 'text-emerald-700'
    const textBad = isDark ? 'text-amber-200/90' : 'text-amber-800'
    const btnClass = isDark
        ? 'inline-flex items-center gap-1 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-white/90 ring-1 ring-white/15 hover:bg-white/15'
        : 'inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-800 ring-1 ring-indigo-200 hover:bg-indigo-100'

    return (
        <ul className="mt-2 space-y-1.5 border-t border-white/10 pt-3 dark:border-white/10">
            {order.map((key) => {
                const ok = Boolean(crit[key])
                const hint =
                    key === 'has_sufficient_assets'
                        ? `${counts.assets ?? 0}/10 assets`
                        : key === 'has_sufficient_references'
                          ? `${counts.tier23_references ?? 0}/3 refs`
                          : null
                const act = !ok ? actionFor(key) : null
                return (
                    <li key={key} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className={isDark ? 'text-white/80' : 'text-gray-800'}>
                            <span className={ok ? textOk : textBad}>{ok ? '✔' : '✖'}</span>{' '}
                            {LABELS[key] || key}
                            {hint && <span className={`ml-1 text-xs ${textMuted}`}>({hint})</span>}
                        </span>
                        {!ok && act && (
                            <button type="button" onClick={() => go(act.path)} className={btnClass}>
                                {act.label}
                                <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5 opacity-70" />
                            </button>
                        )}
                    </li>
                )
            })}
        </ul>
    )
}

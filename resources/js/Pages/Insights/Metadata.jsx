import { usePage, router, Link } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import InsightsLayout from '../../layouts/InsightsLayout'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    SparklesIcon,
    QuestionMarkCircleIcon,
    ClockIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline'

const DATE_PRESETS = [
    { id: '7d', label: 'Last 7 days', days: 7 },
    { id: '30d', label: 'Last 30 days', days: 30 },
    { id: '90d', label: 'Last 90 days', days: 90 },
    { id: 'all', label: 'All time', days: null },
]

function toYMD(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function getPresetDates(preset) {
    if (!preset || preset.days === null) return { start_date: '', end_date: '' }
    const end = new Date()
    const start = new Date()
    start.setDate(start.getDate() - preset.days)
    return { start_date: toYMD(start), end_date: toYMD(end) }
}

function getActivePresetId(startDate, endDate) {
    if (!startDate || !endDate) return 'all'
    const start = new Date(startDate + 'T12:00:00')
    const end = new Date(endDate + 'T12:00:00')
    const days = Math.round((end - start) / (24 * 60 * 60 * 1000))
    if (endDate === toYMD(new Date()) && [7, 30, 90].includes(days)) {
        return days === 7 ? '7d' : days === 30 ? '30d' : '90d'
    }
    return null // custom range
}

/** Workbench: product violet fills — same Jackpot accent as nav/filters, not brand lime or traffic-light reds. */
function coverageBarFillClass(percentage) {
    const n = Math.min(100, Math.max(0, Number(percentage) || 0))
    if (n >= 85) return 'bg-violet-500'
    if (n >= 60) return 'bg-violet-600'
    if (n >= 40) return 'bg-violet-600/85'
    if (n >= 20) return 'bg-violet-600/60'
    return 'bg-violet-600/45'
}

export default function MetadataAnalytics({ analytics, filters, is_admin }) {
    const { auth, tenant } = usePage().props

    const [localFilters, setLocalFilters] = useState({
        category_id: filters?.category_id || '',
        start_date: filters?.start_date || '',
        end_date: filters?.end_date || '',
        include_internal: filters?.include_internal || false,
    })

    useEffect(() => {
        setLocalFilters({
            category_id: filters?.category_id || '',
            start_date: filters?.start_date || '',
            end_date: filters?.end_date || '',
            include_internal: filters?.include_internal || false,
        })
    }, [filters?.start_date, filters?.end_date, filters?.category_id, filters?.include_internal])

    const activePresetId = getActivePresetId(localFilters.start_date, localFilters.end_date)

    const handlePresetClick = (preset) => {
        const { start_date, end_date } = getPresetDates(preset)
        const newFilters = { ...localFilters, start_date, end_date }
        setLocalFilters(newFilters)
        router.get('/app/insights/metadata', newFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleFilterChange = (key, value) => {
        const newFilters = { ...localFilters, [key]: value }
        setLocalFilters(newFilters)
        router.get('/app/insights/metadata', newFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const coverage = analytics?.coverage || {}
    const fieldCoverageRaw = coverage.field_coverage || []
    const typeFamilyFields = fieldCoverageRaw.filter((f) => !!f.is_type_family)
    const otherCoverageFields = fieldCoverageRaw.filter((f) => !f.is_type_family)
    /** When a category filter is applied, analytics returns a single “Type” row — keep the flat grid. */
    const groupTypeFamilySection = typeFamilyFields.length > 1
    const aiEffectiveness = analytics?.ai_effectiveness || {}
    const freshness = analytics?.freshness || {}
    const rightsRisk = analytics?.rights_risk || {}
    const governanceGaps = analytics?.governance_gaps || {}

    return (
        <InsightsLayout title="Metadata" activeSection="metadata">
            <div className="space-y-8">
                {/* Filters — compact workbench toolbar (violet = active, not brand primary) */}
                <div className="rounded-xl border border-slate-200/90 bg-white px-3 py-2.5 shadow-sm">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div className="min-w-0 flex-1">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Time range</p>
                            <div
                                className="mt-1.5 inline-flex max-w-full flex-wrap gap-0.5 rounded-lg border border-slate-200 bg-slate-50 p-0.5"
                                role="group"
                                aria-label="Time range"
                            >
                                {DATE_PRESETS.map((preset) => {
                                    const isActive = activePresetId === preset.id
                                    return (
                                        <button
                                            key={preset.id}
                                            type="button"
                                            onClick={() => handlePresetClick(preset)}
                                            className={`whitespace-nowrap rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors sm:text-sm ${
                                                isActive
                                                    ? 'bg-violet-600 text-white shadow-sm'
                                                    : 'text-slate-600 hover:bg-white/90 hover:text-slate-900'
                                            }`}
                                        >
                                            {preset.label}
                                        </button>
                                    )
                                })}
                            </div>
                        </div>
                        {is_admin ? (
                            <label className="flex cursor-pointer items-center gap-2 border-slate-200 sm:border-l sm:pl-4">
                                <input
                                    type="checkbox"
                                    checked={localFilters.include_internal}
                                    onChange={(e) => handleFilterChange('include_internal', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                                />
                                <span className="text-sm text-slate-700">Include internal fields</span>
                            </label>
                        ) : null}
                    </div>
                </div>

                {/* Coverage */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-slate-900 mb-1 flex items-center">
                        <CheckCircleIcon className="h-5 w-5 mr-2 text-slate-400" />
                        Metadata coverage
                    </h3>
                    <p className="mb-4 text-sm text-slate-500">Per-field fill rates for the selected time range.</p>
                    <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm sm:p-5">
                        {fieldCoverageRaw.length > 0 ? (
                            <div className="space-y-5">
                                {groupTypeFamilySection ? (
                                    <>
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {otherCoverageFields.map((field) => (
                                                <CoverageFieldCard
                                                    key={field.field_id}
                                                    field={field}
                                                    totalAssets={coverage.total_assets}
                                                />
                                            ))}
                                        </div>
                                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-slate-50/50">
                                            <div className="border-b border-slate-200 bg-slate-50/80 px-3 py-2.5 sm:px-4">
                                                <h4 className="text-sm font-semibold text-slate-900">Type fields</h4>
                                                <p className="mt-0.5 text-xs text-slate-500">
                                                    Each category has its own type field. Coverage is per field.
                                                </p>
                                            </div>
                                            <div className="overflow-x-auto">
                                                <table className="min-w-full text-left text-sm">
                                                    <thead>
                                                        <tr className="border-b border-slate-200 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                            <th className="px-3 py-2 pl-4 sm:px-4">Field</th>
                                                            <th className="px-2 py-2 text-right">%</th>
                                                            <th className="min-w-[8rem] px-3 py-2 pr-4 sm:pr-4">Coverage</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-slate-100">
                                                        {typeFamilyFields.map((field) => (
                                                            <TypeCoverageTableRow
                                                                key={field.field_id}
                                                                field={field}
                                                                totalAssets={coverage.total_assets}
                                                            />
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {fieldCoverageRaw.map((field) => (
                                            <CoverageFieldCard
                                                key={field.field_id}
                                                field={field}
                                                totalAssets={coverage.total_assets}
                                            />
                                        ))}
                                    </div>
                                )}
                                {coverage.lowest_coverage_fields && coverage.lowest_coverage_fields.length > 0 && (
                                    <div className="mt-6 pt-6 border-t border-gray-200">
                                        <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                            Fields with Lowest Coverage
                                        </h4>
                                        <div className="space-y-2">
                                            {coverage.lowest_coverage_fields.slice(0, 5).map((field, idx) => (
                                                <div
                                                    key={idx}
                                                    className="flex items-center justify-between text-sm"
                                                >
                                                    <span className="text-gray-700">{field.field_label}</span>
                                                    <span className="text-gray-500">
                                                        {field.coverage_percentage}%
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-gray-500">No coverage data available</p>
                        )}
                        <div className="mt-6 flex flex-wrap gap-4 border-t border-slate-200 pt-5">
                            <Link
                                href={route('manage.categories', { filter: 'low_coverage' })}
                                className="inline-flex items-center text-sm font-medium text-violet-600 hover:text-violet-500"
                            >
                                View low coverage fields
                            </Link>
                            <Link
                                href={route('manage.tags', { filter: 'missing' })}
                                className="inline-flex items-center text-sm font-medium text-violet-600 hover:text-violet-500"
                            >
                                Fix missing tags
                            </Link>
                        </div>
                    </div>
                </div>

                {/* AI Effectiveness */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <SparklesIcon className="h-5 w-5 mr-2 text-violet-500" />
                        AI suggestion effectiveness
                    </h3>
                    <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm sm:p-5">
                        {aiEffectiveness.total_suggestions > 0 ? (
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <MetricCard
                                    label="Total Suggestions"
                                    value={aiEffectiveness.total_suggestions?.toLocaleString() || '0'}
                                    tooltip="Total number of AI-generated metadata suggestions"
                                />
                                <MetricCard
                                    label="Acceptance Rate"
                                    value={`${aiEffectiveness.acceptance_rate?.toFixed(1) || '0'}%`}
                                    tooltip="Percentage of AI suggestions that were approved"
                                />
                                <MetricCard
                                    label="Rejection Rate"
                                    value={`${aiEffectiveness.rejection_rate?.toFixed(1) || '0'}%`}
                                    tooltip="Percentage of AI suggestions that were rejected"
                                />
                                <MetricCard
                                    label="Avg Confidence (Approved)"
                                    value={aiEffectiveness.avg_confidence_approved
                                        ? aiEffectiveness.avg_confidence_approved.toFixed(2)
                                        : 'N/A'}
                                    tooltip="Average confidence score of approved AI suggestions"
                                />
                            </div>
                        ) : (
                            <p className="text-sm text-gray-500">
                                No AI suggestions data available
                            </p>
                        )}
                    </div>
                </div>

                {/* Rights & Risk */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <ExclamationTriangleIcon className="h-5 w-5 mr-2 text-amber-500" />
                        Rights &amp; risk indicators
                    </h3>
                    <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm sm:p-5">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <MetricCard
                                label="Expired Assets"
                                value={rightsRisk.expired_count?.toLocaleString() || '0'}
                                tooltip="Assets with expired usage rights"
                                variant="danger"
                            />
                            <MetricCard
                                label="Expiring in 30 Days"
                                value={rightsRisk.expiring_30_days?.toLocaleString() || '0'}
                                tooltip="Assets with usage rights expiring within 30 days"
                                variant="warning"
                            />
                            <MetricCard
                                label="Expiring in 60 Days"
                                value={rightsRisk.expiring_60_days?.toLocaleString() || '0'}
                                tooltip="Assets with usage rights expiring within 60 days"
                            />
                            <MetricCard
                                label="Expiring in 90 Days"
                                value={rightsRisk.expiring_90_days?.toLocaleString() || '0'}
                                tooltip="Assets with usage rights expiring within 90 days"
                            />
                        </div>
                        {rightsRisk.usage_rights_distribution &&
                            rightsRisk.usage_rights_distribution.length > 0 && (
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                        Usage Rights Distribution
                                    </h4>
                                    <div className="space-y-2">
                                        {rightsRisk.usage_rights_distribution.map((item, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-center justify-between text-sm"
                                            >
                                                <span className="text-gray-700">{item.value}</span>
                                                <span className="text-gray-500">
                                                    {item.count} assets
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                    </div>
                </div>

                {/* Freshness */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                        <ClockIcon className="h-5 w-5 mr-2 text-slate-400" />
                        Metadata freshness
                    </h3>
                    <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm sm:p-5">
                        <div className="mb-4">
                            <MetricCard
                                label="Assets with Stale Metadata"
                                value={freshness.stale_assets_count?.toLocaleString() || '0'}
                                tooltip={`Assets with metadata not updated in the last ${freshness.stale_threshold_days || 90} days`}
                            />
                        </div>
                        {freshness.last_updated_by_field &&
                            freshness.last_updated_by_field.length > 0 && (
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <h4 className="text-sm font-semibold text-gray-900 mb-3">
                                        Last Updated by Field
                                    </h4>
                                    <div className="space-y-2">
                                        {freshness.last_updated_by_field
                                            .sort((a, b) => (b.days_ago || 0) - (a.days_ago || 0))
                                            .slice(0, 10)
                                            .map((field, idx) => (
                                                <div
                                                    key={idx}
                                                    className="flex items-center justify-between text-sm"
                                                >
                                                    <span className="text-gray-700">
                                                        {field.field_label}
                                                    </span>
                                                    <span className="text-gray-500">
                                                        {field.days_ago !== null
                                                            ? `${field.days_ago} days ago`
                                                            : 'Never'}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            )}
                    </div>
                </div>

                {/* Governance Gaps */}
                {is_admin && (
                    <div className="mb-8">
                        <h3 className="text-lg font-semibold text-slate-900 mb-4 flex items-center">
                            <ShieldCheckIcon className="h-5 w-5 mr-2 text-slate-400" />
                            Governance &amp; permission gaps
                        </h3>
                        <div className="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm sm:p-5">
                            <p className="text-sm text-gray-500">
                                Governance gap analysis requires permission logging (future enhancement)
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </InsightsLayout>
    )
}

function TypeCoverageTableRow({ field, totalAssets }) {
    const pct = field.coverage_percentage
    const fill = coverageBarFillClass(pct)
    return (
        <tr>
            <td className="max-w-[12rem] px-3 py-2.5 pl-4 align-middle text-slate-900 sm:max-w-none sm:px-4">
                {field.field_label}
            </td>
            <td className="w-12 whitespace-nowrap px-2 py-2.5 text-right tabular-nums text-slate-600">{pct}%</td>
            <td className="px-3 py-2 pr-4 align-middle">
                <div className="h-1.5 w-full min-w-[6rem] overflow-hidden rounded-full bg-slate-200/90">
                    <div className={`h-1.5 rounded-full transition-all ${fill}`} style={{ width: `${pct}%` }} />
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                    {field.assets_with_value} of {totalAssets} assets
                </p>
            </td>
        </tr>
    )
}

function CoverageFieldCard({ field, totalAssets }) {
    const pct = field.coverage_percentage
    const fill = coverageBarFillClass(pct)
    return (
        <div className="flex flex-col rounded-lg border border-slate-200/90 bg-white p-3 shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <span className="text-sm font-medium leading-snug text-slate-900">{field.field_label}</span>
                <span className="shrink-0 text-sm font-semibold tabular-nums text-slate-700">{pct}%</span>
            </div>
            <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-200/90">
                <div className={`h-1.5 rounded-full transition-all ${fill}`} style={{ width: `${pct}%` }} />
            </div>
            <p className="mt-1.5 text-[11px] leading-relaxed text-slate-500">
                {field.assets_with_value} of {totalAssets} assets
            </p>
        </div>
    )
}

function MetricCard({ label, value, tooltip, variant = 'default' }) {
    const variantClasses = {
        default: 'text-slate-900',
        danger: 'text-red-600',
        warning: 'text-amber-600',
    }

    return (
        <div className="rounded-lg border border-slate-200/90 bg-slate-50/30 p-3 sm:p-4">
            <div className="mb-1 flex items-center justify-between">
                <span className="text-sm font-medium text-slate-500">{label}</span>
                {tooltip && (
                    <span className="inline-flex cursor-help text-violet-500/90" title={tooltip}>
                        <QuestionMarkCircleIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                    </span>
                )}
            </div>
            <div className={`text-2xl font-semibold ${variantClasses[variant]}`}>
                {value}
            </div>
        </div>
    )
}

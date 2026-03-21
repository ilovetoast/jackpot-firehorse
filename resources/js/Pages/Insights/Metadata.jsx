import { usePage, router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import InsightsLayout from '../../layouts/InsightsLayout'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    InformationCircleIcon,
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
                {/* Filters */}
                <div className="mb-6 bg-white rounded-lg shadow p-4 border border-gray-200">
                    <div className="flex flex-wrap items-center gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Time range
                            </label>
                            <div className="flex flex-wrap gap-2">
                                {DATE_PRESETS.map((preset) => {
                                    const isActive = activePresetId === preset.id
                                    return (
                                        <button
                                            key={preset.id}
                                            type="button"
                                            onClick={() => handlePresetClick(preset)}
                                            className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                                                isActive
                                                    ? 'bg-primary text-white'
                                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                            }`}
                                        >
                                            {preset.label}
                                        </button>
                                    )
                                })}
                            </div>
                        </div>
                        {is_admin && (
                            <div className="flex items-end">
                                <label className="flex items-center">
                                    <input
                                        type="checkbox"
                                        checked={localFilters.include_internal}
                                        onChange={(e) => handleFilterChange('include_internal', e.target.checked)}
                                        className="rounded border-gray-300 text-primary focus:ring-primary"
                                    />
                                    <span className="ml-2 text-sm text-gray-700">
                                        Include internal fields
                                    </span>
                                </label>
                            </div>
                        )}
                    </div>
                </div>

                {/* Coverage */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <CheckCircleIcon className="h-5 w-5 mr-2 text-gray-400" />
                        Metadata Coverage
                    </h3>
                    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                        {fieldCoverageRaw.length > 0 ? (
                            <div className="space-y-4">
                                {groupTypeFamilySection ? (
                                    <>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                            {otherCoverageFields.map((field) => (
                                                <CoverageFieldCard
                                                    key={field.field_id}
                                                    field={field}
                                                    totalAssets={coverage.total_assets}
                                                />
                                            ))}
                                        </div>
                                        <div className="rounded-lg border border-gray-200 bg-gray-50/90 p-4">
                                            <div className="mb-3">
                                                <h4 className="text-sm font-semibold text-gray-900">Type</h4>
                                                <p className="mt-0.5 text-xs text-gray-500">
                                                    Each library category has its own type field (for example Photo Type,
                                                    Logo Type). Coverage is per field, not a single shared value.
                                                </p>
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                                {typeFamilyFields.map((field) => (
                                                    <CoverageFieldCard
                                                        key={field.field_id}
                                                        field={field}
                                                        totalAssets={coverage.total_assets}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
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
                    </div>
                </div>

                {/* AI Effectiveness */}
                <div className="mb-8">
                    <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <InformationCircleIcon className="h-5 w-5 mr-2 text-gray-400" />
                        AI Suggestion Effectiveness
                    </h3>
                    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
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
                    <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <ExclamationTriangleIcon className="h-5 w-5 mr-2 text-yellow-500" />
                        Rights & Risk Indicators
                    </h3>
                    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
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
                    <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <ClockIcon className="h-5 w-5 mr-2 text-gray-400" />
                        Metadata Freshness
                    </h3>
                    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
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
                        <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <ShieldCheckIcon className="h-5 w-5 mr-2 text-gray-400" />
                            Governance & Permission Gaps
                        </h3>
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
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

function CoverageFieldCard({ field, totalAssets }) {
    return (
        <div className="border border-gray-200 rounded-lg bg-white p-4">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-sm font-medium text-gray-900">{field.field_label}</span>
                <span className="text-sm text-gray-500">{field.coverage_percentage}%</span>
            </div>
            <div className="h-2 w-full rounded-full bg-gray-200">
                <div
                    className="bg-primary h-2 rounded-full transition-all"
                    style={{ width: `${field.coverage_percentage}%` }}
                />
            </div>
            <p className="mt-1 text-xs text-gray-500">
                {field.assets_with_value} of {totalAssets} assets
            </p>
        </div>
    )
}

function MetricCard({ label, value, tooltip, variant = 'default' }) {
    const variantClasses = {
        default: 'text-gray-900',
        danger: 'text-red-600',
        warning: 'text-yellow-600',
    }

    return (
        <div className="border border-gray-200 rounded-lg p-4">
            <div className="flex items-center justify-between mb-1">
                <span className="text-sm font-medium text-gray-500">{label}</span>
                {tooltip && (
                    <span
                        className="text-xs text-gray-400 cursor-help"
                        title={tooltip}
                    >
                        ℹ️
                    </span>
                )}
            </div>
            <div className={`text-2xl font-semibold ${variantClasses[variant]}`}>
                {value}
            </div>
        </div>
    )
}

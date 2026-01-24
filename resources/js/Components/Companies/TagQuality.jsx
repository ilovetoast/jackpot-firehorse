/**
 * TagQuality Component
 * 
 * Phase J.2.6: Tag Quality & Trust Metrics UI
 * 
 * Features:
 * - Overall AI tag acceptance rate
 * - Auto-applied tag retention % 
 * - Most accepted/dismissed tags
 * - Confidence vs acceptance analysis
 * - Trust signals for problematic patterns
 * - CSV export functionality
 * - Read-only analytics only
 */

import { useState, useEffect } from 'react'
import { 
    ChartBarIcon, 
    ArrowDownTrayIcon,
    ExclamationTriangleIcon, 
    InformationCircleIcon,
    SparklesIcon,
    CheckCircleIcon,
    XCircleIcon
} from '@heroicons/react/24/outline'

export default function TagQuality({ 
    canView = false, 
    className = "" 
}) {
    const [metrics, setMetrics] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [timeRange, setTimeRange] = useState(new Date().toISOString().slice(0, 7)) // YYYY-MM
    const [exporting, setExporting] = useState(false)

    useEffect(() => {
        if (canView) {
            loadMetrics()
        } else {
            setLoading(false)
        }
    }, [canView, timeRange])

    const loadMetrics = async () => {
        try {
            setLoading(true)
            setError(null)

            const response = await window.axios.get('/app/api/companies/ai-tag-metrics', {
                params: { time_range: timeRange }
            })
            
            if (response.data) {
                setMetrics(response.data)
            } else {
                throw new Error('Invalid response format')
            }
        } catch (err) {
            console.error('[TagQuality] Failed to load metrics:', err)
            setError({
                message: err.response?.data?.error || err.message || 'Failed to load tag quality metrics',
                type: err.response?.status === 403 ? 'permission' : 'error',
                canRetry: err.response?.status !== 403
            })
        } finally {
            setLoading(false)
        }
    }

    const exportCsv = async () => {
        try {
            setExporting(true)

            const response = await window.axios.get('/app/api/companies/ai-tag-metrics/export', {
                params: { time_range: timeRange },
                responseType: 'blob'
            })

            // Create download link
            const url = window.URL.createObjectURL(new Blob([response.data]))
            const link = document.createElement('a')
            link.href = url
            link.setAttribute('download', `tag-quality-metrics-${timeRange}.csv`)
            document.body.appendChild(link)
            link.click()
            link.remove()
            window.URL.revokeObjectURL(url)

        } catch (err) {
            console.error('[TagQuality] Export failed:', err)
            alert('Failed to export metrics. Please try again.')
        } finally {
            setExporting(false)
        }
    }

    const retry = () => {
        loadMetrics()
    }

    // Permission denied state
    if (!canView) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-gray-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-gray-600">
                                You don't have permission to view tag quality metrics. Contact a company admin for access.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    // Loading state
    if (loading) {
        return (
            <div className={`${className}`}>
                <div className="flex items-center justify-center py-8">
                    <div className="flex items-center space-x-3">
                        <div className="animate-spin rounded-full h-5 w-5 border-2 border-gray-300 border-t-indigo-600" />
                        <div className="text-sm text-gray-500">Loading tag quality metrics...</div>
                    </div>
                </div>
            </div>
        )
    }

    // Error state
    if (error) {
        const isPermissionError = error.type === 'permission'
        
        return (
            <div className={`${className}`}>
                <div className={`rounded-md p-4 ${isPermissionError ? 'bg-yellow-50' : 'bg-red-50'}`}>
                    <div className="flex">
                        <div className="flex-shrink-0">
                            {isPermissionError ? (
                                <InformationCircleIcon className="h-5 w-5 text-yellow-400" />
                            ) : (
                                <ExclamationTriangleIcon className="h-5 w-5 text-red-400" />
                            )}
                        </div>
                        <div className="ml-3 flex-1">
                            <h3 className={`text-sm font-medium ${isPermissionError ? 'text-yellow-800' : 'text-red-800'}`}>
                                Error Loading Tag Quality Metrics
                            </h3>
                            <div className={`mt-2 text-sm ${isPermissionError ? 'text-yellow-700' : 'text-red-700'}`}>
                                <p>{error.message}</p>
                            </div>
                            {error.canRetry && (
                                <div className="mt-4">
                                    <button
                                        type="button"
                                        onClick={retry}
                                        className={`inline-flex items-center rounded-md px-2 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                            isPermissionError
                                                ? 'bg-yellow-50 text-yellow-800 hover:bg-yellow-100 focus:ring-yellow-600 focus:ring-offset-yellow-50'
                                                : 'bg-red-50 text-red-800 hover:bg-red-100 focus:ring-red-600 focus:ring-offset-red-50'
                                        }`}
                                    >
                                        Retry
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    if (!metrics) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-gray-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-gray-600">
                                No tag quality metrics available.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    // AI disabled state
    if (!metrics.summary?.ai_enabled) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-gray-100 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium text-gray-800">
                                AI tagging is disabled
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Enable AI Tagging in the settings above to see quality metrics.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    const summary = metrics.summary
    const hasData = summary.total_candidates > 0
    
    return (
        <div className={`space-y-6 ${className}`}>
            {/* Time Range Selector */}
            <div className="flex items-center justify-between">
                <div className="flex items-center space-x-3">
                    <ChartBarIcon className="h-5 w-5 text-gray-500" />
                    <h3 className="text-lg font-medium text-gray-900">Tag Quality Analysis</h3>
                </div>
                <div className="flex items-center space-x-3">
                    <input
                        type="month"
                        value={timeRange}
                        onChange={(e) => setTimeRange(e.target.value)}
                        className="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                    />
                    {hasData && (
                        <button
                            type="button"
                            onClick={exportCsv}
                            disabled={exporting}
                            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {exporting ? (
                                <div className="animate-spin rounded-full h-4 w-4 border-2 border-gray-300 border-t-gray-900 mr-2" />
                            ) : (
                                <ArrowDownTrayIcon className="h-4 w-4 mr-2" />
                            )}
                            Export CSV
                        </button>
                    )}
                </div>
            </div>

            {/* No Data State */}
            {!hasData && (
                <div className="rounded-md bg-blue-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-blue-400" />
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium text-blue-800">
                                No AI tagging data for {timeRange}
                            </h3>
                            <p className="mt-1 text-sm text-blue-700">
                                Quality metrics will appear here once AI has generated tag suggestions for assets.
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Summary Metrics */}
            {hasData && (
                <>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Acceptance Rate */}
                        <div className="bg-white overflow-hidden shadow rounded-lg">
                            <div className="p-5">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <CheckCircleIcon className="h-6 w-6 text-green-400" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt className="text-sm font-medium text-gray-500 truncate">
                                                Acceptance Rate
                                            </dt>
                                            <dd className="text-lg font-medium text-gray-900">
                                                {(summary.acceptance_rate * 100).toFixed(1)}%
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <div className="text-sm text-gray-500">
                                        {summary.accepted_candidates} of {summary.total_candidates} candidates accepted
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Dismissal Rate */}
                        <div className="bg-white overflow-hidden shadow rounded-lg">
                            <div className="p-5">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <XCircleIcon className="h-6 w-6 text-red-400" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt className="text-sm font-medium text-gray-500 truncate">
                                                Dismissal Rate
                                            </dt>
                                            <dd className="text-lg font-medium text-gray-900">
                                                {(summary.dismissal_rate * 100).toFixed(1)}%
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <div className="text-sm text-gray-500">
                                        {summary.dismissed_candidates} candidates dismissed
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Manual vs AI Ratio */}
                        <div className="bg-white overflow-hidden shadow rounded-lg">
                            <div className="p-5">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <SparklesIcon className="h-6 w-6 text-indigo-400" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt className="text-sm font-medium text-gray-500 truncate">
                                                Manual:AI Ratio
                                            </dt>
                                            <dd className="text-lg font-medium text-gray-900">
                                                {summary.manual_ai_ratio ? `${summary.manual_ai_ratio}:1` : 'N/A'}
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <div className="text-sm text-gray-500">
                                        {summary.manual_tags} manual, {summary.ai_tags} AI tags
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Auto-Applied Tags */}
                        <div className="bg-white overflow-hidden shadow rounded-lg">
                            <div className="p-5">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <div className="h-6 w-6 bg-purple-100 rounded-full flex items-center justify-center">
                                            <div className="h-3 w-3 bg-purple-500 rounded-full" />
                                        </div>
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt className="text-sm font-medium text-gray-500 truncate">
                                                Auto-Applied
                                            </dt>
                                            <dd className="text-lg font-medium text-gray-900">
                                                {summary.auto_applied_tags}
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <div className="text-sm text-gray-500">
                                        {summary.auto_applied_retention_note}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Confidence Analysis */}
                    {summary.avg_confidence_accepted && summary.avg_confidence_dismissed && (
                        <div className="bg-white shadow rounded-lg">
                            <div className="px-4 py-5 sm:p-6">
                                <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                    Confidence Analysis
                                </h3>
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Average Confidence (Accepted)</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-green-600">
                                            {(summary.avg_confidence_accepted * 100).toFixed(1)}%
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Average Confidence (Dismissed)</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-red-600">
                                            {(summary.avg_confidence_dismissed * 100).toFixed(1)}%
                                        </dd>
                                    </div>
                                </div>
                                <div className="mt-4">
                                    <div className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium ${
                                        summary.confidence_correlation 
                                            ? 'bg-green-100 text-green-800' 
                                            : 'bg-yellow-100 text-yellow-800'
                                    }`}>
                                        {summary.confidence_correlation ? 
                                            'Confidence correlates with acceptance' : 
                                            'Confidence does not correlate with acceptance'
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Top Tags */}
                    {metrics.tags?.tags?.length > 0 && (
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            {/* Most Accepted Tags */}
                            <div className="bg-white shadow rounded-lg">
                                <div className="px-4 py-5 sm:p-6">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                        Most Accepted Tags
                                    </h3>
                                    <div className="space-y-3">
                                        {metrics.tags.tags
                                            .filter(tag => tag.accepted > 0)
                                            .sort((a, b) => b.acceptance_rate - a.acceptance_rate)
                                            .slice(0, 5)
                                            .map((tag, index) => (
                                                <div key={tag.tag} className="flex items-center justify-between">
                                                    <div className="flex items-center">
                                                        <span className="text-sm font-medium text-gray-900">
                                                            #{index + 1} {tag.tag}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-center space-x-2">
                                                        <span className="text-sm text-gray-500">
                                                            {tag.accepted}/{tag.total_generated}
                                                        </span>
                                                        <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            {(tag.acceptance_rate * 100).toFixed(1)}%
                                                        </span>
                                                    </div>
                                                </div>
                                            ))
                                        }
                                    </div>
                                </div>
                            </div>

                            {/* Most Dismissed Tags */}
                            <div className="bg-white shadow rounded-lg">
                                <div className="px-4 py-5 sm:p-6">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                        Most Dismissed Tags
                                    </h3>
                                    <div className="space-y-3">
                                        {metrics.tags.tags
                                            .filter(tag => tag.dismissed > 0)
                                            .sort((a, b) => b.dismissal_rate - a.dismissal_rate)
                                            .slice(0, 5)
                                            .map((tag, index) => (
                                                <div key={tag.tag} className="flex items-center justify-between">
                                                    <div className="flex items-center">
                                                        <span className="text-sm font-medium text-gray-900">
                                                            #{index + 1} {tag.tag}
                                                        </span>
                                                        {tag.trust_signals?.length > 0 && (
                                                            <ExclamationTriangleIcon className="h-4 w-4 text-yellow-500 ml-2" title="Has trust signals" />
                                                        )}
                                                    </div>
                                                    <div className="flex items-center space-x-2">
                                                        <span className="text-sm text-gray-500">
                                                            {tag.dismissed}/{tag.total_generated}
                                                        </span>
                                                        <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                            {(tag.dismissal_rate * 100).toFixed(1)}%
                                                        </span>
                                                    </div>
                                                </div>
                                            ))
                                        }
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Trust Signals */}
                    {metrics.trust_signals?.summary?.total_problematic_tags > 0 && (
                        <div className="bg-white shadow rounded-lg">
                            <div className="px-4 py-5 sm:p-6">
                                <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                    Trust Signals
                                </h3>
                                <p className="text-sm text-gray-600 mb-4">
                                    These patterns may indicate areas where AI tagging could be improved. 
                                    <span className="font-medium">This is informational only</span> - no automatic changes will be made.
                                </p>
                                
                                <div className="space-y-4">
                                    {/* High Generation, Low Acceptance */}
                                    {metrics.trust_signals.signals.high_generation_low_acceptance?.length > 0 && (
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-900 mb-2">
                                                High Generation, Low Acceptance ({metrics.trust_signals.signals.high_generation_low_acceptance.length} tags)
                                            </h4>
                                            <div className="bg-yellow-50 rounded-md p-3">
                                                <div className="text-sm text-gray-600">
                                                    Tags generated frequently but rarely accepted by users.
                                                </div>
                                                <div className="mt-2 space-y-1">
                                                    {metrics.trust_signals.signals.high_generation_low_acceptance.slice(0, 3).map(signal => (
                                                        <div key={signal.tag} className="text-xs text-gray-500">
                                                            <span className="font-medium">{signal.tag}</span>: {signal.generated} generated, {(signal.acceptance_rate * 100).toFixed(1)}% accepted
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Zero Acceptance */}
                                    {metrics.trust_signals.signals.zero_acceptance_tags?.length > 0 && (
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-900 mb-2">
                                                Never Accepted ({metrics.trust_signals.signals.zero_acceptance_tags.length} tags)
                                            </h4>
                                            <div className="bg-red-50 rounded-md p-3">
                                                <div className="text-sm text-gray-600">
                                                    Tags that have never been accepted despite multiple generations.
                                                </div>
                                                <div className="mt-2 space-y-1">
                                                    {metrics.trust_signals.signals.zero_acceptance_tags.slice(0, 5).map(signal => (
                                                        <div key={signal.tag} className="text-xs text-gray-500">
                                                            <span className="font-medium">{signal.tag}</span>: {signal.generated} generated, 0% accepted
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Confidence Trust Drops */}
                                    {metrics.trust_signals.signals.confidence_trust_drops?.length > 0 && (
                                        <div>
                                            <h4 className="text-sm font-medium text-gray-900 mb-2">
                                                High Confidence, Low Trust ({metrics.trust_signals.signals.confidence_trust_drops.length} tags)
                                            </h4>
                                            <div className="bg-orange-50 rounded-md p-3">
                                                <div className="text-sm text-gray-600">
                                                    Tags with high AI confidence but low user acceptance.
                                                </div>
                                                <div className="mt-2 space-y-1">
                                                    {metrics.trust_signals.signals.confidence_trust_drops.slice(0, 3).map(signal => (
                                                        <div key={signal.tag} className="text-xs text-gray-500">
                                                            <span className="font-medium">{signal.tag}</span>: {(signal.avg_confidence * 100).toFixed(1)}% confidence, {(signal.acceptance_rate * 100).toFixed(1)}% accepted
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Confidence Bands */}
                    {metrics.confidence?.confidence_bands?.length > 0 && (
                        <div className="bg-white shadow rounded-lg">
                            <div className="px-4 py-5 sm:p-6">
                                <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                    Confidence vs Acceptance
                                </h3>
                                <div className="space-y-3">
                                    {metrics.confidence.confidence_bands.map(band => (
                                        <div key={band.confidence_band} className="flex items-center justify-between py-2">
                                            <div>
                                                <span className="text-sm font-medium text-gray-900">
                                                    {band.confidence_band}
                                                </span>
                                                <span className="text-xs text-gray-500 ml-2">
                                                    ({band.total_candidates} candidates)
                                                </span>
                                            </div>
                                            <div className="flex items-center space-x-4">
                                                <span className="text-sm text-gray-600">
                                                    {band.accepted}/{band.total_candidates} accepted
                                                </span>
                                                <div className="w-24 bg-gray-200 rounded-full h-2">
                                                    <div
                                                        className="bg-indigo-600 h-2 rounded-full"
                                                        style={{ 
                                                            width: `${band.total_candidates > 0 ? (band.acceptance_rate * 100) : 0}%` 
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-sm font-medium text-gray-900 w-12 text-right">
                                                    {(band.acceptance_rate * 100).toFixed(0)}%
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    )
}
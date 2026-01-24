/**
 * AiUsagePanel Component
 * 
 * Phase J.2.5: Resilient AI usage panel for Company Settings
 * 
 * Features:
 * - Graceful error handling (no hard crashes)
 * - Multiple states: loading, success, disabled, empty, error
 * - Retry functionality
 * - Current month usage with caps
 * - Feature breakdown display
 * - Permission-aware rendering
 */

import { useState, useEffect } from 'react'
import { 
    ExclamationTriangleIcon, 
    InformationCircleIcon, 
    ChartBarIcon,
    SparklesIcon,
    ArrowPathIcon
} from '@heroicons/react/24/outline'

export default function AiUsagePanel({ 
    canView = false,
    className = "" 
}) {
    const [usageData, setUsageData] = useState(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (canView) {
            loadUsageData()
        } else {
            setLoading(false)
        }
    }, [canView])

    const loadUsageData = async () => {
        try {
            setLoading(true)
            setError(null)

            const response = await window.axios.get('/app/api/companies/ai-usage')
            
            if (response.data && response.data.status) {
                setUsageData(response.data)
            } else {
                throw new Error('Invalid data structure received')
            }
        } catch (err) {
            console.error('[AiUsagePanel] Failed to load usage data:', err)
            setError({
                message: err.response?.data?.error || err.message || 'Failed to load AI usage data',
                type: err.response?.status === 403 ? 'permission' : 'error',
                canRetry: err.response?.status !== 403
            })
        } finally {
            setLoading(false)
        }
    }

    const retry = () => {
        loadUsageData()
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
                                You don't have permission to view AI usage data. Contact a company admin for access.
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
                        <div className="text-sm text-gray-500">Loading usage data...</div>
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
                                {isPermissionError ? 'Permission Denied' : 'Error Loading AI Usage'}
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
                                        <ArrowPathIcon className="h-4 w-4 mr-1" />
                                        Retry
                                    </button>
                                    <div className="mt-2 text-xs text-gray-500">
                                        If the problem persists, check browser console for details.
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    // No data state (shouldn't happen but handle gracefully)
    if (!usageData) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-gray-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-gray-600">
                                No usage data available.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    // Check if AI is disabled at the company level
    const isAiDisabled = usageData.status && Object.values(usageData.status).every(feature => feature?.is_disabled)

    if (isAiDisabled) {
        return (
            <div className={`${className}`}>
                <div className="rounded-md bg-gray-100 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-gray-400" />
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium text-gray-800">
                                AI is currently disabled for this company
                            </h3>
                            <p className="mt-1 text-sm text-gray-600">
                                Enable AI Tagging in the settings above to see usage data.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    // Success state - render usage data
    const currentMonth = usageData.current_month
    const monthStart = new Date(usageData.month_start).toLocaleDateString()
    const monthEnd = new Date(usageData.month_end).toLocaleDateString()
    const features = ['tagging', 'suggestions']

    return (
        <div className={`space-y-6 ${className}`}>
            {/* Current Month Info */}
            <div className="rounded-md bg-gray-50 p-4">
                <div className="flex items-center">
                    <ChartBarIcon className="h-5 w-5 text-gray-400 mr-2" />
                    <p className="text-sm text-gray-600">
                        <span className="font-medium">Current Period:</span> {currentMonth} 
                        {' '}({monthStart} - {monthEnd})
                    </p>
                </div>
            </div>

            {/* Feature Usage */}
            <div className="space-y-4">
                {features.map((featureKey) => {
                    const feature = usageData.status?.[featureKey]
                    
                    if (!feature) {
                        return (
                            <div key={featureKey} className="rounded-md bg-gray-50 p-4">
                                <p className="text-sm text-gray-500">
                                    <span className="font-medium capitalize">{featureKey}:</span> No data available
                                </p>
                            </div>
                        )
                    }

                    const isUnlimited = feature.is_unlimited
                    const isDisabled = feature.is_disabled
                    const isExceeded = feature.is_exceeded
                    const usage = feature.usage || 0
                    const cap = feature.cap || 0
                    const remaining = feature.remaining
                    const percentage = feature.percentage || 0

                    if (isDisabled) {
                        return (
                            <div key={featureKey} className="rounded-md bg-gray-100 p-4">
                                <div className="flex items-center">
                                    <div className="h-2 w-2 rounded-full bg-gray-400 mr-3" />
                                    <p className="text-sm text-gray-600">
                                        <span className="font-medium capitalize">{featureKey}:</span> Disabled
                                    </p>
                                </div>
                            </div>
                        )
                    }

                    return (
                        <div key={featureKey} className="rounded-md border border-gray-200 p-4">
                            <div className="flex items-center justify-between mb-3">
                                <div className="flex items-center">
                                    <SparklesIcon className="h-5 w-5 text-indigo-500 mr-2" />
                                    <h4 className="text-sm font-medium text-gray-900 capitalize">
                                        {featureKey}
                                    </h4>
                                </div>
                                <div className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                    isExceeded ? 'bg-red-100 text-red-800' : 
                                    percentage > 80 ? 'bg-yellow-100 text-yellow-800' :
                                    'bg-green-100 text-green-800'
                                }`}>
                                    {isExceeded ? 'Limit Exceeded' :
                                     percentage > 80 ? 'Near Limit' : 'Available'}
                                </div>
                            </div>
                            
                            <div className="space-y-2">
                                {/* Usage Numbers */}
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-600">Usage this month:</span>
                                    <span className="font-medium text-gray-900">
                                        {usage.toLocaleString()}
                                        {!isUnlimited && ` / ${cap.toLocaleString()}`}
                                    </span>
                                </div>

                                {/* Progress Bar */}
                                {!isUnlimited && (
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className={`h-2 rounded-full transition-all duration-300 ${
                                                isExceeded ? 'bg-red-500' :
                                                percentage > 80 ? 'bg-yellow-500' : 'bg-green-500'
                                            }`}
                                            style={{ width: `${Math.min(percentage, 100)}%` }}
                                        />
                                    </div>
                                )}

                                {/* Remaining/Percentage */}
                                <div className="flex justify-between text-sm text-gray-500">
                                    {isUnlimited ? (
                                        <span>Unlimited</span>
                                    ) : (
                                        <>
                                            <span>
                                                {remaining !== undefined ? 
                                                    `${Math.max(0, remaining).toLocaleString()} remaining` : 
                                                    `${percentage.toFixed(1)}% used`
                                                }
                                            </span>
                                            <span>{percentage.toFixed(1)}%</span>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    )
                })}
            </div>

            {/* Zero Usage Message */}
            {features.every(key => (usageData.status?.[key]?.usage || 0) === 0) && (
                <div className="rounded-md bg-blue-50 p-4">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <InformationCircleIcon className="h-5 w-5 text-blue-400" />
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-blue-700">
                                No AI usage recorded this month. Usage will appear here once AI features are used.
                            </p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
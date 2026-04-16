import { useState } from 'react'
import { Link } from '@inertiajs/react'
import { XMarkIcon, ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/react/24/outline'

/**
 * Reusable Plan Limit Alert Component
 * 
 * Displays dismissable alerts for plan limit issues
 * 
 * @param {Object} props
 * @param {string} props.type - 'brand_limit' or 'user_limit'
 * @param {Object} props.planLimitInfo - Plan limit information from backend
 * @param {boolean} props.isAdminOrOwner - Whether user is admin/owner (shows upgrade prompt)
 * @param {string} props.alertId - Unique ID for localStorage to track dismissal
 */
export default function PlanLimitAlert({ 
    type = 'brand_limit', 
    planLimitInfo, 
    isAdminOrOwner = false,
    alertId = null 
}) {
    const [dismissed, setDismissed] = useState(() => {
        if (!alertId || typeof window === 'undefined') return false
        const dismissedAlerts = JSON.parse(localStorage.getItem('dismissed_plan_alerts') || '[]')
        return dismissedAlerts.includes(alertId)
    })

    if (!planLimitInfo || dismissed) {
        return null
    }

    const handleDismiss = () => {
        setDismissed(true)
        if (alertId && typeof window !== 'undefined') {
            const dismissedAlerts = JSON.parse(localStorage.getItem('dismissed_plan_alerts') || '[]')
            if (!dismissedAlerts.includes(alertId)) {
                dismissedAlerts.push(alertId)
                localStorage.setItem('dismissed_plan_alerts', JSON.stringify(dismissedAlerts))
            }
        }
    }

    if (type === 'brand_limit' && planLimitInfo.brand_limit_exceeded) {
        const isFree = planLimitInfo.plan_name === 'free'
        const disabledNames = planLimitInfo.disabled_brand_names || []

        if (isAdminOrOwner) {
            return (
                <div className={`mb-4 rounded-md border p-4 ${isFree ? 'bg-indigo-50 border-indigo-200' : 'bg-yellow-50 border-yellow-200'}`} role="alert">
                    <div className="flex items-start">
                        <div className="flex-shrink-0">
                            {isFree ? (
                                <InformationCircleIcon className="h-5 w-5 text-indigo-500" />
                            ) : (
                                <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600" />
                            )}
                        </div>
                        <div className="ml-3 flex-1">
                            <h3 className={`text-sm font-medium ${isFree ? 'text-indigo-800' : 'text-yellow-800'}`}>
                                {isFree ? 'Ready to grow?' : 'Brand limit reached'}
                            </h3>
                            <div className={`mt-2 text-sm ${isFree ? 'text-indigo-700' : 'text-yellow-700'}`}>
                                <p>
                                    Your {isFree ? 'free' : 'current'} plan includes <strong>{planLimitInfo.max_brands} brand{planLimitInfo.max_brands !== 1 ? 's' : ''}</strong>.
                                    {disabledNames.length > 0 && (
                                        <> <strong>{disabledNames.join(', ')}</strong> {disabledNames.length === 1 ? 'is' : 'are'} paused until you upgrade.</>
                                    )}
                                </p>
                                <p className="mt-2">
                                    <Link
                                        href="/app/billing"
                                        className={`font-medium underline ${isFree ? 'text-indigo-700 hover:text-indigo-800' : 'text-yellow-800 hover:text-yellow-900'}`}
                                    >
                                        {isFree ? 'See plans' : 'Upgrade your plan'}
                                    </Link> to unlock more brands.
                                </p>
                            </div>
                        </div>
                        <div className="ml-auto pl-3">
                            <button
                                type="button"
                                onClick={handleDismiss}
                                className={`inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                    isFree
                                        ? 'bg-indigo-50 text-indigo-500 hover:bg-indigo-100 focus:ring-indigo-600 focus:ring-offset-indigo-50'
                                        : 'bg-yellow-50 text-yellow-600 hover:bg-yellow-100 focus:ring-yellow-600 focus:ring-offset-yellow-50'
                                }`}
                            >
                                <span className="sr-only">Dismiss</span>
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </div>
            )
        } else {
            if (disabledNames.length > 0) {
                return (
                    <div className="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3" role="alert">
                        <div className="flex items-start">
                            <div className="flex-shrink-0">
                                <InformationCircleIcon className="h-5 w-5 text-blue-600" />
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm text-blue-700">
                                    <strong>{disabledNames.join(', ')}</strong> {disabledNames.length === 1 ? 'is' : 'are'} not available on the current plan. Ask your admin to upgrade.
                                </p>
                            </div>
                            <div className="ml-auto pl-3">
                                <button
                                    type="button"
                                    onClick={handleDismiss}
                                    className="inline-flex rounded-md bg-blue-50 p-1.5 text-blue-600 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 focus:ring-offset-blue-50"
                                >
                                    <span className="sr-only">Dismiss</span>
                                    <XMarkIcon className="h-5 w-5" />
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }
        }
    }

    if (type === 'user_limit' && planLimitInfo.user_limit_exceeded) {
        if (isAdminOrOwner) {
            return (
                <div className="mb-4 rounded-md bg-yellow-50 border border-yellow-200 p-4" role="alert">
                    <div className="flex items-start">
                        <div className="flex-shrink-0">
                            <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600" />
                        </div>
                        <div className="ml-3 flex-1">
                            <h3 className="text-sm font-medium text-yellow-800">
                                User Limit Exceeded
                            </h3>
                            <div className="mt-2 text-sm text-yellow-700">
                                <p>
                                    You have <strong>{planLimitInfo.current_user_count} users</strong>, but your current plan only allows <strong>{planLimitInfo.max_users} user{planLimitInfo.max_users !== 1 ? 's' : ''}</strong>.
                                </p>
                                <p className="mt-2">
                                    <Link 
                                        href="/app/billing" 
                                        className="font-medium text-yellow-800 underline hover:text-yellow-900"
                                    >
                                        Upgrade your plan
                                    </Link> to add more users.
                                </p>
                            </div>
                        </div>
                        <div className="ml-auto pl-3">
                            <button
                                type="button"
                                onClick={handleDismiss}
                                className="inline-flex rounded-md bg-yellow-50 p-1.5 text-yellow-600 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-600 focus:ring-offset-2 focus:ring-offset-yellow-50"
                            >
                                <span className="sr-only">Dismiss</span>
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </div>
            )
        }
    }

    return null
}

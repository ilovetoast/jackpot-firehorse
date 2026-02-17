import { router, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function BillingIndex({ tenant, current_plan, plans, subscription, payment_method, current_usage, current_plan_limits, site_primary_color, storage_info, storage_addon_packages }) {
    const { auth, errors, flash } = usePage().props
    const [processingPlanId, setProcessingPlanId] = useState(null)
    const [storageAddonSubmitting, setStorageAddonSubmitting] = useState(false)
    const [storageAddonError, setStorageAddonError] = useState(null)

    const handleSubscribe = (priceId, planId) => {
        if (!priceId || priceId === 'price_free' || priceId === 'price_FREE') {
            alert('This plan cannot be purchased. Please select a paid plan.')
            return
        }
        
        setProcessingPlanId(planId)
        // Submit form to create checkout session and redirect
        router.post('/app/billing/subscribe', 
            { price_id: priceId },
            {
                preserveState: false,
                preserveScroll: false,
                onFinish: () => {
                    setProcessingPlanId(null)
                },
                onError: (errors) => {
                    setProcessingPlanId(null)
                    if (errors.price_id) {
                        console.error('Price ID error:', errors.price_id)
                    }
                }
            }
        )
    }

    const handleUpdateSubscription = (priceId, planId) => {
        if (!priceId) {
            console.error('Price ID is required')
            return
        }
        
        setProcessingPlanId(planId)
        router.post('/app/billing/update-subscription', {
            price_id: priceId,
            plan_id: planId,
        }, {
            preserveScroll: false,
            onFinish: () => {
                setProcessingPlanId(null)
            },
            onError: () => {
                setProcessingPlanId(null)
            }
        })
    }
    
    // Determine if a plan is higher or lower than current plan
    const getPlanAction = (plan) => {
        if (!currentPlanData || plan.id === current_plan) {
            return null // Current plan or no current plan
        }
        
        const currentPrice = parseFloat(currentPlanData.monthly_price || 0)
        const planPrice = parseFloat(plan.monthly_price || 0)
        
        if (planPrice > currentPrice) {
            return 'upgrade'
        } else if (planPrice < currentPrice) {
            return 'downgrade'
        }
        
        return 'switch' // Same price, different plan
    }
    
    const getButtonText = (plan) => {
        if (plan.id === current_plan) {
            return 'Current Plan'
        }
        
        const action = getPlanAction(plan)
        if (action === 'upgrade') {
            return 'Upgrade'
        } else if (action === 'downgrade') {
            return 'Downgrade'
        } else if (action === 'switch') {
            return 'Switch Plan'
        }
        
        // New subscription
        return subscription ? 'Switch to Plan' : 'Buy this plan'
    }

    const handleCancel = () => {
        if (confirm('Are you sure you want to cancel your subscription? You will lose access to premium features at the end of your billing period.')) {
            setProcessingPlanId('cancel')
            router.post('/app/billing/cancel', {}, {
                onFinish: () => {
                    setProcessingPlanId(null)
                }
            })
        }
    }

    const handleResume = () => {
        setProcessingPlanId('resume')
        router.post('/app/billing/resume', {}, {
            onFinish: () => {
                setProcessingPlanId(null)
            }
        })
    }

    const handleAddStorageAddon = async (packageId) => {
        if (!packageId || storageAddonSubmitting) return
        setStorageAddonError(null)
        setStorageAddonSubmitting(true)
        try {
            const response = await window.axios.post('/app/billing/storage-addon', { package_id: packageId }, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            if (response?.data?.storage) {
                router.reload()
            }
        } catch (err) {
            setStorageAddonError(err.response?.data?.message ?? 'Failed to add storage. Please try again.')
        } finally {
            setStorageAddonSubmitting(false)
        }
    }

    const handleRemoveStorageAddon = async () => {
        if (storageAddonSubmitting) return
        if (!confirm('Remove the storage add-on? Your storage limit will decrease at the end of the billing period.')) return
        setStorageAddonError(null)
        setStorageAddonSubmitting(true)
        try {
            await window.axios.delete('/app/billing/storage-addon', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            router.reload()
        } catch (err) {
            setStorageAddonError(err.response?.data?.message ?? 'Failed to remove storage add-on. Please try again.')
        } finally {
            setStorageAddonSubmitting(false)
        }
    }

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647 || limit === 999999 || limit === 0) {
            return 'Unlimited'
        }
        if (limit === -1) {
            return 'Disabled'
        }
        return limit
    }

    const formatStorage = (mb) => {
        if (mb >= 1024 * 1024) {
            return `${(mb / 1024 / 1024).toFixed(0)} TB`
        }
        if (mb >= 1024) {
            return `${(mb / 1024).toFixed(1)} GB`
        }
        return `${mb} MB`
    }

    const formatUsage = (current, max) => {
        if (max === Number.MAX_SAFE_INTEGER || max === 2147483647 || max === 999999 || max === 0) {
            return `${current} of Unlimited`
        }
        if (max === -1) {
            return `${current} (Disabled)`
        }
        return `${current} of ${max}`
    }

    const getUsagePercentage = (current, max) => {
        if (max === Number.MAX_SAFE_INTEGER || max === 2147483647 || max === 999999 || max === 0 || max === -1) {
            return 0
        }
        return Math.min((current / max) * 100, 100)
    }

    const currentPlanData = plans?.find((p) => p.id === current_plan)
    const sitePrimaryColor = site_primary_color || '#6366f1'

    // Feature list for each plan
    const getPlanFeatures = (plan) => {
        const features = []
        
        // Always include basic features
        features.push('Custom domains')
        features.push('Edge content delivery')
        features.push('Advanced analytics')
        
        // Add download-related features
        if (plan.download_features) {
            if (plan.download_features.share_downloads_with_permissions) {
                features.push('Share downloads with custom permissions')
            }
            if (plan.download_features.custom_download_permissions) {
                features.push('Custom download link permissions')
            }
        }
        // Phase D3: Download creation UX — plan features/limits
        if (plan.download_management) {
            const dm = plan.download_management
            if (dm.rename) features.push('Rename downloads')
            if (dm.non_expiring) features.push('Non-expiring downloads')
            if (dm.restrict_access_brand) features.push('Restrict access to brand members')
            if (dm.restrict_access_company) features.push('Restrict access to company members')
            if (dm.restrict_access_users) features.push('Restrict access to specific users')
            if (dm.extend_expiration) features.push(`Extend expiration (up to ${dm.max_expiration_days || 30} days)`)
            if (dm.revoke) features.push('Revoke download links')
            if (dm.regenerate) features.push('Regenerate download links')
        }
        
        // Add plan-specific features
        if (plan.id === 'starter' || plan.id === 'pro' || plan.id === 'enterprise') {
            features.push('Quarterly workshops')
        }
        
        if (plan.id === 'pro' || plan.id === 'enterprise') {
            features.push('Single sign-on (SSO)')
            features.push('Priority phone support')
        }
        
        if (plan.id === 'enterprise') {
            features.push('Custom integrations')
            features.push('Dedicated account manager')
        }
        
        // Add role access feature
        if (plan.features && plan.features.includes('access_to_more_roles')) {
            features.push('Access to more roles and permissions')
        }
        
        return features
    }

    return (
        <div className="min-h-full bg-white">
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="relative isolate">
                {/* Gradient Background (like homepage) */}
                <div
                    className="absolute inset-x-0 -top-40 -z-10 transform-gpu overflow-hidden blur-3xl sm:-top-80 pointer-events-none"
                    aria-hidden="true"
                >
                    <div
                        className="relative left-[calc(50%-11rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 rotate-[30deg] bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%-30rem)] sm:w-[72.1875rem]"
                        style={{
                            clipPath:
                                'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                        }}
                    />
                </div>
                <div
                    className="absolute inset-x-0 top-[calc(100%-13rem)] -z-10 transform-gpu overflow-hidden blur-3xl sm:top-[calc(100%-30rem)] pointer-events-none"
                    aria-hidden="true"
                >
                    <div
                        className="relative left-[calc(50%+3rem)] aspect-[1155/678] w-[36.125rem] -translate-x-1/2 bg-gradient-to-tr from-[#ff80b5] to-[#9089fc] opacity-30 sm:left-[calc(50%+36rem)] sm:w-[72.1875rem]"
                        style={{
                            clipPath:
                                'polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.5% 76.7%, 76.1% 97.7%, 74.1% 44.1%)',
                        }}
                    />
                </div>

                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
                    {/* Header */}
                    <div className="text-center mb-12">
                        <h1 className="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                            Pricing that grows with you
                        </h1>
                        <p className="mt-4 text-lg text-gray-600">
                            Choose an affordable plan that's packed with the best features for engaging your audience, creating customer loyalty, and driving sales.
                        </p>
                    </div>

                    {/* Incomplete Payment Error - Prominent at top */}
                    {(subscription?.has_incomplete_payment || subscription?.status === 'Incomplete') && (
                        <div className="mb-6 rounded-md bg-red-50 p-4 border-2 border-red-200">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <h3 className="text-sm font-semibold text-red-800">
                                        Payment Required - Action Needed
                                    </h3>
                                    <div className="mt-2 text-sm text-red-700">
                                        <p className="font-medium">Your subscription payment is incomplete. Please complete your payment to continue service.</p>
                                        <div className="mt-4 flex flex-wrap gap-3">
                                            {subscription.payment_url ? (
                                                <a
                                                    href={subscription.payment_url}
                                                    className="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                                >
                                                    Complete Payment Now
                                                    <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                                    </svg>
                                                </a>
                                            ) : null}
                                            <button
                                                onClick={() => window.location.href = '/app/billing/portal'}
                                                className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                            >
                                                Update Payment Method
                                                <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Current Plan Usage Card */}
                    {currentPlanData && (
                        <div className="mb-12 bg-white rounded-lg border border-gray-200 shadow-sm p-6">
                            <h2 className="text-xl font-semibold text-gray-900 mb-4">Current Plan: {currentPlanData.name}</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 xl:grid-cols-8 gap-4">
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">Brands</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.brands || 0, current_plan_limits?.max_brands || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.brands || 0, current_plan_limits?.max_brands || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">Users</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.users || 0, current_plan_limits?.max_users || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.users || 0, current_plan_limits?.max_users || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">Categories</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.categories || 0, current_plan_limits?.max_categories || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.categories || 0, current_plan_limits?.max_categories || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">Storage</span>
                                        <span className="text-gray-900 font-medium">
                                            {storage_info
                                                ? `${formatStorage(storage_info.current_usage_mb)} / ${formatStorage(storage_info.max_storage_mb)}`
                                                : `${formatStorage(current_usage?.storage_mb || 0)} / ${formatStorage(current_plan_limits?.max_storage_mb || 0)}`}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${storage_info
                                                    ? Math.min(storage_info.usage_percentage || 0, 100)
                                                    : getUsagePercentage(current_usage?.storage_mb || 0, current_plan_limits?.max_storage_mb || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">Download Links</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.download_links || 0, current_plan_limits?.max_downloads_per_month || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.download_links || 0, current_plan_limits?.max_downloads_per_month || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">AI Tagging</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.ai_tagging || 0, current_plan_limits?.max_ai_tagging_per_month || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.ai_tagging || 0, current_plan_limits?.max_ai_tagging_per_month || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-gray-600">AI Suggestions</span>
                                        <span className="text-gray-900 font-medium">
                                            {formatUsage(current_usage?.ai_suggestions || 0, current_plan_limits?.max_ai_suggestions_per_month || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.ai_suggestions || 0, current_plan_limits?.max_ai_suggestions_per_month || 0)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Error Messages */}
                    {errors?.subscription && (
                        <div className="mb-6 rounded-md bg-yellow-50 p-4 border border-yellow-200">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <h3 className="text-sm font-medium text-yellow-800">
                                        Payment Required
                                    </h3>
                                    <div className="mt-2 text-sm text-yellow-700">
                                        <p>{errors.subscription}</p>
                                        {errors.payment_url && (
                                            <div className="mt-3">
                                                <a
                                                    href={errors.payment_url}
                                                    className="inline-flex items-center rounded-md bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500"
                                                >
                                                    Complete Payment
                                                    <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                    </svg>
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Plans Grid */}
                    <div className="grid grid-cols-1 gap-8 lg:grid-cols-4">
                        {plans?.map((plan) => {
                            const isCurrent = plan.is_current
                            const features = getPlanFeatures(plan)
                            
                            return (
                                <div
                                    key={plan.id}
                                    className={`relative rounded-lg border ${
                                        isCurrent
                                            ? 'border-gray-300 bg-white ring-2 shadow-lg'
                                            : 'border-gray-200 bg-white shadow-sm'
                                    }`}
                                    style={isCurrent ? { ringColor: sitePrimaryColor } : {}}
                                >
                                    {isCurrent && (
                                        <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                                            <span
                                                className="rounded-full px-3 py-1 text-xs font-medium text-white"
                                                style={{ backgroundColor: sitePrimaryColor }}
                                            >
                                                Current Plan
                                            </span>
                                        </div>
                                    )}
                                    
                                    <div className="p-6">
                                        <h3 className="text-xl font-semibold text-gray-900 mb-2">{plan.name}</h3>
                                        
                                        <div className="mb-4">
                                            {plan.monthly_price ? (
                                                <>
                                                    <span className="text-3xl font-bold text-gray-900">${plan.monthly_price}</span>
                                                    <span className="text-gray-500 ml-2">USD</span>
                                                    <p className="text-sm text-gray-500 mt-1">Billed monthly</p>
                                                </>
                                            ) : (
                                                <>
                                                    <span className="text-3xl font-bold text-gray-900">Free</span>
                                                    <p className="text-sm text-gray-500 mt-1">No credit card required</p>
                                                </>
                                            )}
                                        </div>

                                        {/* Limits Summary */}
                                        <div className="mb-6 space-y-2 text-sm text-gray-600">
                                            <div className="flex justify-between">
                                                <span>Brands:</span>
                                                <span className="font-medium text-gray-900">{formatLimit(plan.limits.max_brands)}</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Users:</span>
                                                <span className="font-medium text-gray-900">{formatLimit(plan.limits.max_users)}</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Categories:</span>
                                                <span className="font-medium text-gray-900">{formatLimit(plan.limits.max_categories)}</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Storage:</span>
                                                <span className="font-medium text-gray-900">
                                                    {plan.id === 'enterprise' ? (
                                                        <span>
                                                            <span>2 TB included</span>
                                                            <span className="block text-xs text-gray-500 font-normal">Additional storage available</span>
                                                        </span>
                                                    ) : (
                                                        formatStorage(plan.limits.max_storage_mb)
                                                    )}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Download Links:</span>
                                                <span className="font-medium text-gray-900">
                                                    {plan.download_features?.download_links_limited === false 
                                                        ? 'Unlimited' 
                                                        : formatLimit(plan.limits.max_downloads_per_month)}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Tags per Asset:</span>
                                                <span className="font-medium text-gray-900">
                                                    {formatLimit(plan.limits.max_tags_per_asset)}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>AI Tagging:</span>
                                                <span className="font-medium text-gray-900">
                                                    {formatLimit(plan.limits.max_ai_tagging_per_month)}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>AI Suggestions:</span>
                                                <span className="font-medium text-gray-900">
                                                    {formatLimit(plan.limits.max_ai_suggestions_per_month)}
                                                </span>
                                            </div>
                                            {plan.limits.max_custom_metadata_fields !== undefined && (
                                                <div className="flex justify-between">
                                                    <span>Custom Metadata Fields:</span>
                                                    <span className="font-medium text-gray-900">
                                                        {formatLimit(plan.limits.max_custom_metadata_fields)}
                                                    </span>
                                                </div>
                                            )}
                                        </div>

                                        {/* Features List */}
                                        <ul className="mb-6 space-y-3">
                                            {features.map((feature, index) => (
                                                <li key={index} className="flex items-start">
                                                    <svg
                                                        className="h-5 w-5 text-green-500 mr-2 flex-shrink-0 mt-0.5"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                    >
                                                        <path
                                                            fillRule="evenodd"
                                                            d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                                            clipRule="evenodd"
                                                        />
                                                    </svg>
                                                    <span className="text-sm text-gray-600">{feature}</span>
                                                </li>
                                            ))}
                                        </ul>

                                        {/* Action Button */}
                                        <div className="mt-6">
                                            {isCurrent ? (
                                                <button
                                                    type="button"
                                                    disabled
                                                    className="w-full rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400 cursor-not-allowed"
                                                >
                                                    Current Plan
                                                </button>
                                            ) : plan.id === 'free' ? (
                                                <button
                                                    type="button"
                                                    disabled
                                                    className="w-full rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400 cursor-not-allowed"
                                                >
                                                    Free Plan
                                                </button>
                                            ) : subscription?.has_incomplete_payment ? (
                                                <button
                                                    type="button"
                                                    disabled
                                                    className="w-full rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400 cursor-not-allowed"
                                                    title="Complete your payment before changing plans"
                                                >
                                                    Payment Required
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        if (!plan.stripe_price_id || plan.stripe_price_id === 'price_free') {
                                                            alert('This plan is not available for purchase. Please contact support.')
                                                            return
                                                        }
                                                        if (subscription) {
                                                            handleUpdateSubscription(plan.stripe_price_id, plan.id)
                                                        } else {
                                                            handleSubscribe(plan.stripe_price_id, plan.id)
                                                        }
                                                    }}
                                                    disabled={processingPlanId !== null || !plan.stripe_price_id || plan.stripe_price_id === 'price_free' || subscription?.has_incomplete_payment}
                                                    className={`w-full rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed`}
                                                    style={{ backgroundColor: sitePrimaryColor }}
                                                >
                                                    {processingPlanId === plan.id ? 'Processing...' : getButtonText(plan)}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )
                        })}
                    </div>

                    {/* Add Storage Section - for paid plans */}
                    {['starter', 'pro', 'enterprise'].includes(current_plan) && (
                        <div className="mt-10 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                            <h3 className="text-lg font-semibold text-gray-900 mb-2">Additional Storage</h3>
                            <p className="text-sm text-gray-600 mb-4">
                                Add extra storage to your plan. Storage is prorated and billed monthly.
                            </p>

                            {storage_info && (
                                <div className="mb-4 rounded-md bg-gray-50 p-3 text-sm">
                                    <div className="flex items-center justify-between text-gray-700">
                                        <span>
                                            Using <strong>{formatStorage(storage_info.current_usage_mb)}</strong> of{' '}
                                            <strong>{formatStorage(storage_info.max_storage_mb)}</strong>
                                            {storage_info.has_storage_addon && (
                                                <span className="ml-2 text-gray-500">
                                                    (+{formatStorage(storage_info.addon_storage_mb)} add-on)
                                                </span>
                                            )}
                                        </span>
                                    </div>
                                    <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${Math.min(storage_info.usage_percentage || 0, 100)}%`,
                                                backgroundColor: sitePrimaryColor,
                                            }}
                                        />
                                    </div>
                                </div>
                            )}

                            {storageAddonError && (
                                <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
                                    {storageAddonError}
                                </div>
                            )}

                            {subscription?.status !== 'active' ? (
                                <p className="text-sm text-gray-600">
                                    Add storage requires an active subscription. Subscribe to a plan above to add storage.
                                </p>
                            ) : storage_addon_packages?.length > 0 ? (
                                <div className="space-y-2">
                                    {storage_info?.has_storage_addon ? (
                                        <div className="flex items-center justify-between rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                                            <span className="text-sm text-gray-700">
                                                You have a storage add-on. To change it, remove the current add-on first.
                                            </span>
                                            <button
                                                type="button"
                                                onClick={handleRemoveStorageAddon}
                                                disabled={storageAddonSubmitting}
                                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                {storageAddonSubmitting ? 'Removing...' : 'Remove add-on'}
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            {storage_addon_packages.map((pkg) => (
                                                <button
                                                    key={pkg.id}
                                                    type="button"
                                                    onClick={() => handleAddStorageAddon(pkg.id)}
                                                    disabled={storageAddonSubmitting}
                                                    className="flex flex-col items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-3 text-center shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    <span className="text-sm font-medium text-gray-900">{pkg.label}</span>
                                                    <span className="mt-1 text-sm font-semibold" style={{ color: sitePrimaryColor }}>
                                                        ${Number(pkg.monthly_price).toFixed(2)}/mo
                                                    </span>
                                                    <span className="mt-1 text-xs text-gray-500">
                                                        Add storage
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-gray-500">
                                    Storage add-ons are not configured. Add <code className="rounded bg-gray-100 px-1 py-0.5 text-xs">STRIPE_PRICE_STORAGE_50GB</code>, etc. to your .env and create matching products in Stripe.
                                </p>
                            )}
                        </div>
                    )}

                    {/* Billing Overview & Invoices Links */}
                    <div className="mt-8 flex items-center justify-center gap-6">
                        <Link
                            href="/app/billing/overview"
                            className="text-sm font-medium text-gray-600 hover:text-gray-900"
                        >
                            View billing overview →
                        </Link>
                        
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

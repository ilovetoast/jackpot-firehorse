import { router, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function BillingIndex({ tenant, current_plan, plans, subscription, payment_method, current_usage, current_plan_limits, site_primary_color }) {
    const { auth, errors, flash } = usePage().props
    const [processingPlanId, setProcessingPlanId] = useState(null)

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

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647 || limit === 999999) {
            return 'Unlimited'
        }
        return limit
    }

    const formatStorage = (mb) => {
        if (mb === Number.MAX_SAFE_INTEGER || mb === 2147483647 || mb === 999999) {
            return 'Unlimited'
        }
        if (mb >= 1024) {
            return `${(mb / 1024).toFixed(1)} GB`
        }
        return `${mb} MB`
    }

    const formatUsage = (current, max) => {
        if (max === Number.MAX_SAFE_INTEGER || max === 2147483647 || max === 999999) {
            return `${current} of Unlimited`
        }
        return `${current} of ${max}`
    }

    const getUsagePercentage = (current, max) => {
        if (max === Number.MAX_SAFE_INTEGER || max === 2147483647 || max === 999999) {
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

                    {/* Incomplete Payment Warning */}
                    {subscription?.has_incomplete_payment && subscription?.payment_url && (
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
                                        <p>Your subscription payment is incomplete. Please complete your payment before you can upgrade or change plans.</p>
                                        <div className="mt-3">
                                            <a
                                                href={subscription.payment_url}
                                                className="inline-flex items-center rounded-md bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500"
                                            >
                                                Complete Payment
                                                <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                            </a>
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
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
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
                                            {formatStorage(current_usage?.storage_mb || 0)} / {formatStorage(current_plan_limits?.max_storage_mb || 0)}
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div
                                            className="h-2 rounded-full transition-all"
                                            style={{
                                                width: `${getUsagePercentage(current_usage?.storage_mb || 0, current_plan_limits?.max_storage_mb || 0)}%`,
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
                                                <span className="font-medium text-gray-900">{formatStorage(plan.limits.max_storage_mb)}</span>
                                            </div>
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

                    {/* Billing Overview & Invoices Links */}
                    <div className="mt-8 flex items-center justify-center gap-6">
                        <Link
                            href="/app/billing/overview"
                            className="text-sm font-medium text-gray-600 hover:text-gray-900"
                        >
                            View billing overview →
                        </Link>
                        <span className="text-gray-300">|</span>
                        <Link
                            href="/app/billing/invoices"
                            className="text-sm font-medium text-gray-600 hover:text-gray-900"
                        >
                            View all invoices →
                        </Link>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

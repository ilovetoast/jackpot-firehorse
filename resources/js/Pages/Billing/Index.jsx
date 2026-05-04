import { router, Link, usePage } from '@inertiajs/react'
import { Fragment, useState, useMemo } from 'react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import PlanCardLimitsPanel from '../../Components/Billing/PlanCardLimitsPanel'
import { findFirstUpgradePlanThatSolves, RECOGNIZED_PLAN_LIMIT_REASONS } from '../../utils/planLimitEligibility'

const PLAN_ORDER = ['free', 'starter', 'pro', 'business']

const PLAN_DESCRIPTIONS = {
    free: 'Try the platform. See what AI-powered brand asset management can do.',
    starter: 'Everything a small brand needs to stay organized and on-brand.',
    pro: 'For growing teams that need advanced permissions, approvals, and more AI.',
    business: 'Full platform power with SSO, Creator Module, and premium controls.',
}

const PLAN_HIGHLIGHTS = {
    free: [
        { text: '1 GB storage', included: true },
        { text: '75 AI credits / month', included: true },
        { text: '25 downloads / month', included: true },
        { text: 'Basic asset types', included: true },
        { text: 'AI auto-tagging', included: true },
        { text: 'Approvals & workflows', included: false },
        { text: 'Versioning', included: false },
        { text: 'Brand portal', included: false },
        { text: 'Add-ons', included: false },
    ],
    starter: [
        { text: '50 GB storage', included: true },
        { text: '300 AI credits / month', included: true },
        { text: '200 downloads / month', included: true },
        { text: 'All asset types', included: true },
        { text: 'Asset versioning (5 per file)', included: true },
        { text: 'Custom sharing & permissions', included: true },
        { text: 'Internal collections', included: true },
        { text: '5 custom metadata fields', included: true },
        { text: 'Storage & credit add-ons', included: true },
        { text: 'Approvals & workflows', included: false },
        { text: 'Brand portal', included: false },
    ],
    pro: [
        { text: '250 GB storage', included: true },
        { text: '1,500 AI credits / month', included: true },
        { text: '1,000 downloads / month', included: true },
        { text: 'Full approval workflows', included: true },
        { text: 'Versioning (25 per file)', included: true },
        { text: 'Brand portal customization', included: true },
        { text: 'Brand guidelines editor', included: true },
        { text: 'Advanced roles & permissions', included: true },
        { text: 'Private categories', included: true },
        { text: 'Public collection share links (optional password)', included: true },
        { text: 'ZIP downloads from public collection pages', included: true },
        { text: 'Creator Module add-on (base + extra seat packs)', included: true, addon: true },
        { text: 'All add-ons available', included: true },
    ],
    business: [
        { text: '1 TB storage', included: true },
        { text: '6,000 AI credits / month', included: true },
        { text: 'Unlimited downloads', included: true },
        { text: 'SSO / Single sign-on', included: true },
        { text: 'Creator Module included (50 seats)', included: true },
        { text: 'Public collections & share links', included: true },
        { text: 'ZIP downloads from public collection pages', included: true },
        { text: 'Brand portal with public access', included: true },
        { text: 'Password-protected links', included: true },
        { text: 'Download policy controls', included: true },
        { text: 'Non-expiring links', included: true },
        { text: 'Versioning (250 per file)', included: true },
        { text: 'Priority support', included: true },
    ],
}

const PLAN_KEY_LIMITS = {
    free: [
        { label: 'Storage', value: '1 GB' },
        { label: 'Brands', value: '1' },
        { label: 'Users', value: '2' },
        { label: 'Upload', value: '10 MB' },
        { label: 'AI', value: '75/mo' },
    ],
    starter: [
        { label: 'Storage', value: '50 GB' },
        { label: 'Brands', value: '1' },
        { label: 'Users', value: '5' },
        { label: 'Upload', value: '50 MB' },
        { label: 'AI', value: '300/mo' },
    ],
    pro: [
        { label: 'Storage', value: '250 GB' },
        { label: 'Brands', value: '3' },
        { label: 'Users', value: '20' },
        { label: 'Upload', value: 'Unlimited' },
        { label: 'AI', value: '1,500/mo' },
    ],
    business: [
        { label: 'Storage', value: '1 TB' },
        { label: 'Brands', value: '10' },
        { label: 'Users', value: '75' },
        { label: 'Upload', value: 'Unlimited' },
        { label: 'AI', value: '6,000/mo' },
    ],
}

export default function BillingIndex({
    tenant,
    current_plan,
    plans,
    subscription,
    payment_method,
    current_usage,
    current_plan_limits,
    site_primary_color,
    storage_info,
    storage_addon_packages,
    ai_credits_addon_packages,
    credit_weights,
    creator_addon_config,
    creator_billing_state,
    available_addons,
}) {
    const page = usePage()
    const { auth, errors, flash } = page.props
    const inertiaUrl =
        page.props.url ||
        (typeof window !== 'undefined' ? `${window.location.pathname}${window.location.search}` : '')
    const [processingPlanId, setProcessingPlanId] = useState(null)
    const [addonSubmitting, setAddonSubmitting] = useState(null)
    const [addonError, setAddonError] = useState(null)

    const sitePrimaryColor = site_primary_color || '#7c3aed'
    const currentPlanData = plans?.find((p) => p.id === current_plan)
    const visiblePlans = plans?.filter(p => PLAN_ORDER.includes(p.id)).sort((a, b) => PLAN_ORDER.indexOf(a.id) - PLAN_ORDER.indexOf(b.id)) || []

    const canManageBilling =
        Array.isArray(auth?.effective_permissions) && auth.effective_permissions.includes('billing.manage')

    const limitQuery = useMemo(() => {
        const pageUrl = inertiaUrl || ''
        const q = pageUrl.includes('?') ? pageUrl.slice(pageUrl.indexOf('?')) : ''
        const params = new URLSearchParams(q)
        return {
            reason: params.get('reason') || '',
            current_plan: params.get('current_plan') || '',
            attempted: params.get('attempted') || '',
            limit: params.get('limit') || '',
        }
    }, [inertiaUrl])

    const firstSolverPlanId = useMemo(
        () =>
            findFirstUpgradePlanThatSolves(
                visiblePlans,
                limitQuery.current_plan,
                limitQuery.reason,
                limitQuery.attempted,
            ),
        [visiblePlans, limitQuery.current_plan, limitQuery.reason, limitQuery.attempted],
    )

    const limitBannerCopy = useMemo(() => {
        if (!limitQuery.reason || !RECOGNIZED_PLAN_LIMIT_REASONS.has(limitQuery.reason)) return null
        if (limitQuery.reason !== 'max_upload_size') return null
        const planMeta = plans?.find((p) => p.id === limitQuery.current_plan)
        const name = planMeta?.name || limitQuery.current_plan
        const attemptedLabel = limitQuery.attempted ? `${limitQuery.attempted} MB` : ''
        const allowedLabel = limitQuery.limit ? `${limitQuery.limit} MB` : ''
        if (!attemptedLabel || !allowedLabel || !name) return null
        return { name, attemptedLabel, allowedLabel }
    }, [limitQuery, plans])

    const handleSubscribe = (priceId, planId) => {
        if (!priceId || priceId === 'price_free' || priceId === 'price_FREE') return
        setProcessingPlanId(planId)
        router.post('/app/billing/subscribe', { price_id: priceId }, {
            preserveState: false,
            onFinish: () => setProcessingPlanId(null),
            onError: () => setProcessingPlanId(null),
        })
    }

    const handleUpdateSubscription = (priceId, planId) => {
        if (!priceId) return
        setProcessingPlanId(planId)
        router.post('/app/billing/update-subscription', { price_id: priceId, plan_id: planId }, {
            preserveScroll: false,
            onFinish: () => setProcessingPlanId(null),
            onError: () => setProcessingPlanId(null),
        })
    }

    const handleAddonAction = async (url, body, method = 'post') => {
        setAddonError(null)
        setAddonSubmitting(body?.package_id || body?.pack_id || url)
        try {
            if (method === 'delete') {
                await window.axios.delete(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            } else {
                await window.axios.post(url, body, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            }
            router.reload()
        } catch (err) {
            setAddonError(err.response?.data?.message ?? 'Something went wrong. Please try again.')
        } finally {
            setAddonSubmitting(null)
        }
    }

    const getPlanAction = (plan) => {
        if (!currentPlanData || plan.id === current_plan) return null
        const cur = parseFloat(currentPlanData.monthly_price || 0)
        const target = parseFloat(plan.monthly_price || 0)
        return target > cur ? 'upgrade' : target < cur ? 'downgrade' : 'switch'
    }

    const getButtonLabel = (plan) => {
        if (plan.id === current_plan) return 'Current Plan'
        const action = getPlanAction(plan)
        if (action === 'upgrade') return 'Upgrade'
        if (action === 'downgrade') return 'Downgrade'
        return subscription ? 'Switch Plan' : 'Get Started'
    }

    const currentPlanIdx = PLAN_ORDER.indexOf(current_plan)
    const shouldShowPopular = (planId) => {
        if (planId !== 'pro') return false
        return currentPlanIdx < PLAN_ORDER.indexOf('pro')
    }

    const formatStorage = (mb) => {
        if (mb >= 1024 * 1024) return `${(mb / 1024 / 1024).toFixed(0)} TB`
        if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`
        return `${mb} MB`
    }

    const isPaid = (planId) => ['starter', 'pro', 'business', 'premium'].includes(planId)
    const canBuyAddons = isPaid(current_plan) && subscription?.status === 'active'

    const showCreatorAddonCard = Boolean(creator_billing_state?.show_card)
    const addonGridClass = showCreatorAddonCard ? 'md:grid-cols-3' : 'md:grid-cols-2'

    const baseCreator = creator_addon_config?.base || {}
    const baseCreatorPrice = Number(baseCreator.monthly_price ?? 99)
    const baseIncludedSeats = Number(baseCreator.included_seats ?? 25)

    return (
        <div className="min-h-full bg-gray-50">
            <AppHead title="Plans & Billing" />
            <AppNav brand={auth.activeBrand} tenant={tenant} />

            <main className="pb-20">
                {/* Incomplete payment banner */}
                {(subscription?.has_incomplete_payment || subscription?.status === 'Incomplete') && (
                    <div className="bg-red-600 text-white">
                        <div className="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-medium">Your payment is incomplete. Please update your payment method to continue service.</p>
                                <div className="flex gap-2">
                                    {subscription.payment_url && (
                                        <a href={subscription.payment_url} className="rounded bg-white px-3 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50">Complete Payment</a>
                                    )}
                                    <a href="/app/billing/portal" className="rounded bg-red-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-400">Update Card</a>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Header */}
                <div className="bg-white border-b border-gray-200">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-10">
                        <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                            <div>
                                <h1 className="text-3xl font-bold text-gray-900">Choose your plan</h1>
                                <p className="mt-2 text-gray-500">Simple pricing. No hidden fees. Upgrade, downgrade, or cancel anytime.</p>
                            </div>
                            {currentPlanData && (
                                <div className="flex items-center gap-4">
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1 text-sm font-medium text-violet-800">
                                        <span className="h-1.5 w-1.5 rounded-full bg-violet-500" />
                                        {currentPlanData.name} plan
                                    </span>
                                    <Link href="/app/billing/overview" className="text-sm font-medium text-violet-600 hover:text-violet-500">
                                        Billing & invoices &rarr;
                                    </Link>
                                </div>
                            )}
                        </div>
                    </div>
                </div>


                {limitBannerCopy ? (
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-6">
                        <div className="rounded-lg border border-amber-200 bg-amber-50/90 px-4 py-3 text-sm text-amber-950">
                            <p className="font-semibold">You hit your {limitBannerCopy.name} plan&apos;s upload limit.</p>
                            <p className="mt-1 text-amber-900/95">
                                Your file was {limitBannerCopy.attemptedLabel}. {limitBannerCopy.name} allows files up to {limitBannerCopy.allowedLabel}. Upgrade to a plan with larger uploads or reduce the file size.
                            </p>
                            {!canManageBilling ? (
                                <p className="mt-2 text-xs font-medium text-amber-900/90">
                                    Ask a workspace admin to upgrade for larger uploads.
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                {errors?.subscription && (
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-4">
                        <div
                            className="rounded-lg border border-violet-200 bg-violet-50/90 p-4"
                            style={{ borderLeftWidth: 4, borderLeftColor: sitePrimaryColor }}
                            role="alert"
                        >
                            <p className="text-sm font-medium text-violet-950">{errors.subscription}</p>
                        </div>
                    </div>
                )}

                {/* Plan Cards */}
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8">
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                        {visiblePlans.map((plan) => {
                            const isCurrent = plan.id === current_plan
                            const isPopular = shouldShowPopular(plan.id)
                            const isDowngrade = getPlanAction(plan) === 'downgrade'
                            const highlights = PLAN_HIGHLIGHTS[plan.id] || []
                            const keyLimits = PLAN_KEY_LIMITS[plan.id] || []

                            return (
                                <div
                                    key={plan.id}
                                    className={`relative flex flex-col rounded-xl border bg-white ${
                                        isCurrent ? 'border-violet-500 ring-2 ring-violet-500 shadow-lg' :
                                        isPopular ? 'border-violet-300 ring-1 ring-violet-300 shadow-md' :
                                        'border-gray-200 shadow-sm'
                                    }`}
                                >
                                    {isCurrent && (
                                        <div className="absolute -top-3 left-0 right-0 flex justify-center">
                                            <span className="rounded-full bg-violet-600 px-3 py-1 text-xs font-semibold text-white">
                                                Your plan
                                            </span>
                                        </div>
                                    )}
                                    {isPopular && !isCurrent && (
                                        <div className="absolute -top-3 left-0 right-0 flex justify-center">
                                            <span className="rounded-full bg-violet-600 px-3 py-1 text-xs font-semibold text-white">
                                                Most popular
                                            </span>
                                        </div>
                                    )}

                                    <div className="p-6 flex-1 flex flex-col">
                                        {/* Plan name & price */}
                                        <div className="mb-4">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-lg font-semibold text-gray-900">{plan.name}</h3>
                                            </div>
                                            <div className="mt-3">
                                                {plan.monthly_price ? (
                                                    <div className="flex items-baseline">
                                                        <span className="text-4xl font-bold text-gray-900">${parseFloat(plan.monthly_price).toFixed(0)}</span>
                                                        <span className="ml-1 text-sm text-gray-500">/month</span>
                                                    </div>
                                                ) : (
                                                    <div className="text-4xl font-bold text-gray-900">Free</div>
                                                )}
                                            </div>
                                            <p className="mt-2 text-sm text-gray-500 leading-snug">{PLAN_DESCRIPTIONS[plan.id]}</p>
                                        </div>

                                        {/* Key limits row */}
                                        <div className="grid grid-cols-5 rounded-lg bg-gray-50 py-3 mb-5">
                                            {keyLimits.map((s, i, arr) => (
                                                <div key={s.label} className={`flex flex-col items-center justify-center text-center px-1 ${i < arr.length - 1 ? 'border-r border-gray-200' : ''}`}>
                                                    <div className="text-sm font-bold text-gray-900 leading-tight truncate w-full">{s.value}</div>
                                                    <div className="text-[10px] uppercase tracking-wider text-gray-400 mt-0.5">{s.label}</div>
                                                </div>
                                            ))}
                                        </div>

                                        {/* Feature highlights */}
                                        <ul className="space-y-2.5 mb-6 flex-1">
                                            {highlights.map((item, i) => (
                                                <li key={i} className="flex items-start gap-2">
                                                    {item.included ? (
                                                        <svg className="h-4 w-4 mt-0.5 flex-shrink-0 text-violet-600" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-4 w-4 mt-0.5 flex-shrink-0 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M4 10a.75.75 0 01.75-.75h10.5a.75.75 0 010 1.5H4.75A.75.75 0 014 10z" clipRule="evenodd" />
                                                        </svg>
                                                    )}
                                                    <span className={`text-sm ${item.included ? 'text-gray-700' : 'text-gray-400'}`}>
                                                        {item.text}
                                                        {item.addon && <span className="ml-1 text-xs text-violet-600">(add-on)</span>}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>

                                        <PlanCardLimitsPanel
                                            plan={plan}
                                            autoExpand={Boolean(limitQuery.reason && RECOGNIZED_PLAN_LIMIT_REASONS.has(limitQuery.reason))}
                                            reason={limitQuery.reason}
                                            queryCurrentPlan={limitQuery.current_plan}
                                            firstSolverPlanId={firstSolverPlanId}
                                        />


                                        {/* CTA */}
                                        <div className="mt-auto">
                                            {isCurrent ? (
                                                <button disabled className="w-full rounded-lg bg-violet-600 py-2.5 text-sm font-semibold text-white cursor-default">
                                                    Current Plan
                                                </button>
                                            ) : plan.id === 'free' && isDowngrade ? (
                                                <button disabled className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2.5 text-sm font-semibold text-gray-400 cursor-default">
                                                    Free Forever
                                                </button>
                                            ) : plan.requires_contact ? (
                                                <Link
                                                    href={route('contact', { plan: plan.id })}
                                                    className="flex w-full justify-center rounded-lg bg-gray-900 py-2.5 text-sm font-semibold text-white hover:bg-gray-800 transition"
                                                >
                                                    Contact Sales
                                                </Link>
                                            ) : subscription?.has_incomplete_payment ? (
                                                <button disabled className="w-full rounded-lg bg-gray-100 py-2.5 text-sm font-semibold text-gray-400 cursor-not-allowed">
                                                    Payment Required
                                                </button>
                                            ) : isDowngrade ? (
                                                <button
                                                    onClick={() => {
                                                        if (!plan.stripe_price_id || plan.stripe_price_id === 'price_free') return
                                                        subscription ? handleUpdateSubscription(plan.stripe_price_id, plan.id) : handleSubscribe(plan.stripe_price_id, plan.id)
                                                    }}
                                                    disabled={processingPlanId !== null || !plan.stripe_price_id || plan.stripe_price_id === 'price_free'}
                                                    className="w-full rounded-lg border border-gray-200 bg-white py-2.5 text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    {processingPlanId === plan.id ? 'Processing...' : 'Switch to this plan'}
                                                </button>
                                            ) : limitQuery.reason === 'max_upload_size' &&
                                              plan.id === firstSolverPlanId &&
                                              !canManageBilling ? (
                                                <p className="w-full rounded-lg border border-gray-200 bg-gray-50 py-2.5 text-center text-sm font-medium text-gray-600 px-2">
                                                    Ask a workspace admin to upgrade for larger uploads.
                                                </p>
                                            ) : (
                                                <button
                                                    onClick={() => {
                                                        if (!plan.stripe_price_id || plan.stripe_price_id === 'price_free') return
                                                        subscription ? handleUpdateSubscription(plan.stripe_price_id, plan.id) : handleSubscribe(plan.stripe_price_id, plan.id)
                                                    }}
                                                    disabled={processingPlanId !== null || !plan.stripe_price_id || plan.stripe_price_id === 'price_free'}
                                                    className={`w-full rounded-lg py-2.5 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed ${
                                                        isPopular
                                                            ? 'bg-violet-600 text-white hover:bg-violet-500'
                                                            : 'bg-gray-900 text-white hover:bg-gray-800'
                                                    }`}
                                                >
                                                    {processingPlanId === plan.id ? 'Processing...' : limitQuery.reason === 'max_upload_size' && plan.id === firstSolverPlanId && canManageBilling ? 'Upgrade for larger uploads' : getButtonLabel(plan)}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )
                        })}
                    </div>

                    {/* Enterprise callout */}
                    <div className="mt-6 rounded-xl border border-gray-200 bg-white p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900">Enterprise</h3>
                            <p className="text-sm text-gray-500">Dedicated infrastructure, custom integrations, agency templates, and volume pricing. Let's talk.</p>
                        </div>
                        <Link
                            href={route('contact', { plan: 'enterprise' })}
                            className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition whitespace-nowrap"
                        >
                            Contact Sales
                        </Link>
                    </div>
                </div>

                {/* Add-ons Section */}
                {canBuyAddons && (
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-12">
                        <div className="mb-6">
                            <h2 className="text-xl font-bold text-gray-900">Add-ons</h2>
                            <p className="text-sm text-gray-500 mt-1">Boost your plan with extra storage, AI credits, or the Creator Module. Billed monthly, prorated.</p>
                        </div>

                        {addonError && (
                            <div className="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                                {addonError}
                            </div>
                        )}

                        <div className={`grid gap-6 grid-cols-1 ${addonGridClass}`}>
                            {/* Storage Add-ons */}
                            <div className="rounded-xl border border-gray-200 bg-white p-5">
                                <div className="flex items-center gap-2 mb-3">
                                    <div className="rounded-lg bg-violet-50 p-2">
                                        <svg className="h-5 w-5 text-violet-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                                        </svg>
                                    </div>
                                    <h3 className="text-sm font-semibold text-gray-900">Storage</h3>
                                </div>

                                {storage_info && (
                                    <div className="mb-3 text-xs text-gray-500">
                                        Using {formatStorage(storage_info.current_usage_mb)} of {formatStorage(storage_info.max_storage_mb)}
                                        {storage_info.has_storage_addon && <span> (+{formatStorage(storage_info.addon_storage_mb)})</span>}
                                    </div>
                                )}

                                {storage_info?.has_storage_addon ? (
                                    <div className="space-y-2">
                                        <p className="text-xs text-gray-500">You have a storage add-on active. Remove it to switch.</p>
                                        <button
                                            onClick={() => handleAddonAction('/app/billing/storage-addon', null, 'delete')}
                                            disabled={addonSubmitting !== null}
                                            className="w-full rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            {addonSubmitting === '/app/billing/storage-addon' ? 'Removing...' : 'Remove add-on'}
                                        </button>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {(storage_addon_packages || []).filter(p => (available_addons?.storage || []).includes(p.id)).map(pkg => (
                                            <button
                                                key={pkg.id}
                                                onClick={() => handleAddonAction('/app/billing/storage-addon', { package_id: pkg.id })}
                                                disabled={addonSubmitting !== null}
                                                className="w-full flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 hover:border-violet-300 hover:bg-violet-50/50 transition disabled:opacity-50"
                                            >
                                                <span className="text-sm font-medium text-gray-900">+{pkg.label}</span>
                                                <span className="text-sm font-semibold text-violet-600">${Number(pkg.monthly_price).toFixed(0)}/mo</span>
                                            </button>
                                        ))}
                                        {(storage_addon_packages || []).filter(p => (available_addons?.storage || []).includes(p.id)).length === 0 && (
                                            <p className="text-xs text-gray-400">Upgrade your plan to unlock storage add-ons.</p>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* AI Credit Add-ons */}
                            <div className="rounded-xl border border-gray-200 bg-white p-5">
                                <div className="flex items-center gap-2 mb-3">
                                    <div className="rounded-lg bg-violet-50 p-2">
                                        <svg className="h-5 w-5 text-violet-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                                        </svg>
                                    </div>
                                    <h3 className="text-sm font-semibold text-gray-900">AI Credits</h3>
                                </div>

                                {current_usage?.ai_credits && (
                                    <div className="mb-3 text-xs text-gray-500">
                                        {current_usage.ai_credits.is_unlimited
                                            ? `${current_usage.ai_credits.credits_used || 0} credits used (unlimited)`
                                            : `${current_usage.ai_credits.credits_used || 0} / ${current_usage.ai_credits.credits_cap || 0} credits used`
                                        }
                                    </div>
                                )}

                                <div className="space-y-2">
                                    {(ai_credits_addon_packages || []).filter(p => (available_addons?.ai_credits || []).includes(p.id)).map(pkg => (
                                        <button
                                            key={pkg.id}
                                            onClick={() => handleAddonAction('/app/billing/ai-credits-addon', { package_id: pkg.id })}
                                            disabled={addonSubmitting !== null}
                                            className="w-full flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 hover:border-violet-300 hover:bg-violet-50/50 transition disabled:opacity-50"
                                        >
                                            <span className="text-sm font-medium text-gray-900">+{pkg.credits.toLocaleString()} credits</span>
                                            <span className="text-sm font-semibold text-violet-600">${pkg.monthly_price}/mo</span>
                                        </button>
                                    ))}
                                    {(ai_credits_addon_packages || []).filter(p => (available_addons?.ai_credits || []).includes(p.id)).length === 0 && (
                                        <p className="text-xs text-gray-400">Upgrade your plan to unlock AI credit packs.</p>
                                    )}
                                </div>
                            </div>

                            {/* Creator Module */}
                            {showCreatorAddonCard ? (
                                <div className="rounded-xl border border-gray-200 bg-white p-5">
                                    <div className="flex items-start justify-between gap-2 mb-2">
                                        <div className="flex items-center gap-2">
                                            <div className="rounded-lg bg-amber-50 p-2">
                                                <svg className="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                                </svg>
                                            </div>
                                            <h3 className="text-sm font-semibold text-gray-900">Creator Module</h3>
                                        </div>
                                        {creator_billing_state?.plan_includes_module ? (
                                            <span className="shrink-0 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-green-800">
                                                Included
                                            </span>
                                        ) : null}
                                    </div>

                                    <p className="text-xs text-gray-500 mb-3">
                                        Give freelancers and external creators scoped upload access with their own portal. Track performance and manage seats.
                                    </p>

                                    {creator_billing_state?.plan_includes_module ? (
                                        <div className="mb-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2">
                                            <p className="text-xs font-medium text-green-800">
                                                Included in {currentPlanData?.name || 'your plan'} — no extra charge for the module.
                                            </p>
                                            {creator_billing_state?.seats_limit != null ? (
                                                <p className="mt-1 text-xs text-green-700">
                                                    {creator_billing_state.seats_limit} creator seats included
                                                    {creator_billing_state.active_seat_pack_id ? ' (with add-on)' : ''}.
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <div className="mb-3 space-y-0.5">
                                            <p className="text-sm font-semibold text-gray-900">
                                                Starts at ${baseCreatorPrice.toFixed(0)}/mo
                                            </p>
                                            <p className="text-xs text-gray-500">Includes {baseIncludedSeats} creator seats</p>
                                        </div>
                                    )}

                                    {creator_billing_state?.can_purchase_base_module &&
                                    !creator_billing_state?.stripe_base_subscription_active ? (
                                        <button
                                            type="button"
                                            onClick={() => handleAddonAction('/app/billing/creator-module', {})}
                                            disabled={addonSubmitting !== null}
                                            className="mb-3 w-full rounded-lg border border-amber-200 bg-amber-50/80 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-50 disabled:opacity-50"
                                        >
                                            {addonSubmitting === '/app/billing/creator-module' ? 'Adding…' : 'Add Creator Module'}
                                        </button>
                                    ) : null}

                                    {creator_billing_state?.stripe_base_subscription_active &&
                                    !creator_billing_state?.plan_includes_module ? (
                                        <button
                                            type="button"
                                            onClick={() => handleAddonAction('/app/billing/creator-module', null, 'delete')}
                                            disabled={addonSubmitting !== null}
                                            className="mb-3 w-full rounded-lg border border-gray-200 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            {addonSubmitting === '/app/billing/creator-module' ? 'Removing…' : 'Remove Creator Module'}
                                        </button>
                                    ) : null}

                                    <details className="group rounded-lg border border-gray-100 bg-gray-50/80">
                                        <summary className="cursor-pointer list-none px-3 py-2 text-xs font-semibold text-gray-800 marker:content-none [&::-webkit-details-marker]:hidden flex items-center justify-between">
                                            <span>Add more creator seats</span>
                                            <span className="text-gray-400 transition group-open:rotate-90">›</span>
                                        </summary>
                                        <div className="space-y-2 border-t border-gray-100 px-3 pb-3 pt-2">
                                            {!creator_billing_state?.can_manage_seat_packs ? (
                                                <p className="text-xs text-gray-500">
                                                    Add the Creator Module (or use a plan that includes it) to purchase extra seat packs.
                                                </p>
                                            ) : (creator_addon_config?.seat_packs || []).length === 0 ? (
                                                <p className="text-xs text-gray-400">Seat pack prices are not configured.</p>
                                            ) : (
                                                (creator_addon_config.seat_packs || []).map((pack) => (
                                                    <button
                                                        key={pack.id}
                                                        type="button"
                                                        onClick={() =>
                                                            handleAddonAction('/app/billing/creator-seats', {
                                                                pack_id: pack.id,
                                                            })
                                                        }
                                                        disabled={addonSubmitting !== null}
                                                        className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left transition disabled:opacity-50 ${
                                                            creator_billing_state?.active_seat_pack_id === pack.id
                                                                ? 'border-amber-400 bg-amber-50 ring-1 ring-amber-200'
                                                                : 'border-gray-200 hover:border-amber-300 hover:bg-amber-50/40'
                                                        }`}
                                                    >
                                                        <span className="text-sm font-medium text-gray-900">
                                                            +{pack.seats} seats
                                                        </span>
                                                        <span className="text-sm font-semibold text-amber-600">
                                                            ${Number(pack.monthly_price).toFixed(0)}/mo
                                                        </span>
                                                    </button>
                                                ))
                                            )}
                                            {creator_billing_state?.active_seat_pack_id ? (
                                                <p className="text-[11px] text-gray-500">
                                                    You have a seat pack subscription. Choosing another option updates your add-on (prorated).
                                                </p>
                                            ) : null}
                                        </div>
                                    </details>
                                </div>
                            ) : null}
                        </div>
                    </div>
                )}

                {/* Agency Partner CTA */}
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-12">
                    <div className="rounded-xl bg-gradient-to-br from-gray-900 to-gray-800 p-8 sm:p-10">
                        <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                            <div className="max-w-xl">
                                <div className="inline-flex items-center gap-1.5 rounded-full bg-violet-500/15 px-3 py-1 text-xs font-semibold text-violet-300 mb-3">
                                    <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                    Agency Partner Program
                                </div>
                                <h2 className="text-2xl font-bold text-white">Run an agency or studio?</h2>
                                <p className="mt-2 text-gray-400 text-sm leading-relaxed">
                                    Our Agency Program gives you dedicated tools for multi-brand management, client incubation, ownership transfers, and referral tracking. Set up client workspaces, manage handoffs, and unlock partner tiers as your portfolio grows.
                                </p>
                                <ul className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                                    {[
                                        'Client workspace incubation',
                                        'Seamless ownership transfers',
                                        'Referral attribution & tracking',
                                        'Tiered partner rewards (Silver / Gold / Platinum)',
                                        'Agency dashboard & analytics',
                                        'Scoped brand-level access',
                                    ].map((item) => (
                                        <li key={item} className="flex items-center gap-2 text-sm text-gray-300">
                                            <svg className="h-4 w-4 text-violet-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clipRule="evenodd" />
                                            </svg>
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div className="flex flex-col items-start lg:items-center gap-3">
                                <Link
                                    href="/agency"
                                    className="inline-flex items-center justify-center rounded-lg bg-violet-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-violet-500 transition whitespace-nowrap"
                                >
                                    Learn about the Agency Program
                                </Link>
                                <Link
                                    href="/contact?plan=agency"
                                    className="text-sm font-medium text-gray-400 hover:text-white transition"
                                >
                                    Or talk to our partnerships team &rarr;
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Footer link */}
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 mt-8 text-center">
                    <Link href="/app/billing/overview" className="text-sm font-medium text-gray-500 hover:text-violet-700">
                        View billing overview, invoices & usage details &rarr;
                    </Link>
                </div>
            </main>
            <AppFooter variant="settings" />
        </div>
    )
}

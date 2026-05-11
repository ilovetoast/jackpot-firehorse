import { Link, router, usePage } from '@inertiajs/react'
import { useState, useMemo } from 'react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import { RECOGNIZED_PLAN_LIMIT_REASONS } from '../../utils/planLimitEligibility'
import { AI_FEATURE_LABELS } from '../../utils/aiCreditsUsageDisplay'

export default function BillingOverview({
    tenant,
    current_plan,
    subscription,
    payment_method,
    recent_invoices,
    has_stripe_id,
    on_demand_usage,
    monthly_average,
    currency,
    storage_info,
    storage_addon_packages,
    ai_credits,
    credit_weights,
    credit_tier_costs = {},
    ai_credits_addon_packages,
    ai_credits_addon_state = { active: false, monthly_credits: 0 },
    creator_addon_config,
    creator_billing_state = null,
}) {
    const page = usePage()
    const { auth } = page.props
    const inertiaUrl =
        page.props.url ||
        (typeof window !== 'undefined' ? `${window.location.pathname}${window.location.search}` : '')

    const limitOverviewCard = useMemo(() => {
        const pageUrl = inertiaUrl || ''
        const q = pageUrl.includes('?') ? pageUrl.slice(pageUrl.indexOf('?')) : ''
        const params = new URLSearchParams(q)
        const reason = params.get('reason') || ''
        if (!reason || !RECOGNIZED_PLAN_LIMIT_REASONS.has(reason)) return null
        if (reason !== 'max_upload_size') return null
        const currentPlanKey = params.get('current_plan') || ''
        const attempted = params.get('attempted') || ''
        const limit = params.get('limit') || ''
        const attemptedLabel = attempted ? `${attempted} MB` : ''
        const allowedLabel = limit ? `${limit} MB` : ''
        if (!attemptedLabel || !allowedLabel) return null
        const base = route('billing.plans')
        const plansHref = q ? `${base}${q}` : base
        return { attemptedLabel, allowedLabel, plansHref, currentPlanKey }
    }, [inertiaUrl])

    const canManageBilling =
        Array.isArray(auth?.effective_permissions) && auth.effective_permissions.includes('billing.manage')
    const [billingAddonBusy, setBillingAddonBusy] = useState(null)
    const [billingAddonError, setBillingAddonError] = useState(null)

    const formatStorage = (mb) => {
        if (mb >= 1024 * 1024) return `${(mb / 1024 / 1024).toFixed(0)} TB`
        if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`
        return `${mb} MB`
    }

    const handleManageBilling = () => {
        // Direct full page redirect for external Stripe portal
        window.location.href = '/app/billing/portal'
    }

    const billingJsonHeaders = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

    const handleAddStorageAddon = async (packageId) => {
        if (!packageId || billingAddonBusy || !canManageBilling) return
        setBillingAddonError(null)
        setBillingAddonBusy('storage')
        try {
            const response = await window.axios.post('/app/billing/storage-addon', { package_id: packageId }, {
                headers: billingJsonHeaders,
            })
            if (response?.data?.storage) router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to add storage. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleRemoveStorageAddon = async () => {
        if (billingAddonBusy || !canManageBilling) return
        if (!confirm('Remove the storage add-on? Stripe will apply any credits for unused time; your workspace limit updates right away.')) return
        setBillingAddonError(null)
        setBillingAddonBusy('storage')
        try {
            await window.axios.delete('/app/billing/storage-addon', {
                headers: billingJsonHeaders,
            })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to remove storage add-on. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleAddAiCreditsAddon = async (packageId) => {
        if (!packageId || billingAddonBusy || !canManageBilling) return
        setBillingAddonError(null)
        setBillingAddonBusy(`ai:${packageId}`)
        try {
            await window.axios.post('/app/billing/ai-credits-addon', { package_id: packageId }, { headers: billingJsonHeaders })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to add AI credit add-on. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleRemoveAiCreditsAddon = async () => {
        if (billingAddonBusy || !canManageBilling) return
        if (!confirm('Remove the monthly AI credit add-on? Credits from your plan only will apply next period.')) return
        setBillingAddonError(null)
        setBillingAddonBusy('ai')
        try {
            await window.axios.delete('/app/billing/ai-credits-addon', { headers: billingJsonHeaders })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to remove AI credit add-on. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleCreatorModuleAdd = async () => {
        if (billingAddonBusy || !canManageBilling) return
        setBillingAddonError(null)
        setBillingAddonBusy('creator-module')
        try {
            await window.axios.post('/app/billing/creator-module', {}, { headers: billingJsonHeaders })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to add Creator module. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleCreatorModuleRemove = async () => {
        if (billingAddonBusy || !canManageBilling) return
        if (!confirm('Remove the Creator module add-on? Creator portal access may be limited based on your plan.')) return
        setBillingAddonError(null)
        setBillingAddonBusy('creator-module')
        try {
            await window.axios.delete('/app/billing/creator-module', { headers: billingJsonHeaders })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to remove Creator module. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const handleCreatorSeatPack = async (packId) => {
        if (!packId || billingAddonBusy || !canManageBilling) return
        setBillingAddonError(null)
        setBillingAddonBusy(`creator-seats:${packId}`)
        try {
            await window.axios.post('/app/billing/creator-seats', { pack_id: packId }, { headers: billingJsonHeaders })
            router.reload()
        } catch (err) {
            setBillingAddonError(err.response?.data?.message ?? 'Failed to update creator seats. Please try again.')
        } finally {
            setBillingAddonBusy(null)
        }
    }

    const formatCurrency = (amount, currency = 'USD') => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
        }).format(amount)
    }

    const getStatusColor = (status) => {
        const statusMap = {
            paid: 'bg-green-100 text-green-800',
            open: 'bg-yellow-100 text-yellow-800',
            void: 'bg-gray-100 text-gray-800',
            uncollectible: 'bg-red-100 text-red-800',
            refunded: 'bg-slate-100 text-slate-800',
            partially_refunded: 'bg-amber-100 text-amber-900',
        }
        return statusMap[status.toLowerCase()] || 'bg-gray-100 text-gray-800'
    }

    const formatStatusLabel = (status) => {
        if (!status) return ''
        const s = String(status).replace(/_/g, ' ')
        return s.charAt(0).toUpperCase() + s.slice(1)
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppHead title="Billing & Invoices" />
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main>
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}

                    {limitOverviewCard ? (
                        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50/90 px-4 py-3">
                            <h2 className="text-sm font-semibold text-amber-950">You hit a plan limit</h2>
                            <p className="mt-1 text-sm text-amber-900/95">
                                Your file was {limitOverviewCard.attemptedLabel}. This workspace allows files up to {limitOverviewCard.allowedLabel}.
                            </p>
                            {canManageBilling ? (
                                <Link
                                    href={limitOverviewCard.plansHref}
                                    className="mt-3 inline-flex items-center rounded-md bg-amber-800 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-900"
                                >
                                    View upgrade options
                                </Link>
                            ) : (
                                <p className="mt-2 text-xs font-medium text-amber-900/90">
                                    Ask a workspace admin to upgrade for larger uploads.
                                </p>
                            )}
                        </div>
                    ) : null}

                    <div className="mb-8">
                        <Link href="/app/billing" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Plans
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Billing & Invoices</h1>
                            <p className="mt-2 text-sm text-gray-700">View your subscription, usage, and billing history</p>
                        </div>
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
                                            {has_stripe_id && (
                                                <button
                                                    onClick={handleManageBilling}
                                                    className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                                >
                                                    Update Payment Method
                                                    <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                    </svg>
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Current Plan Teaser */}
                    <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center">
                                <svg className="h-5 w-5 text-gray-400 mr-2" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                </svg>
                                <h2 className="text-lg font-semibold text-gray-900">Plan & Billing</h2>
                            </div>
                            <p className="mt-1 text-sm text-gray-500">Manage your subscription and billing information</p>
                        </div>
                        <div className="px-6 py-5">
                            <div className="space-y-6">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-500">Current Plan</label>
                                        <p className="mt-1 text-lg font-semibold text-gray-900">{current_plan.name}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <Link
                                            href="/app/billing"
                                            className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                        >
                                            Manage Plan
                                            <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                            </svg>
                                        </Link>
                                        {has_stripe_id && (
                                            <button
                                                onClick={handleManageBilling}
                                                className="inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                            >
                                                Manage Subscription
                                                <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                            </button>
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-500">Subscription Status</label>
                                    <div className="mt-1 flex items-center gap-2">
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                            subscription.status === 'Active' ? 'bg-green-100 text-green-800' :
                                            subscription.status === 'Incomplete' ? 'bg-yellow-100 text-yellow-800' :
                                            subscription.status === 'Past Due' ? 'bg-orange-100 text-orange-800' :
                                            subscription.status === 'Trialing' ? 'bg-blue-100 text-blue-800' :
                                            subscription.status === 'Canceled' ? 'bg-red-100 text-red-800' :
                                            'bg-gray-100 text-gray-800'
                                        }`}>
                                            {subscription.status}
                                        </span>
                                    </div>
                                    {subscription.period_start && subscription.period_end && (
                                        <p className="mt-2 text-sm text-gray-500">
                                            Billing period: {subscription.period_start} - {subscription.period_end}
                                        </p>
                                    )}
                                </div>
                                {payment_method && (
                                    <div>
                                        <label className="block text-sm font-medium text-gray-500">Payment Method</label>
                                        <p className="mt-1 text-sm font-semibold text-gray-900">
                                            {payment_method.brand?.toUpperCase() || payment_method.type} •••• {payment_method.last_four}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Additional Storage - for paid plans */}
                    {current_plan?.id && current_plan.id !== 'free' && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Additional Storage</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    Extra storage is billed monthly on your subscription. When you add or change an add-on, Stripe invoices
                                    the prorated amount right away and charges your default payment method—if the charge cannot complete,
                                    the add-on is not applied. See{' '}
                                    <Link href="/app/billing/invoices" className="font-medium text-indigo-600 hover:text-indigo-800">
                                        invoices
                                    </Link>{' '}
                                    for line items.
                                </p>
                            </div>
                            <div className="px-6 py-5">
                                {storage_info && (
                                    <div className="mb-4 rounded-md bg-gray-50 p-3 text-sm">
                                        <div className="flex items-center justify-between text-gray-700">
                                            <span>
                                                Using <strong>{formatStorage(storage_info.current_usage_mb)}</strong> of{' '}
                                                <strong>{formatStorage(storage_info.max_storage_mb)}</strong>
                                                {storage_info.has_storage_addon && (
                                                    <span className="ml-2 text-gray-500">(+{formatStorage(storage_info.addon_storage_mb)} add-on)</span>
                                                )}
                                            </span>
                                        </div>
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className="h-2 rounded-full transition-all bg-indigo-600"
                                                style={{ width: `${Math.min(storage_info.usage_percentage || 0, 100)}%` }}
                                            />
                                        </div>
                                    </div>
                                )}

                                {!canManageBilling && (
                                    <p className="mb-4 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-3 py-2">
                                        Only users with billing permission can purchase or remove add-ons.
                                    </p>
                                )}

                                {billingAddonError && (
                                    <div className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{billingAddonError}</div>
                                )}

                                {subscription?.status_lower === 'active' && storage_addon_packages?.length > 0 ? (
                                    storage_info?.has_storage_addon ? (
                                        <div className="flex items-center justify-between rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                                            <span className="text-sm text-gray-700">You have a storage add-on. To change it, remove the current add-on first.</span>
                                            <button
                                                type="button"
                                                onClick={handleRemoveStorageAddon}
                                                disabled={billingAddonBusy !== null || !canManageBilling}
                                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                            >
                                                {billingAddonBusy === 'storage' ? 'Removing...' : 'Remove add-on'}
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            {storage_addon_packages.map((pkg) => (
                                                <button
                                                    key={pkg.id}
                                                    type="button"
                                                    onClick={() => handleAddStorageAddon(pkg.id)}
                                                    disabled={billingAddonBusy !== null || !canManageBilling}
                                                    className="flex flex-col items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-3 text-center shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                                >
                                                    <span className="text-sm font-medium text-gray-900">{pkg.label}</span>
                                                    <span className="mt-1 text-sm font-semibold text-indigo-600">${Number(pkg.monthly_price).toFixed(2)}/mo</span>
                                                    <span className="mt-1 text-xs text-gray-500">Add storage</span>
                                                </button>
                                            ))}
                                        </div>
                                    )
                                ) : subscription?.status_lower === 'active' && (!storage_addon_packages || storage_addon_packages.length === 0) ? (
                                    <p className="text-sm text-gray-500">
                                        No storage add-ons are available on your current plan, or Stripe prices are not configured.
                                    </p>
                                ) : (
                                    <p className="text-sm text-gray-600">
                                        Add storage requires an active subscription.{' '}
                                        <Link href="/app/billing/plans" className="font-medium text-indigo-600 hover:text-indigo-500">
                                            Manage plan →
                                        </Link>
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    {creator_billing_state?.show_card && current_plan?.id && current_plan.id !== 'free' && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Creator module & seats</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    External creators get a scoped upload portal. Add-ons bill immediately to your saved card (same as storage).
                                </p>
                            </div>
                            <div className="px-6 py-5 space-y-4">
                                {!canManageBilling && (
                                    <p className="text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-3 py-2">
                                        Only users with billing permission can purchase or remove this module.
                                    </p>
                                )}
                                {creator_billing_state?.plan_includes_module ? (
                                    <p className="text-sm text-gray-700">
                                        <strong>Included</strong> in your plan
                                        {creator_billing_state?.seats_limit != null ? (
                                            <span className="text-gray-600">
                                                {' '}
                                                — {creator_billing_state.seats_limit} creator seats
                                                {creator_billing_state?.active_seat_pack_id ? ' (with seat add-on)' : ''}.
                                            </span>
                                        ) : null}
                                    </p>
                                ) : null}
                                {creator_billing_state?.can_purchase_base_module &&
                                !creator_billing_state?.stripe_base_subscription_active &&
                                subscription?.status_lower === 'active' ? (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={handleCreatorModuleAdd}
                                            disabled={billingAddonBusy !== null || !canManageBilling}
                                            className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
                                        >
                                            {billingAddonBusy === 'creator-module'
                                                ? 'Adding…'
                                                : `Add Creator module ($${Number(creator_addon_config?.base?.monthly_price ?? 0).toFixed(2)}/mo)`}
                                        </button>
                                    </div>
                                ) : null}
                                {creator_billing_state?.stripe_base_subscription_active && !creator_billing_state?.plan_includes_module ? (
                                    <div className="flex flex-wrap items-center gap-3">
                                        <span className="text-sm text-green-800 font-medium">Creator module is active on your subscription.</span>
                                        <button
                                            type="button"
                                            onClick={handleCreatorModuleRemove}
                                            disabled={billingAddonBusy !== null || !canManageBilling}
                                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                        >
                                            {billingAddonBusy === 'creator-module' ? 'Removing…' : 'Remove module'}
                                        </button>
                                    </div>
                                ) : null}
                                {creator_billing_state?.can_manage_seat_packs && subscription?.status_lower === 'active' ? (
                                    <div>
                                        <h3 className="text-sm font-medium text-gray-900 mb-2">Creator seat packs</h3>
                                        {(creator_addon_config?.seat_packs || []).length === 0 ? (
                                            <p className="text-sm text-gray-500">Seat pack prices are not configured.</p>
                                        ) : (
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {(creator_addon_config?.seat_packs || []).map((pack) => (
                                                    <button
                                                        key={pack.id}
                                                        type="button"
                                                        onClick={() => handleCreatorSeatPack(pack.id)}
                                                        disabled={billingAddonBusy !== null || !canManageBilling}
                                                        className={`flex flex-col items-start rounded-md border px-4 py-3 text-left shadow-sm disabled:opacity-50 ${
                                                            creator_billing_state?.active_seat_pack_id === pack.id
                                                                ? 'border-indigo-500 bg-indigo-50'
                                                                : 'border-gray-300 bg-white hover:bg-gray-50'
                                                        }`}
                                                    >
                                                        <span className="text-sm font-medium text-gray-900">+{pack.seats} seats</span>
                                                        <span className="text-sm font-semibold text-indigo-600">
                                                            ${Number(pack.monthly_price).toFixed(2)}/mo
                                                        </span>
                                                        {creator_billing_state?.active_seat_pack_id === pack.id ? (
                                                            <span className="mt-1 text-xs text-indigo-800">Current add-on</span>
                                                        ) : (
                                                            <span className="mt-1 text-xs text-gray-500">Select pack</span>
                                                        )}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    )}

                    {/* AI Credits Usage */}
                    {ai_credits && (
                        <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">AI Credits</h2>
                                        <p className="mt-1 text-sm text-gray-500">Unified credit pool across all AI features this month</p>
                                    </div>
                                </div>
                            </div>
                            <div className="px-6 py-5">
                                <div className="mb-4">
                                    <div className="flex justify-between text-sm mb-2">
                                        <span className="text-gray-600">
                                            {ai_credits.credits_used?.toLocaleString() || 0} / {ai_credits.is_unlimited ? 'Unlimited' : (ai_credits.credits_cap?.toLocaleString() || 0)} credits used
                                        </span>
                                        {!ai_credits.is_unlimited && (
                                            <span className="font-medium text-gray-900">
                                                {ai_credits.credits_remaining?.toLocaleString() || 0} remaining
                                            </span>
                                        )}
                                    </div>
                                    {!ai_credits.is_unlimited && (
                                        <div className="w-full bg-gray-200 rounded-full h-3">
                                            <div
                                                className={`h-3 rounded-full transition-all ${
                                                    (ai_credits.warning_level || 0) >= 100 ? 'bg-red-500' :
                                                    (ai_credits.warning_level || 0) >= 90 ? 'bg-orange-500' :
                                                    (ai_credits.warning_level || 0) >= 80 ? 'bg-yellow-500' :
                                                    'bg-indigo-600'
                                                }`}
                                                style={{ width: `${Math.min(100, ai_credits.credits_percentage || 0)}%` }}
                                            />
                                        </div>
                                    )}
                                    {ai_credits.warning_level >= 80 && (
                                        <div className={`mt-3 rounded-md p-3 text-sm ${
                                            ai_credits.warning_level >= 100 ? 'bg-red-50 text-red-700 border border-red-200' :
                                            ai_credits.warning_level >= 90 ? 'bg-orange-50 text-orange-700 border border-orange-200' :
                                            'bg-yellow-50 text-yellow-700 border border-yellow-200'
                                        }`}>
                                            {ai_credits.warning_level >= 100
                                                ? 'AI credit budget exhausted. Premium AI actions are paused until next month. Purchase a credit add-on to continue.'
                                                : `${ai_credits.warning_level}% of your AI credits have been used this month.`
                                            }
                                        </div>
                                    )}
                                </div>

                                {!ai_credits.is_unlimited &&
                                    current_plan?.id &&
                                    current_plan.id !== 'free' &&
                                    subscription?.status_lower === 'active' &&
                                    (ai_credits_addon_packages?.length > 0 || ai_credits_addon_state?.active) && (
                                    <div className="mt-4 border-t border-gray-100 pt-4">
                                        <h4 className="text-sm font-medium text-gray-900 mb-1">Monthly credit add-on</h4>
                                        <p className="text-sm text-gray-500 mb-3">
                                            Adds recurring credits each billing cycle. Proration is charged immediately to your default
                                            card; if payment fails, the add-on is not applied.
                                        </p>
                                        {!canManageBilling && (
                                            <p className="mb-3 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-md px-3 py-2">
                                                Only users with billing permission can purchase or remove credit add-ons.
                                            </p>
                                        )}
                                        {ai_credits_addon_state?.active ? (
                                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 px-4 py-3">
                                                <span className="text-sm text-gray-700">
                                                    Active add-on: <strong>+{ai_credits_addon_state.monthly_credits?.toLocaleString()}</strong>{' '}
                                                    credits per month. Remove to change packs.
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={handleRemoveAiCreditsAddon}
                                                    disabled={billingAddonBusy !== null || !canManageBilling}
                                                    className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                                >
                                                    {billingAddonBusy === 'ai' ? 'Removing…' : 'Remove add-on'}
                                                </button>
                                            </div>
                                        ) : (
                                            <div className="grid gap-2 sm:grid-cols-3">
                                                {(ai_credits_addon_packages || []).map((pkg) => (
                                                    <button
                                                        key={pkg.id}
                                                        type="button"
                                                        onClick={() => handleAddAiCreditsAddon(pkg.id)}
                                                        disabled={billingAddonBusy !== null || !canManageBilling}
                                                        className="flex flex-col items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-3 text-center shadow-sm hover:bg-gray-50 disabled:opacity-50"
                                                    >
                                                        <span className="text-sm font-medium text-gray-900">
                                                            +{pkg.credits?.toLocaleString()} credits/mo
                                                        </span>
                                                        <span className="mt-1 text-sm font-semibold text-indigo-600">
                                                            ${Number(pkg.monthly_price).toFixed(2)}/mo
                                                        </span>
                                                        <span className="mt-1 text-xs text-gray-500">
                                                            {billingAddonBusy === `ai:${pkg.id}` ? 'Adding…' : 'Add'}
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Per-feature breakdown */}
                                {ai_credits.per_feature && Object.keys(ai_credits.per_feature).length > 0 && (
                                    <div className="mt-4 border-t border-gray-100 pt-4">
                                        <h4 className="text-sm font-medium text-gray-700 mb-3">Usage Breakdown</h4>
                                        <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
                                            {Object.entries(ai_credits.per_feature).map(([feature, data]) => (
                                                data.calls > 0 && (
                                                    <div key={feature} className="text-sm bg-gray-50 rounded-md p-2">
                                                        <div className="text-gray-500">
                                                            {AI_FEATURE_LABELS[feature] ?? feature.replace(/_/g, ' ')}
                                                        </div>
                                                        <div className="font-medium text-gray-900">
                                                            {data.calls} calls = {data.credits_used} credits
                                                        </div>
                                                    </div>
                                                )
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Common action costs */}
                                {((credit_weights && Object.keys(credit_weights).length > 0) ||
                                    Object.keys(credit_tier_costs).length > 0) && (
                                    <div className="mt-4 border-t border-gray-100 pt-4">
                                        <h4 className="text-sm font-medium text-gray-700 mb-2">Credit Costs per Action</h4>
                                        <div className="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-gray-500">
                                            {Object.entries(credit_weights || {}).map(([action, cost]) => (
                                                <div key={action} className="flex justify-between bg-gray-50 rounded px-2 py-1">
                                                    <span className="capitalize">{action.replace(/_/g, ' ')}</span>
                                                    <span className="font-medium text-gray-700">{cost}</span>
                                                </div>
                                            ))}
                                            {Object.entries(credit_tier_costs).map(([action, tier]) => (
                                                <div key={action} className="flex justify-between bg-gray-50 rounded px-2 py-1">
                                                    <span>{tier.label || action.replace(/_/g, ' ')}</span>
                                                    <span className="font-medium text-gray-700">
                                                        {tier.base_credits}
                                                        {tier.per_additional_minute ? ` + ${tier.per_additional_minute}/min` : ''}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Recent Charges / On-Demand Usage & Monthly Average */}
                    <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">Usage Overview</h2>
                                    <p className="mt-1 text-sm text-gray-500">On-demand charges and monthly average</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-6 py-5">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {/* On-Demand Usage */}
                                <div className="text-center py-6 border-r border-gray-200 md:border-r md:border-b-0 border-b pb-6 md:pb-0">
                                    <p className="text-sm font-medium text-gray-500 mb-2">On-Demand</p>
                                    <p className="text-3xl font-semibold text-gray-900">
                                        {formatCurrency(on_demand_usage || 0, currency)}
                                    </p>
                                    <p className="mt-2 text-sm text-gray-500">Additional charges beyond your plan</p>
                                </div>
                                
                                {/* Monthly Average */}
                                <div className="text-center py-6">
                                    <p className="text-sm font-medium text-gray-500 mb-2">Monthly Average</p>
                                    <p className="text-3xl font-semibold text-gray-900">
                                        {formatCurrency(monthly_average || 0, currency)}
                                    </p>
                                    <p className="mt-2 text-sm text-gray-500">Average monthly spend (last 12 months)</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Recent Invoices */}
                    <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">Invoices</h2>
                                    <p className="mt-1 text-sm text-gray-500">View and download your billing invoices</p>
                                </div>
                                {recent_invoices.length > 0 && (
                                    <Link
                                        href="/app/billing/invoices"
                                        className="text-sm font-medium text-gray-600 hover:text-gray-900"
                                    >
                                        View all →
                                    </Link>
                                )}
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            {recent_invoices.length === 0 ? (
                                <div className="px-6 py-8 text-center text-sm text-gray-500">
                                    No invoices found
                                </div>
                            ) : (
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {recent_invoices.map((invoice) => (
                                            <tr key={invoice.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    {invoice.date}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    Subscription Invoice
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${getStatusColor(invoice.status)}`}>
                                                        {formatStatusLabel(invoice.status)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <div>{formatCurrency(invoice.amount, invoice.currency)}</div>
                                                    {Number(invoice.amount_refunded) > 0 ? (
                                                        <div className="text-xs text-gray-500 mt-0.5">
                                                            Refunded {formatCurrency(invoice.amount_refunded, invoice.currency)}
                                                        </div>
                                                    ) : null}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                    {invoice.url ? (
                                                        <a
                                                            href={invoice.url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center text-indigo-600 hover:text-indigo-900"
                                                        >
                                                            View
                                                            <svg className="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                            </svg>
                                                        </a>
                                                    ) : (
                                                        <Link
                                                            href={`/app/billing/invoices/${invoice.id}/download`}
                                                            className="inline-flex items-center text-indigo-600 hover:text-indigo-900"
                                                        >
                                                            Download
                                                            <svg className="ml-1 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                            </svg>
                                                        </Link>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter variant="settings" />
        </div>
    )
}

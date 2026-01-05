import { useForm, router, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function BillingIndex({ tenant, current_plan, plans, subscription, payment_method }) {
    const { auth } = usePage().props
    const { post, processing } = useForm()
    const [selectedPlan, setSelectedPlan] = useState(null)

    const handleSubscribe = (priceId) => {
        post('/app/billing/subscribe', {
            price_id: priceId,
        })
    }

    const handleUpdateSubscription = (priceId) => {
        post('/app/billing/update-subscription', {
            price_id: priceId,
        })
    }

    const handleCancel = () => {
        if (confirm('Are you sure you want to cancel your subscription? You will lose access to premium features at the end of your billing period.')) {
            post('/app/billing/cancel')
        }
    }

    const handleResume = () => {
        post('/app/billing/resume')
    }

    const formatLimit = (limit) => {
        if (limit === Number.MAX_SAFE_INTEGER || limit === 2147483647) {
            return 'Unlimited'
        }
        return limit
    }

    const currentPlanData = plans?.find((p) => p.id === current_plan)

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Billing & Plans</h1>
                    <p className="mt-2 text-sm text-gray-700">Manage your subscription and billing information</p>
                </div>

                {/* Current Plan Card */}
                {subscription && (
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900">Current Subscription</h3>
                            <div className="mt-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-medium text-gray-900">
                                            {currentPlanData?.name || current_plan}
                                        </p>
                                        <p className="text-sm text-gray-500">
                                            Status: {subscription.status}
                                            {subscription.on_grace_period && ' (Cancels at period end)'}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        {subscription.canceled && !subscription.on_grace_period && (
                                            <button
                                                type="button"
                                                onClick={handleResume}
                                                disabled={processing}
                                                className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                            >
                                                Resume
                                            </button>
                                        )}
                                        {!subscription.canceled && (
                                            <button
                                                type="button"
                                                onClick={handleCancel}
                                                disabled={processing}
                                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                            >
                                                Cancel Subscription
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Payment Method */}
                {payment_method && (
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900">Payment Method</h3>
                            <div className="mt-4">
                                <p className="text-sm text-gray-900">
                                    {payment_method.brand?.toUpperCase() || payment_method.type} •••• {payment_method.last_four}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Plans Grid */}
                <div className="mb-8">
                    <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">Available Plans</h3>
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        {plans?.map((plan) => (
                            <div
                                key={plan.id}
                                className={`overflow-hidden rounded-lg border shadow-sm ${
                                    plan.is_current
                                        ? 'border-indigo-600 ring-2 ring-indigo-600'
                                        : 'border-gray-200 bg-white'
                                }`}
                            >
                                <div className="p-6">
                                    <div className="flex items-center justify-between">
                                        <h4 className="text-lg font-semibold text-gray-900">{plan.name}</h4>
                                        {plan.is_current && (
                                            <span className="rounded-full bg-indigo-100 px-2 py-1 text-xs font-medium text-indigo-800">
                                                Current
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-4 space-y-2">
                                        <div className="text-sm text-gray-600">
                                            <span className="font-medium">Brands:</span> {formatLimit(plan.limits.max_brands)}
                                        </div>
                                        <div className="text-sm text-gray-600">
                                            <span className="font-medium">Categories:</span> {formatLimit(plan.limits.max_categories)}
                                        </div>
                                        <div className="text-sm text-gray-600">
                                            <span className="font-medium">Storage:</span>{' '}
                                            {plan.limits.max_storage_mb === 2147483647
                                                ? 'Unlimited'
                                                : `${plan.limits.max_storage_mb} MB`}
                                        </div>
                                        <div className="text-sm text-gray-600">
                                            <span className="font-medium">Upload Size:</span>{' '}
                                            {plan.limits.max_upload_size_mb === 2147483647
                                                ? 'Unlimited'
                                                : `${plan.limits.max_upload_size_mb} MB`}
                                        </div>
                                    </div>
                                    <div className="mt-6">
                                        {plan.is_current ? (
                                            <button
                                                type="button"
                                                disabled
                                                className="w-full rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400"
                                            >
                                                Current Plan
                                            </button>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (subscription) {
                                                        handleUpdateSubscription(plan.stripe_price_id)
                                                    } else {
                                                        handleSubscribe(plan.stripe_price_id)
                                                    }
                                                }}
                                                disabled={processing}
                                                className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                            >
                                                {subscription ? 'Switch to Plan' : 'Subscribe'}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Invoices Link */}
                <div className="overflow-hidden rounded-lg bg-white shadow">
                    <div className="px-4 py-5 sm:p-6">
                        <h3 className="text-lg font-medium leading-6 text-gray-900">Invoices</h3>
                        <div className="mt-4">
                            <Link
                                href="/app/billing/invoices"
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                View all invoices →
                            </Link>
                        </div>
                    </div>
                </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

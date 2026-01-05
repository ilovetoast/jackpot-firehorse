import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function AdminStripeStatus({ stripe_status, price_sync_status, tenants_with_stripe, subscriptions }) {
    const { auth } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Admin Dashboard
                        </Link>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Stripe Status</h1>
                        <p className="mt-2 text-sm text-gray-700">Monitor Stripe connection and subscription status</p>
                    </div>

                    {/* Stripe Connection Status */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Connection Status</h2>
                        </div>
                        <div className="px-6 py-5 space-y-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">Stripe API Connection</p>
                                    <p className="mt-1 text-sm text-gray-500">Status of the Stripe API integration</p>
                                    {stripe_status?.account_name && (
                                        <p className="mt-1 text-sm text-gray-600">Account: {stripe_status.account_name}</p>
                                    )}
                                    {stripe_status?.account_id && (
                                        <p className="mt-1 text-xs text-gray-500">Account ID: {stripe_status.account_id}</p>
                                    )}
                                </div>
                                <div className="flex items-center">
                                    <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${
                                        stripe_status?.connected 
                                            ? 'bg-green-100 text-green-800' 
                                            : 'bg-red-100 text-red-800'
                                    }`}>
                                        {stripe_status?.connected ? '✓ Connected' : '✗ Not Connected'}
                                    </span>
                                </div>
                            </div>
                            {stripe_status?.error && (
                                <div className="rounded-md bg-red-50 p-4">
                                    <p className="text-sm text-red-800">
                                        <strong>Error:</strong> {stripe_status.error}
                                    </p>
                                </div>
                            )}
                            {!stripe_status?.has_keys && (
                                <div className="rounded-md bg-yellow-50 p-4">
                                    <p className="text-sm text-yellow-800">
                                        <strong>Warning:</strong> Stripe keys (STRIPE_KEY and STRIPE_SECRET) are not configured in your environment.
                                    </p>
                                </div>
                            )}
                            {stripe_status?.last_check && (
                                <p className="text-xs text-gray-500">Last checked: {new Date(stripe_status.last_check).toLocaleString()}</p>
                            )}
                        </div>
                    </div>

                    {/* Price Sync Status */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Price & Plan Sync Status</h2>
                            <p className="mt-1 text-sm text-gray-500">Verify that plan prices exist in Stripe</p>
                        </div>
                        <div className="px-6 py-4">
                            <div className="space-y-4">
                                {price_sync_status && Object.keys(price_sync_status).length > 0 ? (
                                    Object.entries(price_sync_status).map(([planKey, planStatus]) => (
                                        <div key={planKey} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-sm font-semibold text-gray-900">{planStatus.name}</h3>
                                                    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                        planStatus.exists === true
                                                            ? 'bg-green-100 text-green-800'
                                                            : planStatus.exists === false
                                                            ? 'bg-red-100 text-red-800'
                                                            : 'bg-yellow-100 text-yellow-800'
                                                    }`}>
                                                        {planStatus.exists === true
                                                            ? '✓ Synced'
                                                            : planStatus.exists === false
                                                            ? '✗ Not Found'
                                                            : '? Unknown'}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-sm text-gray-500">
                                                    Price ID: <code className="text-xs bg-gray-100 px-1 py-0.5 rounded">{planStatus.price_id}</code>
                                                </p>
                                                {planStatus.stripe_price_name && (
                                                    <p className="mt-1 text-sm text-gray-600">
                                                        Stripe Name: {planStatus.stripe_price_name}
                                                        {planStatus.amount && planStatus.amount !== 'N/A' && (
                                                            <span className="ml-2">• {planStatus.amount} {planStatus.currency}</span>
                                                        )}
                                                    </p>
                                                )}
                                                {planStatus.active === false && (
                                                    <p className="mt-1 text-xs text-yellow-600">⚠ Price exists but is inactive in Stripe</p>
                                                )}
                                                {planStatus.note && (
                                                    <p className="mt-1 text-xs text-gray-500">{planStatus.note}</p>
                                                )}
                                                {planStatus.error && (
                                                    <p className="mt-1 text-xs text-red-600">{planStatus.error}</p>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-sm text-gray-500 text-center py-8">No plans configured</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Tenants with Stripe */}
                    <div className="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Companies with Stripe Accounts</h2>
                            <p className="mt-1 text-sm text-gray-500">Companies that have connected their Stripe accounts</p>
                        </div>
                        <div className="px-6 py-4">
                            <div className="space-y-4">
                                {tenants_with_stripe?.length === 0 ? (
                                    <p className="text-sm text-gray-500 text-center py-8">No companies have connected Stripe accounts</p>
                                ) : (
                                    tenants_with_stripe?.map((tenant) => (
                                        <div key={tenant.id} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                            <div>
                                                <h3 className="text-sm font-semibold text-gray-900">{tenant.name}</h3>
                                                <p className="mt-1 text-sm text-gray-500">
                                                    Stripe ID: {tenant.stripe_id || 'Not set'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                                    Connected
                                                </span>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Active Subscriptions */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Active Subscriptions</h2>
                            <p className="mt-1 text-sm text-gray-500">Current active subscriptions across all companies</p>
                        </div>
                        <div className="px-6 py-4">
                            <div className="space-y-4">
                                {subscriptions?.length === 0 ? (
                                    <p className="text-sm text-gray-500 text-center py-8">No active subscriptions</p>
                                ) : (
                                    subscriptions?.map((subscription) => (
                                        <div key={subscription.id} className="flex items-center justify-between py-4 border-b border-gray-200 last:border-0">
                                            <div>
                                                <h3 className="text-sm font-semibold text-gray-900">{subscription.tenant_name}</h3>
                                                <p className="mt-1 text-sm text-gray-500">
                                                    Plan: {subscription.stripe_price} • Status: {subscription.stripe_status}
                                                </p>
                                                {subscription.ends_at && (
                                                    <p className="mt-1 text-xs text-gray-400">Ends: {new Date(subscription.ends_at).toLocaleDateString()}</p>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                    subscription.stripe_status === 'active'
                                                        ? 'bg-green-100 text-green-800'
                                                        : subscription.stripe_status === 'canceled'
                                                        ? 'bg-red-100 text-red-800'
                                                        : 'bg-yellow-100 text-yellow-800'
                                                }`}>
                                                    {subscription.stripe_status}
                                                </span>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

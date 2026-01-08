import { Link, usePage, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function StripeManagement({ 
    stripe_status, 
    price_sync_status, 
    tenants_with_stripe, 
    subscriptions,
    subscription_stats,
    webhook_events
}) {
    const { auth } = usePage().props
    
    // Get tenant_id from URL query params
    const urlParams = new URLSearchParams(window.location.search)
    const tenantIdFromUrl = urlParams.get('tenant_id')
    
    const [activeTab, setActiveTab] = useState(tenantIdFromUrl ? 'customers' : 'overview')
    const [selectedTenant, setSelectedTenant] = useState(tenantIdFromUrl ? parseInt(tenantIdFromUrl) : null)
    const [showRefundModal, setShowRefundModal] = useState(false)
    const { data: refundData, setData: setRefundData, post: postRefund, processing: refundProcessing, errors: refundErrors } = useForm({
        tenant_id: '',
        invoice_id: '',
        amount: '',
        reason: '',
    })

    const tabs = [
        { id: 'overview', name: 'Overview', icon: '📊' },
        { id: 'customers', name: 'Customers', icon: '👥' },
        { id: 'subscriptions', name: 'Subscriptions', icon: '💳' },
        { id: 'webhooks', name: 'Webhooks', icon: '🔔' },
        { id: 'operations', name: 'Operations', icon: '⚙️' },
    ]

    const handleSyncSubscription = (tenantId) => {
        if (confirm('Sync subscription from Stripe? This will update the local database with the latest Stripe data.')) {
            router.post(`/app/admin/stripe/sync-subscription/${tenantId}`, {}, {
                preserveScroll: true,
                onSuccess: () => {
                    router.reload({ only: ['subscriptions', 'tenants_with_stripe'] })
                }
            })
        }
    }

    const handleRefund = (e) => {
        e.preventDefault()
        postRefund('/app/admin/stripe/refund', {
            preserveScroll: true,
            onSuccess: () => {
                setShowRefundModal(false)
                setRefundData({ tenant_id: '', invoice_id: '', amount: '', reason: '' })
            }
        })
    }

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
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Stripe Management</h1>
                        <p className="mt-2 text-sm text-gray-700">Comprehensive Stripe integration management and monitoring</p>
                    </div>

                    {/* Tabs */}
                    <div className="border-b border-gray-200 mb-6">
                        <nav className="-mb-px flex space-x-8">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`
                                        whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm
                                        ${activeTab === tab.id
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        }
                                    `}
                                >
                                    <span className="mr-2">{tab.icon}</span>
                                    {tab.name}
                                </button>
                            ))}
                        </nav>
                    </div>

                    {/* Overview Tab */}
                    {activeTab === 'overview' && (
                        <div className="space-y-6">
                            {/* Connection Status */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-5 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Connection Status</h2>
                                </div>
                                <div className="px-6 py-5 space-y-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">Stripe API Connection</p>
                                            {stripe_status?.account_name && (
                                                <p className="mt-1 text-sm text-gray-600">Account: {stripe_status.account_name}</p>
                                            )}
                                            {stripe_status?.account_id && (
                                                <p className="mt-1 text-xs text-gray-500">Account ID: {stripe_status.account_id}</p>
                                            )}
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ${
                                            stripe_status?.connected 
                                                ? 'bg-green-100 text-green-800' 
                                                : 'bg-red-100 text-red-800'
                                        }`}>
                                            {stripe_status?.connected ? '✓ Connected' : '✗ Not Connected'}
                                        </span>
                                    </div>
                                    {stripe_status?.error && (
                                        <div className="rounded-md bg-red-50 p-4">
                                            <p className="text-sm text-red-800"><strong>Error:</strong> {stripe_status.error}</p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Revenue Metrics */}
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                    <div className="p-6">
                                        <div className="flex items-center">
                                            <div className="flex-shrink-0">
                                                <span className="text-2xl">💰</span>
                                            </div>
                                            <div className="ml-4 w-0 flex-1">
                                                <dl>
                                                    <dt className="text-sm font-medium text-gray-500 truncate">Monthly Recurring Revenue</dt>
                                                    <dd className="text-2xl font-semibold text-gray-900">${subscription_stats?.mrr?.toLocaleString() || '0.00'}</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                    <div className="p-6">
                                        <div className="flex items-center">
                                            <div className="flex-shrink-0">
                                                <span className="text-2xl">✅</span>
                                            </div>
                                            <div className="ml-4 w-0 flex-1">
                                                <dl>
                                                    <dt className="text-sm font-medium text-gray-500 truncate">Active Subscriptions</dt>
                                                    <dd className="text-2xl font-semibold text-gray-900">{subscription_stats?.active || 0}</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                    <div className="p-6">
                                        <div className="flex items-center">
                                            <div className="flex-shrink-0">
                                                <span className="text-2xl">👥</span>
                                            </div>
                                            <div className="ml-4 w-0 flex-1">
                                                <dl>
                                                    <dt className="text-sm font-medium text-gray-500 truncate">Stripe Customers</dt>
                                                    <dd className="text-2xl font-semibold text-gray-900">{tenants_with_stripe?.length || 0}</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                    <div className="p-6">
                                        <div className="flex items-center">
                                            <div className="flex-shrink-0">
                                                <span className="text-2xl">📊</span>
                                            </div>
                                            <div className="ml-4 w-0 flex-1">
                                                <dl>
                                                    <dt className="text-sm font-medium text-gray-500 truncate">Total Subscriptions</dt>
                                                    <dd className="text-2xl font-semibold text-gray-900">{subscription_stats?.total || 0}</dd>
                                                </dl>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Price Sync Status */}
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-5 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Price & Plan Sync Status</h2>
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
                                                                {planStatus.exists === true ? '✓ Synced' : planStatus.exists === false ? '✗ Not Found' : '? Unknown'}
                                                            </span>
                                                        </div>
                                                        <p className="mt-1 text-sm text-gray-500">
                                                            Price ID: <code className="text-xs bg-gray-100 px-1 py-0.5 rounded">{planStatus.price_id}</code>
                                                        </p>
                                                        {planStatus.amount && planStatus.amount !== 'N/A' && (
                                                            <p className="mt-1 text-sm text-gray-600">
                                                                {planStatus.amount} {planStatus.currency}
                                                            </p>
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
                        </div>
                    )}

                    {/* Customers Tab */}
                    {activeTab === 'customers' && (
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Customers (Companies)</h2>
                                <p className="mt-1 text-sm text-gray-500">Manage companies with Stripe accounts</p>
                            </div>
                            <div className="px-6 py-4">
                                {tenants_with_stripe?.length === 0 ? (
                                    <p className="text-sm text-gray-500 text-center py-8">No companies have connected Stripe accounts</p>
                                ) : (
                                    <div className="space-y-4">
                                        {tenants_with_stripe?.map((tenant) => (
                                            <div 
                                                key={tenant.id} 
                                                className={`flex items-center justify-between py-4 border-b border-gray-200 last:border-0 ${
                                                    selectedTenant === tenant.id ? 'bg-indigo-50 ring-2 ring-indigo-500 rounded-lg px-4' : ''
                                                }`}
                                            >
                                                <div className="flex-1">
                                                    <h3 className="text-sm font-semibold text-gray-900">{tenant.name}</h3>
                                                    <div className="mt-2 space-y-1">
                                                        <p className="text-sm text-gray-500">
                                                            Stripe ID: <code className="text-xs bg-gray-100 px-1 py-0.5 rounded">{tenant.stripe_id}</code>
                                                        </p>
                                                        <p className="text-sm text-gray-500">
                                                            Current Plan: <span className="font-medium">{tenant.current_plan || 'Free'}</span>
                                                        </p>
                                                        {tenant.subscription_status && (
                                                            <p className="text-sm text-gray-500">
                                                                Status: <span className={`font-medium ${
                                                                    tenant.subscription_status === 'active' ? 'text-green-600' : 'text-gray-600'
                                                                }`}>{tenant.subscription_status}</span>
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        onClick={() => handleSyncSubscription(tenant.id)}
                                                        className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        Sync
                                                    </button>
                                                    <a
                                                        href={`https://dashboard.stripe.com/customers/${tenant.stripe_id}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                                    >
                                                        View in Stripe
                                                    </a>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Subscriptions Tab */}
                    {activeTab === 'subscriptions' && (
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Subscriptions</h2>
                                <p className="mt-1 text-sm text-gray-500">All subscriptions across all companies</p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Plan</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">MRR</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {subscriptions?.length === 0 ? (
                                            <tr>
                                                <td colSpan="6" className="px-6 py-8 text-center text-sm text-gray-500">No subscriptions found</td>
                                            </tr>
                                        ) : (
                                            subscriptions?.map((subscription) => (
                                                <tr key={subscription.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {subscription.tenant_name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {subscription.current_plan || subscription.stripe_price}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                            subscription.stripe_status === 'active'
                                                                ? 'bg-green-100 text-green-800'
                                                                : subscription.stripe_status === 'canceled'
                                                                ? 'bg-red-100 text-red-800'
                                                                : 'bg-yellow-100 text-yellow-800'
                                                        }`}>
                                                            {subscription.stripe_status}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        ${subscription.monthly_revenue?.toFixed(2) || '0.00'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {subscription.created_at ? new Date(subscription.created_at).toLocaleDateString() : 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <a
                                                            href={`https://dashboard.stripe.com/subscriptions/${subscription.stripe_id}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-indigo-600 hover:text-indigo-900"
                                                        >
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Webhooks Tab */}
                    {activeTab === 'webhooks' && (
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="px-6 py-5 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Recent Webhook Events</h2>
                                <p className="mt-1 text-sm text-gray-500">Last 50 webhook events from logs</p>
                            </div>
                            <div className="px-6 py-4">
                                {webhook_events?.length === 0 ? (
                                    <p className="text-sm text-gray-500 text-center py-8">No webhook events found in recent logs</p>
                                ) : (
                                    <div className="space-y-2">
                                        {webhook_events?.map((event, index) => (
                                            <div key={index} className="flex items-center justify-between py-3 border-b border-gray-200 last:border-0">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-3">
                                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                            event.status === 'success'
                                                                ? 'bg-green-100 text-green-800'
                                                                : 'bg-red-100 text-red-800'
                                                        }`}>
                                                            {event.status === 'success' ? '✓' : '✗'}
                                                        </span>
                                                        <span className="text-sm font-medium text-gray-900">{event.type}</span>
                                                        {event.id && (
                                                            <code className="text-xs bg-gray-100 px-1 py-0.5 rounded text-gray-600">{event.id}</code>
                                                        )}
                                                    </div>
                                                    {event.error && (
                                                        <p className="mt-1 text-xs text-red-600">{event.error}</p>
                                                    )}
                                                    <p className="mt-1 text-xs text-gray-500">{event.timestamp}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Operations Tab */}
                    {activeTab === 'operations' && (
                        <div className="space-y-6">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-5 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Manual Operations</h2>
                                    <p className="mt-1 text-sm text-gray-500">Perform manual Stripe operations</p>
                                </div>
                                <div className="px-6 py-5 space-y-6">
                                    {/* Refund Section */}
                                    <div>
                                        <h3 className="text-sm font-semibold text-gray-900 mb-4">Process Refund</h3>
                                        <form onSubmit={handleRefund} className="space-y-4">
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Company</label>
                                                <select
                                                    value={refundData.tenant_id}
                                                    onChange={(e) => setRefundData('tenant_id', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    required
                                                >
                                                    <option value="">Select a company</option>
                                                    {tenants_with_stripe?.map((tenant) => (
                                                        <option key={tenant.id} value={tenant.id}>{tenant.name}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Invoice ID</label>
                                                <input
                                                    type="text"
                                                    value={refundData.invoice_id}
                                                    onChange={(e) => setRefundData('invoice_id', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    placeholder="in_..."
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Amount (optional - leave empty for full refund)</label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0.01"
                                                    value={refundData.amount}
                                                    onChange={(e) => setRefundData('amount', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    placeholder="0.00"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700">Reason (optional)</label>
                                                <input
                                                    type="text"
                                                    value={refundData.reason}
                                                    onChange={(e) => setRefundData('reason', e.target.value)}
                                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    placeholder="Refund reason"
                                                />
                                            </div>
                                            {refundErrors.error && (
                                                <div className="rounded-md bg-red-50 p-4">
                                                    <p className="text-sm text-red-800">{refundErrors.error}</p>
                                                </div>
                                            )}
                                            <button
                                                type="submit"
                                                disabled={refundProcessing}
                                                className="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50"
                                            >
                                                {refundProcessing ? 'Processing...' : 'Process Refund'}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'

export default function BillingOverview({ tenant, current_plan, subscription, payment_method, recent_invoices, has_stripe_id }) {
    const { auth } = usePage().props

    const handleManageBilling = () => {
        router.visit('/app/billing/portal', {
            method: 'get',
            preserveState: false,
        })
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
        }
        return statusMap[status.toLowerCase()] || 'bg-gray-100 text-gray-800'
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppNav brand={auth.activeBrand} tenant={tenant} />
            <main>
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link href="/app/billing" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                            ← Back to Plans
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-gray-900">Billing & Invoices</h1>
                            <p className="mt-2 text-sm text-gray-700">View your subscription, usage, and billing history</p>
                        </div>
                    </div>

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
                                    <Link
                                        href="/app/billing"
                                        className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-900"
                                    >
                                        Manage Plan
                                        <svg className="ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </Link>
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
                                    {subscription.status === 'Incomplete' && (
                                        <p className="mt-2 text-sm text-yellow-600">
                                            ⚠ Your subscription payment is incomplete. Please update your payment method to continue service.
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

                    {/* Recent Charges / On-Demand Usage */}
                    <div className="mb-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-5 border-b border-gray-200">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">On-Demand Usage</h2>
                                    <p className="mt-1 text-sm text-gray-500">Additional charges beyond your plan</p>
                                </div>
                            </div>
                        </div>
                        <div className="px-6 py-5">
                            <div className="text-center py-8">
                                <p className="text-2xl font-semibold text-gray-900">$0.00</p>
                                <p className="mt-2 text-sm text-gray-500">No on-demand charges for this period</p>
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
                                                        {invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    {formatCurrency(invoice.amount, invoice.currency)}
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
            <AppFooter />
        </div>
    )
}

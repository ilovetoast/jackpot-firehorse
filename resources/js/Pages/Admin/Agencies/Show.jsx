import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    BuildingOffice2Icon as BuildingOfficeIcon,
    CheckCircleIcon,
    ClockIcon,
    TrophyIcon,
    ArrowLeftIcon,
    UserGroupIcon,
    ChartBarIcon,
    ShieldCheckIcon,
    XCircleIcon,
    LinkIcon,
} from '@heroicons/react/24/outline'

/**
 * Admin Agency Detail View
 * 
 * Phase AG-11 — Admin Agency Management & Oversight
 * 
 * Displays agency details with admin controls.
 */
export default function AdminAgencyShow({ agency, clients, referrals, access_grants, recent_activity, tiers }) {
    const [saving, setSaving] = useState(false)
    const [selectedTier, setSelectedTier] = useState(agency.tier?.id || '')

    const handleApprove = () => {
        if (!confirm('Approve this agency?')) return
        setSaving(true)
        router.post(`/app/admin/agencies/${agency.id}/approve`, {}, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    const handleRevokeApproval = () => {
        if (!confirm('Revoke agency approval? This will not affect existing rewards or tier.')) return
        setSaving(true)
        router.post(`/app/admin/agencies/${agency.id}/revoke-approval`, {}, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    const handleTierChange = (tierId) => {
        if (!confirm('Change agency tier? This is an admin override.')) return
        setSaving(true)
        router.put(`/app/admin/agencies/${agency.id}/tier`, { tier_id: tierId }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    const handleToggleAgencyStatus = () => {
        const action = agency.is_agency ? 'revoke agency status from' : 'grant agency status to'
        if (!confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} this company? ${agency.is_agency ? 'This will not delete existing rewards or history.' : ''}`)) return
        setSaving(true)
        router.post(`/app/admin/agencies/${agency.id}/toggle-status`, {}, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    const formatDate = (isoString) => {
        if (!isoString) return 'N/A'
        return new Date(isoString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        })
    }

    return (
        <>
            <AppNav />
            
            <div className="min-h-screen bg-gray-50 py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-6">
                        <Link 
                            href="/app/admin/agencies" 
                            className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-4"
                        >
                            <ArrowLeftIcon className="h-4 w-4 mr-1" />
                            Back to Agencies
                        </Link>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-4">
                                <div className="p-3 bg-indigo-100 rounded-lg">
                                    <BuildingOfficeIcon className="h-10 w-10 text-indigo-600" />
                                </div>
                                <div>
                                    <h1 className="text-3xl font-bold text-gray-900">{agency.name}</h1>
                                    <p className="text-sm text-gray-500">{agency.slug}</p>
                                </div>
                            </div>
                            <div className="flex items-center space-x-3">
                                {agency.is_approved ? (
                                    <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                        <ShieldCheckIcon className="h-4 w-4 mr-1" />
                                        Approved
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                        <ClockIcon className="h-4 w-4 mr-1" />
                                        Pending Approval
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Agency Summary Card */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            {/* Tier */}
                            <div>
                                <h3 className="text-sm font-medium text-gray-500">Current Tier</h3>
                                <div className="mt-1 flex items-center">
                                    <TrophyIcon className="h-5 w-5 text-indigo-500 mr-2" />
                                    <span className="text-lg font-semibold text-gray-900">
                                        {agency.tier?.name || 'None'}
                                    </span>
                                </div>
                                {agency.tier?.reward_percentage && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        {agency.tier.reward_percentage}% reward rate
                                    </p>
                                )}
                            </div>

                            {/* Activated Clients */}
                            <div>
                                <h3 className="text-sm font-medium text-gray-500">Activated Clients</h3>
                                <p className="mt-1 text-lg font-semibold text-gray-900 flex items-center">
                                    <CheckCircleIcon className="h-5 w-5 text-green-500 mr-2" />
                                    {agency.activated_client_count}
                                </p>
                                {agency.next_tier && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        {agency.next_tier.threshold - agency.activated_client_count} to {agency.next_tier.name}
                                    </p>
                                )}
                            </div>

                            {/* Approved By */}
                            <div>
                                <h3 className="text-sm font-medium text-gray-500">Approved</h3>
                                <p className="mt-1 text-lg font-semibold text-gray-900">
                                    {agency.is_approved ? formatDate(agency.approved_at) : 'Not approved'}
                                </p>
                            </div>

                            {/* Created */}
                            <div>
                                <h3 className="text-sm font-medium text-gray-500">Created</h3>
                                <p className="mt-1 text-lg font-semibold text-gray-900">
                                    {agency.created_at}
                                </p>
                            </div>
                        </div>

                        {/* Tier Progress */}
                        {agency.tier && agency.next_tier && (
                            <div className="mt-6 pt-6 border-t border-gray-200">
                                <div className="flex items-center justify-between mb-2">
                                    <p className="text-sm font-medium text-gray-700">
                                        Progress to {agency.next_tier.name}
                                    </p>
                                    <p className="text-sm text-gray-600">
                                        {agency.activated_client_count} / {agency.next_tier.threshold}
                                    </p>
                                </div>
                                <div className="w-full bg-gray-200 rounded-full h-2">
                                    <div 
                                        className="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                                        style={{ 
                                            width: `${Math.min((agency.activated_client_count / agency.next_tier.threshold) * 100, 100)}%` 
                                        }}
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Admin Controls */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Admin Controls</h2>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {/* Approval Control */}
                            <div className="p-4 bg-gray-50 rounded-lg">
                                <h3 className="text-sm font-medium text-gray-700 mb-2">Approval Status</h3>
                                {agency.is_approved ? (
                                    <button
                                        onClick={handleRevokeApproval}
                                        disabled={saving}
                                        className="inline-flex items-center px-4 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50"
                                    >
                                        <XCircleIcon className="h-4 w-4 mr-2" />
                                        Revoke Approval
                                    </button>
                                ) : (
                                    <button
                                        onClick={handleApprove}
                                        disabled={saving}
                                        className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
                                    >
                                        <ShieldCheckIcon className="h-4 w-4 mr-2" />
                                        Approve Agency
                                    </button>
                                )}
                            </div>

                            {/* Tier Change */}
                            <div className="p-4 bg-gray-50 rounded-lg">
                                <h3 className="text-sm font-medium text-gray-700 mb-2">Change Tier</h3>
                                <select
                                    value={selectedTier}
                                    onChange={(e) => {
                                        setSelectedTier(e.target.value)
                                        if (e.target.value && e.target.value !== String(agency.tier?.id)) {
                                            handleTierChange(e.target.value)
                                        }
                                    }}
                                    disabled={saving}
                                    className="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                >
                                    <option value="">Select tier...</option>
                                    {tiers.map((tier) => (
                                        <option key={tier.id} value={tier.id}>
                                            {tier.name} (threshold: {tier.activation_threshold ?? 'N/A'})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Agency Status */}
                            <div className="p-4 bg-gray-50 rounded-lg">
                                <h3 className="text-sm font-medium text-gray-700 mb-2">Agency Status</h3>
                                <button
                                    onClick={handleToggleAgencyStatus}
                                    disabled={saving}
                                    className={`inline-flex items-center px-4 py-2 border text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 ${
                                        agency.is_agency
                                            ? 'border-red-300 text-red-700 bg-white hover:bg-red-50 focus:ring-red-500'
                                            : 'border-green-300 text-green-700 bg-white hover:bg-green-50 focus:ring-green-500'
                                    }`}
                                >
                                    {agency.is_agency ? 'Revoke Agency Status' : 'Grant Agency Status'}
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <CheckCircleIcon className="h-6 w-6 text-green-500" />
                                <h3 className="text-sm font-medium text-gray-600">Activated Clients</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{clients.activated.length}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <BuildingOfficeIcon className="h-6 w-6 text-gray-400" />
                                <h3 className="text-sm font-medium text-gray-600">Incubated Clients</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{clients.incubated.length}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <ClockIcon className="h-6 w-6 text-yellow-500" />
                                <h3 className="text-sm font-medium text-gray-600">Pending Transfers</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{clients.pending_transfers.length}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <LinkIcon className="h-6 w-6 text-purple-500" />
                                <h3 className="text-sm font-medium text-gray-600">Referrals</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{referrals.length}</p>
                        </div>
                    </div>

                    {/* Clients Section */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        {/* Activated Clients */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900 flex items-center">
                                    <CheckCircleIcon className="h-5 w-5 text-green-500 mr-2" />
                                    Activated Clients
                                </h3>
                            </div>
                            <div className="p-6 max-h-96 overflow-y-auto">
                                {clients.activated.length === 0 ? (
                                    <p className="text-gray-500 text-center py-4">No activated clients</p>
                                ) : (
                                    <div className="space-y-3">
                                        {clients.activated.map((client) => (
                                            <div key={client.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                                <div>
                                                    <p className="font-medium text-gray-900">{client.name}</p>
                                                    <p className="text-xs text-gray-500">{client.activated_at_human}</p>
                                                </div>
                                                <Link
                                                    href={`/app/admin/companies/${client.id}`}
                                                    className="text-indigo-600 hover:text-indigo-900 text-sm"
                                                >
                                                    View
                                                </Link>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Incubated Clients */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900 flex items-center">
                                    <BuildingOfficeIcon className="h-5 w-5 text-gray-400 mr-2" />
                                    Incubated Clients
                                </h3>
                            </div>
                            <div className="p-6 max-h-96 overflow-y-auto">
                                {clients.incubated.length === 0 ? (
                                    <p className="text-gray-500 text-center py-4">No incubated clients</p>
                                ) : (
                                    <div className="space-y-3">
                                        {clients.incubated.map((client) => (
                                            <div key={client.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                                <div>
                                                    <p className="font-medium text-gray-900">{client.name}</p>
                                                    <p className="text-xs text-gray-500">
                                                        Incubated: {formatDate(client.incubated_at)}
                                                    </p>
                                                </div>
                                                <Link
                                                    href={`/app/admin/companies/${client.id}`}
                                                    className="text-indigo-600 hover:text-indigo-900 text-sm"
                                                >
                                                    View
                                                </Link>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Partner Access Grants */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
                        <div className="p-6 border-b border-gray-200">
                            <h3 className="text-lg font-semibold text-gray-900 flex items-center">
                                <UserGroupIcon className="h-5 w-5 text-indigo-500 mr-2" />
                                Partner Access Grants
                            </h3>
                        </div>
                        <div className="p-6">
                            {access_grants.length === 0 ? (
                                <p className="text-gray-500 text-center py-4">No partner access grants</p>
                            ) : (
                                <div className="space-y-3">
                                    {access_grants.map((grant) => (
                                        <div key={grant.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                            <div>
                                                <p className="font-medium text-gray-900">
                                                    {grant.user_name} → {grant.client_name}
                                                </p>
                                                <p className="text-xs text-gray-500">
                                                    Granted: {formatDate(grant.granted_at)}
                                                    {grant.revoked_at && ` | Revoked: ${formatDate(grant.revoked_at)}`}
                                                </p>
                                            </div>
                                            <span className={`px-2 py-1 rounded text-xs font-medium ${
                                                grant.is_active 
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-gray-100 text-gray-600'
                                            }`}>
                                                {grant.is_active ? 'Active' : 'Revoked'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Recent Activity */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div className="p-6 border-b border-gray-200">
                            <h3 className="text-lg font-semibold text-gray-900 flex items-center">
                                <ChartBarIcon className="h-5 w-5 text-gray-400 mr-2" />
                                Recent Activity
                            </h3>
                        </div>
                        <div className="p-6">
                            {recent_activity.length === 0 ? (
                                <p className="text-gray-500 text-center py-4">No recent activity</p>
                            ) : (
                                <div className="space-y-3">
                                    {recent_activity.map((event) => (
                                        <div key={event.id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                            <div>
                                                <p className="font-medium text-gray-900">{event.event_type}</p>
                                                <p className="text-xs text-gray-500">{event.created_at_human}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <AppFooter />
        </>
    )
}

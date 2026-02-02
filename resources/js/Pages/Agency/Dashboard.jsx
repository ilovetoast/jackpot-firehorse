import { usePage } from '@inertiajs/react'
import { 
    TrophyIcon,
    BuildingOfficeIcon,
    CheckCircleIcon,
    ClockIcon,
    ChartBarIcon,
    ExclamationTriangleIcon,
    ArrowRightIcon,
    SparklesIcon,
    UserGroupIcon,
    LinkIcon,
} from '@heroicons/react/24/outline'
import AppFooter from '../../Components/AppFooter'
import AppNav from '../../Components/AppNav'

/**
 * Agency Dashboard
 * 
 * Phase AG-7 — Agency Dashboard & Credits Visibility
 * Phase AG-8 — UX Polish & Transfer Nudges (Non-Blocking)
 * Phase AG-10 — Partner Marketing & Referral Attribution (Foundational)
 * 
 * READ-ONLY dashboard for agency partners.
 * Displays tier status, activated clients, rewards ledger, client overview, and referrals.
 * 
 * AG-8: Added informational nudges for transfers and tier progression.
 * AG-10: Added referral tracking display (attribution only, no rewards).
 * 
 * NO mutation actions - all data is informational only.
 * NO enforcement - all nudges are soft messaging.
 */
export default function AgencyDashboard({ auth, tenant, agency, rewards = [], clients = {}, referrals = {} }) {
    const { incubated = [], activated = [], pending_transfers = [] } = clients
    const { total: totalReferrals = 0, activated: activatedReferrals = [], pending: pendingReferrals = [] } = referrals

    // Format currency (placeholder - not used yet)
    const formatCurrency = (value) => {
        if (!value) return '$0.00'
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(value)
    }

    // Format date
    const formatDate = (isoString) => {
        if (!isoString) return 'N/A'
        return new Date(isoString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        })
    }

    // Phase AG-8: Check if any incubated client is expiring soon
    const hasExpiringSoon = incubated.some(client => client.expiring_soon)

    return (
        <>
            <AppNav auth={auth} tenant={tenant} />
            
            <div className="min-h-screen bg-gray-50 py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold text-gray-900">Agency Partner Dashboard</h1>
                        <p className="mt-2 text-sm text-gray-600">
                            View your partner tier, activated clients, and rewards.
                        </p>
                    </div>

                    {/* Tier Status Card */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-4">
                                <div className="p-3 bg-indigo-100 rounded-lg">
                                    <TrophyIcon className="h-8 w-8 text-indigo-600" />
                                </div>
                                <div>
                                    <h2 className="text-2xl font-bold text-gray-900">
                                        {agency.tier.name} Partner
                                    </h2>
                                    <p className="text-sm text-gray-600">
                                        {agency.activated_client_count} activated client{agency.activated_client_count !== 1 ? 's' : ''}
                                    </p>
                                </div>
                            </div>
                            {agency.tier.reward_percentage && (
                                <div className="text-right">
                                    <p className="text-sm text-gray-600">Partner Reward Rate</p>
                                    <p className="text-2xl font-bold text-indigo-600">
                                        {agency.tier.reward_percentage}%
                                    </p>
                                    {/* Phase AG-8: Reward nudge */}
                                    <p className="text-xs text-gray-500 mt-1">
                                        Earn up to {agency.tier.reward_percentage}% in partner credits
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Tier Progress */}
                        {agency.next_tier && (
                            <div className="mt-6">
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
                                        style={{ width: `${agency.next_tier.progress_percentage}%` }}
                                    />
                                </div>
                                {/* Phase AG-8: Tier progress nudge */}
                                {agency.next_tier.activations_to_next_tier > 0 && (
                                    <p className="text-sm text-gray-600 mt-2">
                                        <SparklesIcon className="h-4 w-4 inline-block mr-1 text-indigo-500" />
                                        Complete {agency.next_tier.activations_to_next_tier} more client activation{agency.next_tier.activations_to_next_tier !== 1 ? 's' : ''} to reach {agency.next_tier.name}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Phase AG-8: No next tier - at max */}
                        {!agency.next_tier && agency.activated_client_count > 0 && (
                            <div className="mt-4 p-3 bg-indigo-50 rounded-lg">
                                <p className="text-sm text-indigo-700 flex items-center">
                                    <TrophyIcon className="h-4 w-4 mr-2" />
                                    You've reached the highest partner tier. Thank you for your partnership!
                                </p>
                            </div>
                        )}
                    </div>

                    {/* Stats Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        {/* Incubated Clients */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <BuildingOfficeIcon className="h-6 w-6 text-gray-400" />
                                <h3 className="text-sm font-medium text-gray-600">Incubated Clients</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{incubated.length}</p>
                            {/* Phase AG-8: Incubated status messaging */}
                            <p className="text-xs text-gray-500 mt-2">
                                Prepared by your agency
                            </p>
                            {hasExpiringSoon && (
                                <p className="text-xs text-amber-600 mt-1 flex items-center">
                                    <ExclamationTriangleIcon className="h-3 w-3 mr-1" />
                                    Some windows ending soon
                                </p>
                            )}
                        </div>

                        {/* Activated Clients */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <CheckCircleIcon className="h-6 w-6 text-green-500" />
                                <h3 className="text-sm font-medium text-gray-600">Activated Clients</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{activated.length}</p>
                            {/* Phase AG-8: Activated status messaging */}
                            <p className="text-xs text-gray-500 mt-2">
                                Ownership transferred — partner rewards active
                            </p>
                        </div>

                        {/* Pending Transfers */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <ClockIcon className="h-6 w-6 text-yellow-500" />
                                <h3 className="text-sm font-medium text-gray-600">Pending Billing</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{pending_transfers.length}</p>
                            {/* Phase AG-8: Pending billing status messaging */}
                            {pending_transfers.length > 0 && (
                                <p className="text-xs text-amber-600 mt-2">
                                    Transfer completes once billing is activated
                                </p>
                            )}
                        </div>

                        {/* Phase AG-10: Referrals */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center space-x-3 mb-2">
                                <LinkIcon className="h-6 w-6 text-purple-500" />
                                <h3 className="text-sm font-medium text-gray-600">Referrals</h3>
                            </div>
                            <p className="text-3xl font-bold text-gray-900">{totalReferrals}</p>
                            <p className="text-xs text-gray-500 mt-2">
                                {activatedReferrals.length} activated, {pendingReferrals.length} pending
                            </p>
                        </div>
                    </div>

                    {/* Two Column Layout */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Rewards Ledger */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900">Partner Rewards Ledger</h3>
                                <p className="text-sm text-gray-600 mt-1">
                                    History of earned partner rewards
                                </p>
                            </div>
                            <div className="p-6">
                                {rewards.length === 0 ? (
                                    <div className="text-center py-8 text-gray-500">
                                        <ChartBarIcon className="h-12 w-12 mx-auto mb-3 text-gray-300" />
                                        <p>No partner rewards earned yet</p>
                                        {/* Phase AG-8: Improved empty state messaging */}
                                        <p className="text-sm mt-1">Transfer to client and activate billing to earn partner rewards</p>
                                        {agency.tier.reward_percentage && (
                                            <p className="text-xs text-indigo-600 mt-2">
                                                Earn up to {agency.tier.reward_percentage}% in partner credits
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        {rewards.map((reward) => (
                                            <div key={reward.id} className="flex items-center justify-between py-3 border-b border-gray-100 last:border-0">
                                                <div>
                                                    <p className="font-medium text-gray-900">{reward.client_name}</p>
                                                    <p className="text-sm text-gray-500">{reward.reward_type}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-sm text-gray-600">{reward.created_at_human}</p>
                                                </div>
                                            </div>
                                        ))}
                                        {/* Phase AG-8: Rewards summary */}
                                        <div className="pt-4 border-t border-gray-200">
                                            <p className="text-sm text-gray-600">
                                                Total partner rewards: {rewards.length}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Client Overview */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900">Client Overview</h3>
                                <p className="text-sm text-gray-600 mt-1">
                                    Incubated and activated clients
                                </p>
                            </div>
                            <div className="p-6">
                                {/* Activated Clients */}
                                <div className="mb-6">
                                    <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                                        <CheckCircleIcon className="h-4 w-4 text-green-500 mr-2" />
                                        Activated ({activated.length})
                                    </h4>
                                    {activated.length === 0 ? (
                                        <div className="ml-6">
                                            <p className="text-sm text-gray-500">No activated clients yet</p>
                                            <p className="text-xs text-gray-400 mt-1">Transfer to client to activate ownership</p>
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {activated.map((client) => (
                                                <div key={client.id} className="flex items-center justify-between py-2 pl-6 hover:bg-gray-50 rounded">
                                                    <div className="flex items-center space-x-2">
                                                        <p className="text-sm font-medium text-gray-900">{client.name}</p>
                                                        {/* Phase AG-8: Activated badge */}
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                            Activated
                                                        </span>
                                                    </div>
                                                    <p className="text-xs text-gray-500">{client.activated_at_human}</p>
                                                </div>
                                            ))}
                                            {/* Phase AG-8: Activated messaging */}
                                            <p className="text-xs text-gray-500 ml-6 mt-2">
                                                Client owns this company — partner rewards active
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* Pending Transfers */}
                                {pending_transfers.length > 0 && (
                                    <div className="mb-6">
                                        <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                                            <ClockIcon className="h-4 w-4 text-yellow-500 mr-2" />
                                            Pending Billing ({pending_transfers.length})
                                        </h4>
                                        <div className="space-y-2">
                                            {pending_transfers.map((transfer) => (
                                                <div key={transfer.id} className="flex items-center justify-between py-2 pl-6 hover:bg-gray-50 rounded">
                                                    <div className="flex items-center space-x-2">
                                                        <p className="text-sm font-medium text-gray-900">{transfer.client_name}</p>
                                                        {/* Phase AG-8: Pending billing badge */}
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                                            Pending Billing
                                                        </span>
                                                    </div>
                                                    <p className="text-xs text-gray-500">{transfer.accepted_at_human}</p>
                                                </div>
                                            ))}
                                            {/* Phase AG-8: Pending billing messaging */}
                                            <p className="text-xs text-amber-600 ml-6 mt-2">
                                                Ownership transfer will complete once billing is activated
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Incubated Clients */}
                                {incubated.length > 0 && (
                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                                            <BuildingOfficeIcon className="h-4 w-4 text-gray-400 mr-2" />
                                            Incubated ({incubated.length})
                                        </h4>
                                        <div className="space-y-2">
                                            {incubated.map((client) => (
                                                <div key={client.id} className="flex items-center justify-between py-2 pl-6 hover:bg-gray-50 rounded">
                                                    <div className="flex items-center space-x-2">
                                                        <p className="text-sm font-medium text-gray-900">{client.name}</p>
                                                        {/* Phase AG-8: Incubated badge */}
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                            Incubated
                                                        </span>
                                                        {/* Phase AG-8: Expiring soon warning */}
                                                        {client.expiring_soon && (
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">
                                                                <ExclamationTriangleIcon className="h-3 w-3 mr-1" />
                                                                {client.days_remaining} day{client.days_remaining !== 1 ? 's' : ''} left
                                                            </span>
                                                        )}
                                                    </div>
                                                    {client.incubated_at && (
                                                        <p className="text-xs text-gray-500">{formatDate(client.incubated_at)}</p>
                                                    )}
                                                </div>
                                            ))}
                                            {/* Phase AG-8: Incubated messaging */}
                                            <p className="text-xs text-gray-500 ml-6 mt-2">
                                                Prepared by your agency — transfer to activate ownership
                                            </p>
                                            {/* Phase AG-8: Expiring soon global warning */}
                                            {hasExpiringSoon && (
                                                <div className="ml-6 mt-3 p-2 bg-amber-50 border border-amber-200 rounded">
                                                    <p className="text-xs text-amber-700 flex items-center">
                                                        <ExclamationTriangleIcon className="h-4 w-4 mr-1 flex-shrink-0" />
                                                        Incubation window ending soon — transfer to activate
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Phase AG-8: Empty state messaging */}
                                {incubated.length === 0 && activated.length === 0 && pending_transfers.length === 0 && (
                                    <div className="text-center py-8 text-gray-500">
                                        <BuildingOfficeIcon className="h-12 w-12 mx-auto mb-3 text-gray-300" />
                                        <p>No clients yet</p>
                                        <p className="text-sm mt-1">Incubate a client company to get started</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Phase AG-10: Referrals Section */}
                    {totalReferrals > 0 && (
                        <div className="mt-6 bg-white rounded-lg shadow-sm border border-gray-200">
                            <div className="p-6 border-b border-gray-200">
                                <h3 className="text-lg font-semibold text-gray-900 flex items-center">
                                    <LinkIcon className="h-5 w-5 text-purple-500 mr-2" />
                                    Referrals
                                </h3>
                                <p className="text-sm text-gray-600 mt-1">
                                    Clients referred to the platform by your agency
                                </p>
                            </div>
                            <div className="p-6">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    {/* Activated Referrals */}
                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                                            <CheckCircleIcon className="h-4 w-4 text-green-500 mr-2" />
                                            Activated Referrals ({activatedReferrals.length})
                                        </h4>
                                        {activatedReferrals.length === 0 ? (
                                            <p className="text-sm text-gray-500 ml-6">No activated referrals yet</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {activatedReferrals.map((referral) => (
                                                    <div key={referral.id} className="flex items-center justify-between py-2 pl-6 hover:bg-gray-50 rounded">
                                                        <div className="flex items-center space-x-2">
                                                            <p className="text-sm font-medium text-gray-900">{referral.client_name}</p>
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                                Activated
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-gray-500">{referral.activated_at_human}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Pending Referrals */}
                                    <div>
                                        <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                                            <ClockIcon className="h-4 w-4 text-gray-400 mr-2" />
                                            Pending Referrals ({pendingReferrals.length})
                                        </h4>
                                        {pendingReferrals.length === 0 ? (
                                            <p className="text-sm text-gray-500 ml-6">No pending referrals</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {pendingReferrals.map((referral) => (
                                                    <div key={referral.id} className="flex items-center justify-between py-2 pl-6 hover:bg-gray-50 rounded">
                                                        <div className="flex items-center space-x-2">
                                                            <p className="text-sm font-medium text-gray-900">{referral.client_name}</p>
                                                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                                                Pending
                                                            </span>
                                                            {referral.source && (
                                                                <span className="text-xs text-gray-400">
                                                                    via {referral.source}
                                                                </span>
                                                            )}
                                                        </div>
                                                        <p className="text-xs text-gray-500">{referral.created_at_human}</p>
                                                    </div>
                                                ))}
                                                <p className="text-xs text-gray-500 ml-6 mt-2">
                                                    Referrals activate when clients add billing
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <AppFooter />
        </>
    )
}

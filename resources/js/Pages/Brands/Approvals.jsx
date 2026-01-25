import { useState, useEffect } from 'react'
import { router, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { CheckCircleIcon, XCircleIcon, ClockIcon } from '@heroicons/react/24/outline'

/**
 * Phase AF-1: Approval Queue Page
 * 
 * Lists pending assets that require approval.
 * Visible only to users with approval_capable roles (admin, brand_manager).
 */
export default function Approvals({ brand }) {
    const { auth } = usePage().props
    const [assets, setAssets] = useState([])
    const [loading, setLoading] = useState(true)
    const [rejecting, setRejecting] = useState(null)
    const [rejectionReason, setRejectionReason] = useState('')
    const [showRejectModal, setShowRejectModal] = useState(null)
    
    // Phase AF-5: Check if approvals are enabled for tenant plan
    const approvalsEnabled = auth?.approval_features?.approvals_enabled ?? false
    const requiredPlan = auth?.approval_features?.required_plan ?? 'Pro'

    useEffect(() => {
        if (brand?.id) {
            fetch(`/app/api/brands/${brand.id}/approvals`)
                .then(res => res.json())
                .then(data => {
                    setAssets(data.assets || [])
                    setLoading(false)
                })
                .catch(err => {
                    console.error('Failed to load approval queue:', err)
                    setLoading(false)
                })
        }
    }, [brand?.id])

    const handleApprove = (assetId) => {
        router.post(`/app/brands/${brand.id}/assets/${assetId}/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                // Remove approved asset from list
                setAssets(assets.filter(a => a.id !== assetId))
            },
        })
    }

    const handleReject = (assetId) => {
        if (!rejectionReason.trim()) {
            return
        }

        setRejecting(assetId)
        router.post(`/app/brands/${brand.id}/assets/${assetId}/reject`, {
            rejection_reason: rejectionReason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                // Remove rejected asset from list
                setAssets(assets.filter(a => a.id !== assetId))
                setShowRejectModal(null)
                setRejectionReason('')
                setRejecting(null)
            },
            onError: () => {
                setRejecting(null)
            },
        })
    }

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    // Phase AF-5: Show informational notice if approvals disabled
    if (!approvalsEnabled) {
        return (
            <div className="min-h-full flex flex-col">
                <AppNav brand={auth.activeBrand} tenant={auth.activeTenant} />
                <main className="flex-1 bg-gray-50">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                        <div className="bg-white shadow rounded-lg p-6">
                            <div className="text-center py-12">
                                <ClockIcon className="mx-auto h-12 w-12 text-gray-400" />
                                <h3 className="mt-2 text-sm font-semibold text-gray-900">Approval workflows not available</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    Approval workflows require {requiredPlan} plan or higher. Please upgrade your plan to access approval features.
                                </p>
                            </div>
                        </div>
                    </div>
                </main>
                <AppFooter />
            </div>
        )
    }

    return (
        <div className="min-h-full flex flex-col">
            <AppNav brand={auth.activeBrand} tenant={auth.activeTenant} />
            <main className="flex-1 bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Approval Queue</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            Assets pending approval from users with requires_approval flag
                        </p>
                    </div>

                    {loading ? (
                        <div className="text-center py-12 text-gray-500">Loading...</div>
                    ) : assets.length === 0 ? (
                        <div className="text-center py-12">
                            <ClockIcon className="mx-auto h-12 w-12 text-gray-400" />
                            <h3 className="mt-2 text-sm font-semibold text-gray-900">No pending approvals</h3>
                            <p className="mt-1 text-sm text-gray-500">All assets have been reviewed.</p>
                        </div>
                    ) : (
                        <div className="bg-white shadow rounded-lg overflow-hidden">
                            <ul className="divide-y divide-gray-200">
                                {assets.map((asset) => (
                                    <li key={asset.id} className={`p-6 ${asset.is_aged ? 'bg-amber-50 border-l-4 border-amber-400' : ''}`}>
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-3">
                                                    <h3 className="text-lg font-medium text-gray-900">
                                                        {asset.title || asset.original_filename || 'Untitled Asset'}
                                                    </h3>
                                                    <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-300">
                                                        Pending Approval
                                                    </span>
                                                    {/* Phase AF-4: Aging label */}
                                                    {asset.aging_label && (
                                                        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${
                                                            asset.is_aged 
                                                                ? 'bg-amber-100 text-amber-800 border border-amber-300' 
                                                                : 'bg-gray-100 text-gray-700 border border-gray-300'
                                                        }`}>
                                                            {asset.aging_label}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-2 text-sm text-gray-600">
                                                    <p>
                                                        <span className="font-medium">Uploaded by:</span>{' '}
                                                        {asset.uploader?.name || asset.uploader?.email || 'Unknown'}
                                                    </p>
                                                    <p className="mt-1">
                                                        <span className="font-medium">Uploaded:</span>{' '}
                                                        {formatDate(asset.created_at)}
                                                    </p>
                                                    {/* Phase AF-4: Pending since */}
                                                    {asset.pending_since && (
                                                        <p className="mt-1">
                                                            <span className="font-medium">Pending since:</span>{' '}
                                                            {formatDate(asset.pending_since)}
                                                            {asset.pending_days !== null && (
                                                                <span className="text-gray-500 ml-1">
                                                                    ({asset.pending_days} {asset.pending_days === 1 ? 'day' : 'days'})
                                                                </span>
                                                            )}
                                                        </p>
                                                    )}
                                                    {asset.mime_type && (
                                                        <p className="mt-1">
                                                            <span className="font-medium">Type:</span> {asset.mime_type}
                                                        </p>
                                                    )}
                                                    {asset.size_bytes && (
                                                        <p className="mt-1">
                                                            <span className="font-medium">Size:</span>{' '}
                                                            {(asset.size_bytes / 1024 / 1024).toFixed(2)} MB
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="ml-4 flex gap-2">
                                                <button
                                                    onClick={() => handleApprove(asset.id)}
                                                    className="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600"
                                                >
                                                    <CheckCircleIcon className="h-5 w-5 mr-1" />
                                                    Approve
                                                </button>
                                                <button
                                                    onClick={() => setShowRejectModal(asset.id)}
                                                    className="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                                >
                                                    <XCircleIcon className="h-5 w-5 mr-1" />
                                                    Reject
                                                </button>
                                            </div>
                                        </div>

                                        {/* Reject Modal */}
                                        {showRejectModal === asset.id && (
                                            <div className="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                                                <label className="block text-sm font-medium text-gray-900 mb-2">
                                                    Rejection Reason (required)
                                                </label>
                                                <textarea
                                                    value={rejectionReason}
                                                    onChange={(e) => setRejectionReason(e.target.value)}
                                                    rows={3}
                                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm"
                                                    placeholder="Please provide a reason for rejection..."
                                                />
                                                <div className="mt-3 flex gap-2">
                                                    <button
                                                        onClick={() => handleReject(asset.id)}
                                                        disabled={!rejectionReason.trim() || rejecting === asset.id}
                                                        className="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {rejecting === asset.id ? 'Rejecting...' : 'Confirm Rejection'}
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            setShowRejectModal(null)
                                                            setRejectionReason('')
                                                        }}
                                                        className="inline-flex items-center rounded-md bg-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-300"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}

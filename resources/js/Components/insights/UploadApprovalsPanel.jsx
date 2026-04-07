import { useState, useEffect, useCallback } from 'react'
import { router, usePage } from '@inertiajs/react'
import { CheckCircleIcon, XCircleIcon, ClockIcon } from '@heroicons/react/24/outline'

/**
 * Pending team or creator uploads (requires active brand + approvals on plan + approver role).
 * @param {{ queue?: 'team' | 'creator', onQueueChanged?: () => void }} props
 */
export default function UploadApprovalsPanel({ queue = 'team', onQueueChanged }) {
    const { auth } = usePage().props
    const brand = auth?.activeBrand
    const [assets, setAssets] = useState([])
    const [loading, setLoading] = useState(true)
    const [rejecting, setRejecting] = useState(null)
    const [rejectionReason, setRejectionReason] = useState('')
    const [showRejectModal, setShowRejectModal] = useState(null)

    const load = useCallback(() => {
        if (!brand?.id) {
            setLoading(false)
            return
        }
        const q = queue === 'creator' ? 'creator' : 'team'
        setLoading(true)
        fetch(`/app/api/brands/${brand.id}/approvals?queue=${q}`, { credentials: 'same-origin' })
            .then((res) => res.json())
            .then((data) => {
                setAssets(data.assets || [])
                setLoading(false)
            })
            .catch(() => setLoading(false))
    }, [brand?.id, queue])

    useEffect(() => {
        load()
    }, [load])

    const handleApprove = (assetId) => {
        if (!brand?.id) return
        router.post(`/app/brands/${brand.id}/assets/${assetId}/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setAssets((prev) => prev.filter((a) => a.id !== assetId))
                onQueueChanged?.()
            },
        })
    }

    const handleReject = (assetId) => {
        if (!brand?.id || !rejectionReason.trim()) return
        setRejecting(assetId)
        router.post(`/app/brands/${brand.id}/assets/${assetId}/reject`, {
            rejection_reason: rejectionReason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setAssets((prev) => prev.filter((a) => a.id !== assetId))
                setShowRejectModal(null)
                setRejectionReason('')
                setRejecting(null)
                onQueueChanged?.()
            },
            onError: () => setRejecting(null),
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

    if (!brand?.id) {
        return (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                Select a brand to load pending approvals.
            </div>
        )
    }

    if (loading) {
        return <div className="rounded-lg bg-white p-8 text-center text-gray-500 shadow">Loading…</div>
    }

    if (assets.length === 0) {
        return (
            <div className="rounded-lg bg-white p-8 text-center shadow">
                <ClockIcon className="mx-auto h-12 w-12 text-gray-400" />
                <h3 className="mt-2 text-sm font-semibold text-gray-900">No pending approvals</h3>
                <p className="mt-1 text-sm text-gray-500">All assets in this queue have been reviewed.</p>
            </div>
        )
    }

    return (
        <div className="overflow-hidden rounded-lg bg-white shadow">
            <ul className="divide-y divide-gray-200">
                {assets.map((asset) => (
                    <li
                        key={asset.id}
                        className={`p-6 ${asset.is_aged ? 'border-l-4 border-amber-400 bg-amber-50' : ''}`}
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-3">
                                    <h3 className="text-lg font-medium text-gray-900">
                                        {asset.title || asset.original_filename || 'Untitled Asset'}
                                    </h3>
                                    <span className="inline-flex items-center rounded-full border border-yellow-300 bg-yellow-100 px-2.5 py-1 text-xs font-medium text-yellow-700">
                                        Pending Approval
                                    </span>
                                    {asset.aging_label ? (
                                        <span
                                            className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium ${
                                                asset.is_aged
                                                    ? 'border-amber-300 bg-amber-100 text-amber-800'
                                                    : 'border-gray-300 bg-gray-100 text-gray-700'
                                            }`}
                                        >
                                            {asset.aging_label}
                                        </span>
                                    ) : null}
                                </div>
                                <div className="mt-2 text-sm text-gray-600">
                                    <p>
                                        <span className="font-medium">Uploaded by:</span>{' '}
                                        {asset.uploader?.name || asset.uploader?.email || 'Unknown'}
                                    </p>
                                    <p className="mt-1">
                                        <span className="font-medium">Uploaded:</span> {formatDate(asset.created_at)}
                                    </p>
                                    {asset.pending_since ? (
                                        <p className="mt-1">
                                            <span className="font-medium">Pending since:</span>{' '}
                                            {formatDate(asset.pending_since)}
                                            {asset.pending_days != null ? (
                                                <span className="ml-1 text-gray-500">
                                                    ({asset.pending_days}{' '}
                                                    {asset.pending_days === 1 ? 'day' : 'days'})
                                                </span>
                                            ) : null}
                                        </p>
                                    ) : null}
                                    {asset.mime_type ? (
                                        <p className="mt-1">
                                            <span className="font-medium">Type:</span> {asset.mime_type}
                                        </p>
                                    ) : null}
                                    {asset.size_bytes ? (
                                        <p className="mt-1">
                                            <span className="font-medium">Size:</span>{' '}
                                            {(asset.size_bytes / 1024 / 1024).toFixed(2)} MB
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <button
                                    type="button"
                                    onClick={() => handleApprove(asset.id)}
                                    className="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600"
                                >
                                    <CheckCircleIcon className="mr-1 h-5 w-5" />
                                    Approve
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowRejectModal(asset.id)}
                                    className="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                >
                                    <XCircleIcon className="mr-1 h-5 w-5" />
                                    Reject
                                </button>
                            </div>
                        </div>

                        {showRejectModal === asset.id ? (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
                                <label className="mb-2 block text-sm font-medium text-gray-900">
                                    Rejection reason (required)
                                </label>
                                <textarea
                                    value={rejectionReason}
                                    onChange={(e) => setRejectionReason(e.target.value)}
                                    rows={3}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm"
                                    placeholder="Please provide a reason for rejection…"
                                />
                                <div className="mt-3 flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => handleReject(asset.id)}
                                        disabled={!rejectionReason.trim() || rejecting === asset.id}
                                        className="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {rejecting === asset.id ? 'Rejecting…' : 'Confirm rejection'}
                                    </button>
                                    <button
                                        type="button"
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
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    )
}

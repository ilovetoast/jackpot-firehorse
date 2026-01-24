/**
 * Metadata Candidate Review Component
 *
 * Phase B9: Displays metadata candidates that need human review.
 * Decision-based actions only (approve/reject/defer) - no inline editing.
 */

import { useState, useEffect } from 'react'
import {
    CheckIcon,
    XMarkIcon,
    ClockIcon,
    InformationCircleIcon,
} from '@heroicons/react/24/outline'

export default function MetadataCandidateReview({ assetId }) {
    const [reviewItems, setReviewItems] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [showConfirmApprove, setShowConfirmApprove] = useState(null)
    const [showConfirmReject, setShowConfirmReject] = useState(null)

    // Fetch reviewable candidates
    useEffect(() => {
        if (!assetId) return

        setLoading(true)
        fetch(`/app/assets/${assetId}/metadata/review`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setReviewItems(data.review_items || [])
                setLoading(false)
            })
            .catch((err) => {
                console.error('[MetadataCandidateReview] Failed to fetch review items', err)
                setLoading(false)
            })
    }, [assetId])

    // Refresh review items after actions
    const refreshReview = () => {
        if (!assetId) return
        fetch(`/app/assets/${assetId}/metadata/review`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setReviewItems(data.review_items || [])
                // Trigger refresh of approved metadata display
                window.dispatchEvent(new CustomEvent('metadata-updated'))
            })
            .catch((err) => {
                console.error('[MetadataCandidateReview] Failed to refresh review items', err)
            })
    }

    // Handle approve candidate
    const handleApprove = async (candidateId) => {
        if (processing.has(candidateId)) return

        setProcessing((prev) => new Set(prev).add(candidateId))
        setShowConfirmApprove(null)

        try {
            const response = await fetch(`/app/metadata/candidates/${candidateId}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to approve candidate')
            }

            // Refresh review items and approved metadata
            refreshReview()
        } catch (error) {
            console.error('[MetadataCandidateReview] Failed to approve', error)
            alert(error.message || 'Failed to approve candidate')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(candidateId)
                return next
            })
        }
    }

    // Handle reject candidate
    const handleReject = async (candidateId) => {
        if (processing.has(candidateId)) return

        setProcessing((prev) => new Set(prev).add(candidateId))
        setShowConfirmReject(null)

        try {
            const response = await fetch(`/app/metadata/candidates/${candidateId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to reject candidate')
            }

            // Refresh review items
            refreshReview()
        } catch (error) {
            console.error('[MetadataCandidateReview] Failed to reject', error)
            alert(error.message || 'Failed to reject candidate')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(candidateId)
                return next
            })
        }
    }

    // Handle defer candidate
    const handleDefer = async (candidateId) => {
        if (processing.has(candidateId)) return

        setProcessing((prev) => new Set(prev).add(candidateId))

        try {
            const response = await fetch(`/app/metadata/candidates/${candidateId}/defer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to defer candidate')
            }

            // No refresh needed for defer (no state change)
        } catch (error) {
            console.error('[MetadataCandidateReview] Failed to defer', error)
            alert(error.message || 'Failed to defer candidate')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(candidateId)
                return next
            })
        }
    }

    // Get label for a value from options
    const getLabelForValue = (options, value) => {
        if (!options || !Array.isArray(options)) {
            return null
        }
        
        const option = options.find(opt => opt.value === value || opt.value === String(value))
        return option?.display_label || null
    }

    // Format value for display (with label lookup for select/multiselect)
    const formatValue = (fieldType, value, options = []) => {
        if (value === null || value === undefined || value === '') {
            return <span className="text-gray-400 italic">Not set</span>
        }

        if (fieldType === 'multiselect' && Array.isArray(value)) {
            // Look up labels for each value
            const labels = value.map(v => {
                const label = getLabelForValue(options, v)
                return label || String(v)
            })
            return labels.join(', ')
        }

        if (fieldType === 'select') {
            // Look up label for the value
            const label = getLabelForValue(options, value)
            return label || String(value)
        }

        if (fieldType === 'boolean') {
            return value ? 'Yes' : 'No'
        }

        if (fieldType === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch (e) {
                return value
            }
        }

        return String(value)
    }

    // Format producer badge
    const formatProducer = (producer) => {
        const badges = {
            ai: { label: 'AI', class: 'bg-purple-100 text-purple-800' },
            exif: { label: 'EXIF', class: 'bg-blue-100 text-blue-800' },
            system: { label: 'System', class: 'bg-gray-100 text-gray-800' },
            user: { label: 'User', class: 'bg-green-100 text-green-800' },
        }

        const badge = badges[producer] || { label: producer, class: 'bg-gray-100 text-gray-800' }
        return (
            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${badge.class}`}>
                {badge.label}
            </span>
        )
    }

    // Format confidence
    const formatConfidence = (confidence) => {
        if (confidence === null || confidence === undefined) return null
        return `${Math.round(confidence * 100)}%`
    }

    if (loading) {
        return (
            <div className="px-6 py-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">Loading metadata candidates for review...</div>
            </div>
        )
    }

    if (reviewItems.length === 0) {
        return null // Hide if no reviewable candidates
    }

    return (
        <>
            <div className="px-6 py-4 border-t border-gray-200">
                <h3 className="text-sm font-semibold text-gray-900 mb-4 flex items-center">
                    <InformationCircleIcon className="h-4 w-4 mr-2 text-blue-500" />
                    Metadata Candidate Review
                </h3>
                <p className="text-xs text-gray-500 mb-4">
                    Review and approve or reject AI metadata suggestions. Approved suggestions maintain their AI attribution.
                </p>
                <div className="space-y-6">
                    {reviewItems.map((item) => (
                        <div key={item.metadata_field_id} className="border border-blue-200 rounded-lg p-4 bg-blue-50">
                            <div className="mb-3">
                                <dt className="text-sm font-medium text-gray-900 mb-2">
                                    {item.field_label}
                                </dt>
                                
                                {/* Current Resolved Value */}
                                {item.current_resolved_value !== null && (
                                    <div className="mb-3 p-2 bg-white rounded border border-gray-200">
                                        <div className="text-xs text-gray-500 mb-1">Current Value:</div>
                                        <div className="text-sm text-gray-900">
                                            {formatValue(item.field_type, item.current_resolved_value, item.options || [])}
                                        </div>
                                        {item.current_resolved_producer && (
                                            <div className="mt-1 flex items-center gap-2">
                                                {formatProducer(item.current_resolved_producer)}
                                                {item.current_resolved_confidence && (
                                                    <span className="text-xs text-gray-500">
                                                        {formatConfidence(item.current_resolved_confidence)} confidence
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Candidates */}
                                <div className="space-y-2">
                                    <div className="text-xs text-gray-500 mb-1">Candidates for Review:</div>
                                    {item.candidates.map((candidate) => (
                                        <div
                                            key={candidate.id}
                                            className="p-3 bg-white rounded border border-gray-200"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="flex-1">
                                                    <div className="text-sm font-medium text-gray-900 mb-1">
                                                        {formatValue(item.field_type, candidate.value, item.options || [])}
                                                    </div>
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        {formatProducer(candidate.producer)}
                                                        {candidate.confidence && (
                                                            <span className="text-xs text-gray-500">
                                                                {formatConfidence(candidate.confidence)} confidence
                                                            </span>
                                                        )}
                                                        <span className="text-xs text-gray-400">
                                                            Source: {candidate.source}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 ml-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowConfirmApprove(candidate.id)}
                                                        disabled={processing.has(candidate.id)}
                                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <CheckIcon className="h-3 w-3 mr-1" />
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowConfirmReject(candidate.id)}
                                                        disabled={processing.has(candidate.id)}
                                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <XMarkIcon className="h-3 w-3 mr-1" />
                                                        Reject
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleDefer(candidate.id)}
                                                        disabled={processing.has(candidate.id)}
                                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <ClockIcon className="h-3 w-3 mr-1" />
                                                        Defer
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Confirm Approve Modal */}
            {showConfirmApprove && (
                <ConfirmModal
                    title="Approve Candidate"
                    message="This AI suggestion will be approved and applied to the asset, maintaining its AI attribution and confidence score."
                    confirmText="Approve"
                    confirmClass="bg-green-600 hover:bg-green-700"
                    onConfirm={() => handleApprove(showConfirmApprove)}
                    onCancel={() => setShowConfirmApprove(null)}
                    processing={processing.has(showConfirmApprove)}
                />
            )}

            {/* Confirm Reject Modal */}
            {showConfirmReject && (
                <ConfirmModal
                    title="Reject Candidate"
                    message="This candidate will be dismissed and excluded from future resolution. The candidate will be preserved for audit history."
                    confirmText="Reject"
                    confirmClass="bg-red-600 hover:bg-red-700"
                    onConfirm={() => handleReject(showConfirmReject)}
                    onCancel={() => setShowConfirmReject(null)}
                    processing={processing.has(showConfirmReject)}
                />
            )}
        </>
    )
}

// Confirm Modal Component
function ConfirmModal({ title, message, confirmText, confirmClass, onConfirm, onCancel, processing }) {
    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-black/50 z-50 transition-opacity"
                onClick={onCancel}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                className="fixed inset-0 z-50 flex items-center justify-center p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">{title}</h3>
                        <p className="text-sm text-gray-600 mb-6">{message}</p>

                        <div className="flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={onCancel}
                                disabled={processing}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={onConfirm}
                                disabled={processing}
                                className={`px-4 py-2 text-sm font-medium text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 ${confirmClass}`}
                            >
                                {processing ? 'Processing...' : confirmText}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </>
    )
}

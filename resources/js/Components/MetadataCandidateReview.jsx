/**
 * Metadata Candidate Review Component
 *
 * Phase B9: Displays metadata candidates that need human review.
 * Approve / reject / defer, plus inline pickers for select fields so approvers
 * can choose another allowed option before approving (backed by POST body `value`).
 */

import { useState, useEffect } from 'react'
import {
    CheckIcon,
    XMarkIcon,
    ClockIcon,
    InformationCircleIcon,
} from '@heroicons/react/24/outline'
import { usePermission } from '../hooks/usePermission'
import { usePage } from '@inertiajs/react'

export default function MetadataCandidateReview({
    assetId,
    primaryColor,
    uploadedByUserId = null,
    /** When true: flat card style for Metadata Review collapsible (no outer title strip / border-t). */
    compactDrawerReview = false,
    /** Asset drawer: report loading / empty / content / hidden for coordinated empty states */
    onDrawerReviewSlotState = null,
}) {
    const [reviewItems, setReviewItems] = useState([])
    const [loading, setLoading] = useState(true)
    const [processing, setProcessing] = useState(new Set())
    const [showConfirmReject, setShowConfirmReject] = useState(null)
    /** @type {Record<string, string>} candidate id -> selected option `value` (select fields only) */
    const [selectOverrides, setSelectOverrides] = useState({})
    
    const { auth } = usePage().props
    const brandColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'

    const { can } = usePermission()
    const brandRole = (auth?.brand_role || '').toLowerCase()
    const isContributorBrandRole = brandRole === 'contributor'
    const isViewerBrandRole = brandRole === 'viewer'
    const isOwnUpload =
        uploadedByUserId != null &&
        auth?.user?.id != null &&
        String(uploadedByUserId) === String(auth.user.id)
    // Contributors only review AI metadata candidates on assets they uploaded; admin/brand_manager/agency use view-all.
    const canViewSuggestions = isViewerBrandRole
        ? false
        : isContributorBrandRole
            ? isOwnUpload && can('metadata.edit_post_upload')
            : can('metadata.suggestions.view') || (isOwnUpload && can('metadata.edit_post_upload'))
    const canApplySuggestions = isViewerBrandRole
        ? false
        : isContributorBrandRole
            ? isOwnUpload && can('metadata.edit_post_upload')
            : can('metadata.suggestions.apply') || (isOwnUpload && can('metadata.edit_post_upload'))

    // Fetch reviewable candidates
    useEffect(() => {
        if (!assetId || !canViewSuggestions) {
            setLoading(false)
            return
        }

        setLoading(true)
        let cancelled = false
        fetch(`/app/assets/${assetId}/metadata/review`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`)
                }
                return res.json()
            })
            .then((data) => {
                if (cancelled) return
                setReviewItems(data.review_items || [])
                setLoading(false)
            })
            .catch((err) => {
                if (cancelled) return
                console.error('[MetadataCandidateReview] Failed to fetch review items', err)
                setLoading(false)
            })

        return () => {
            cancelled = true
        }
    }, [assetId, canViewSuggestions])

    useEffect(() => {
        if (typeof onDrawerReviewSlotState !== 'function') {
            return
        }
        if (!canViewSuggestions) {
            onDrawerReviewSlotState('hidden')
            return
        }
        if (loading) {
            onDrawerReviewSlotState('loading')
            return
        }
        onDrawerReviewSlotState(reviewItems.length > 0 ? 'content' : 'empty')
    }, [onDrawerReviewSlotState, canViewSuggestions, loading, reviewItems.length])

    // Refresh review items after actions
    const refreshReview = () => {
        if (!assetId || !canViewSuggestions) return
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

    const getSelectValueForCandidate = (fieldType, candidate) => {
        if (fieldType !== 'select') return null
        if (Object.prototype.hasOwnProperty.call(selectOverrides, candidate.id)) {
            return selectOverrides[candidate.id]
        }
        return candidate.value
    }

    const selectValueDiffersFromCandidate = (fieldType, candidate) => {
        if (fieldType !== 'select') return false
        const v = getSelectValueForCandidate(fieldType, candidate)
        const a = v === null || v === undefined ? '' : String(v)
        const b =
            candidate.value === null || candidate.value === undefined
                ? ''
                : String(candidate.value)
        return a !== b
    }

    // Handle approve candidate (DB row) or ephemeral Jackpot embedded suggestion (field_key)
    const handleApprove = async (candidate, item) => {
        const candidateId = candidate.id
        if (processing.has(candidateId)) return

        // Check permission before approving
        if (!canApplySuggestions) {
            alert('You do not have permission to approve metadata suggestions.')
            return
        }

        setProcessing((prev) => new Set(prev).add(candidateId))

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            if (!csrfToken) {
                throw new Error('CSRF token not found. Please refresh the page.')
            }

            if (candidate.ephemeral && candidate.field_key) {
                const payload = { _token: csrfToken }
                const fieldType = item?.field_type
                if (fieldType === 'select' && selectValueDiffersFromCandidate(fieldType, candidate)) {
                    payload.value = getSelectValueForCandidate(fieldType, candidate)
                }
                const response = await fetch(
                    `/app/assets/${assetId}/metadata/suggestions/${encodeURIComponent(candidate.field_key)}/accept`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload),
                    }
                )
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}))
                    throw new Error(data.message || 'Failed to accept suggestion')
                }
                refreshReview()
                return
            }

            const approveBody = { _token: csrfToken }
            const fieldType = item?.field_type
            if (fieldType === 'select' && selectValueDiffersFromCandidate(fieldType, candidate)) {
                approveBody.value = getSelectValueForCandidate(fieldType, candidate)
            }

            const response = await fetch(`/app/metadata/candidates/${candidateId}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify(approveBody),
            })

            if (!response.ok) {
                // Handle 419 CSRF token mismatch
                if (response.status === 419) {
                    throw new Error('Session expired. Please refresh the page and try again.')
                }
                
                let data
                try {
                    data = await response.json()
                } catch (e) {
                    // Response might not be JSON
                    throw new Error(`Failed to approve candidate (${response.status})`)
                }
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

    const handleReject = async (candidate) => {
        const candidateId = candidate.id
        if (processing.has(candidateId)) return

        // Check permission before rejecting
        if (!canApplySuggestions) {
            alert('You do not have permission to reject metadata suggestions.')
            return
        }

        setProcessing((prev) => new Set(prev).add(candidateId))
        setShowConfirmReject(null)

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            if (!csrfToken) {
                throw new Error('CSRF token not found. Please refresh the page.')
            }

            if (candidate.ephemeral && candidate.field_key) {
                const response = await fetch(
                    `/app/assets/${assetId}/metadata/suggestions/${encodeURIComponent(candidate.field_key)}/dismiss`,
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ _token: csrfToken }),
                    }
                )
                if (!response.ok) {
                    const data = await response.json().catch(() => ({}))
                    throw new Error(data.message || 'Failed to dismiss suggestion')
                }
                refreshReview()
                return
            }

            const response = await fetch(`/app/metadata/candidates/${candidateId}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    _token: csrfToken,
                }),
            })

            if (!response.ok) {
                // Handle 419 CSRF token mismatch
                if (response.status === 419) {
                    throw new Error('Session expired. Please refresh the page and try again.')
                }
                
                let data
                try {
                    data = await response.json()
                } catch (e) {
                    throw new Error(`Failed to reject candidate (${response.status})`)
                }
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

    const handleDefer = async (candidate) => {
        const candidateId = candidate.id
        if (candidate.ephemeral) {
            return
        }
        if (processing.has(candidateId)) return
        if (!canViewSuggestions) return

        setProcessing((prev) => new Set(prev).add(candidateId))

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            if (!csrfToken) {
                throw new Error('CSRF token not found. Please refresh the page.')
            }

            const response = await fetch(`/app/metadata/candidates/${candidateId}/defer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    _token: csrfToken,
                }),
            })

            if (!response.ok) {
                // Handle 419 CSRF token mismatch
                if (response.status === 419) {
                    throw new Error('Session expired. Please refresh the page and try again.')
                }
                
                let data
                try {
                    data = await response.json()
                } catch (e) {
                    throw new Error(`Failed to defer candidate (${response.status})`)
                }
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
            ai: { label: 'AI', class: 'bg-gray-100 text-gray-800' },
            exif: { label: 'EXIF', class: 'bg-blue-100 text-blue-800' },
            system: { label: 'System', class: 'bg-gray-100 text-gray-800' },
            user: { label: 'User', class: 'bg-green-100 text-green-800' },
            jackpot_embedded: { label: 'Embedded', class: 'bg-teal-100 text-teal-900' },
        }

        const badge = badges[producer] || { label: producer, class: 'bg-gray-100 text-gray-800' }
        return (
            <span className={`inline-flex items-center rounded px-1.5 py-0 text-[10px] font-medium ${badge.class}`}>
                {badge.label}
            </span>
        )
    }

    // Format confidence (self-reported by the model in the tagging pipeline — not calibrated)
    const formatConfidence = (confidence) => {
        if (confidence === null || confidence === undefined) return null
        return `${Math.round(confidence * 100)}%`
    }

    // Don't render at all if user doesn't have permission to view suggestions
    if (!canViewSuggestions) {
        return null
    }

    if (loading) {
        return (
            <div className={compactDrawerReview ? '' : 'px-4 py-3 border-t border-gray-200'}>
                <div className="text-xs text-gray-500">Loading metadata candidates for review...</div>
            </div>
        )
    }

    if (reviewItems.length === 0) {
        return null // Hide if no reviewable candidates
    }

    const list = (
        <div className="space-y-2">
            {reviewItems.map((item) => (
                <div
                    key={item.metadata_field_id}
                    className={
                        compactDrawerReview
                            ? 'rounded-md border border-gray-200 bg-white p-2'
                            : 'rounded-md border border-gray-200 bg-white p-2'
                    }
                >
                            <div>
                                <dt className="text-xs font-medium text-gray-900 mb-1">
                                    {item.field_label}
                                </dt>

                                {/* Current Resolved Value */}
                                {item.current_resolved_value !== null && (
                                    <div className="mb-1.5 rounded border border-gray-200 bg-white p-1.5">
                                        <div className="mb-0.5 text-[10px] text-gray-500">Current</div>
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span className="text-xs text-gray-900">
                                                {formatValue(item.field_type, item.current_resolved_value, item.options || [])}
                                            </span>
                                            {item.current_resolved_producer && (
                                                <>
                                                    {formatProducer(item.current_resolved_producer)}
                                                    {item.current_resolved_confidence && (
                                                        <span className="text-[10px] text-gray-500">
                                                            {formatConfidence(item.current_resolved_confidence)}
                                                        </span>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                )}

                                <div className="space-y-1.5">
                                    {item.candidates.map((candidate) => (
                                        <div
                                            key={candidate.id}
                                            className="rounded border border-gray-200 bg-white px-2 py-1.5"
                                        >
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <div className="min-w-0 flex-1 basis-[min(100%,12rem)]">
                                                    {item.field_type === 'select' &&
                                                    Array.isArray(item.options) &&
                                                    item.options.length > 0 ? (
                                                        <select
                                                            aria-label={`Suggested value for ${item.field_label}`}
                                                            className="w-full rounded-md border border-gray-300 bg-white px-1.5 py-1 text-xs text-gray-900"
                                                            value={
                                                                getSelectValueForCandidate(item.field_type, candidate) ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                setSelectOverrides((prev) => ({
                                                                    ...prev,
                                                                    [candidate.id]: e.target.value,
                                                                }))
                                                            }
                                                            disabled={processing.has(candidate.id)}
                                                        >
                                                            {(() => {
                                                                const optVals = new Set(
                                                                    item.options.map((o) => String(o.value))
                                                                )
                                                                const sug = candidate.value
                                                                const sugStr =
                                                                    sug === null || sug === undefined
                                                                        ? ''
                                                                        : String(sug)
                                                                const missing =
                                                                    sugStr !== '' && !optVals.has(sugStr)
                                                                return (
                                                                    <>
                                                                        {missing && (
                                                                            <option value={sugStr}>
                                                                                {formatValue(
                                                                                    item.field_type,
                                                                                    candidate.value,
                                                                                    item.options || []
                                                                                )}{' '}
                                                                                (suggested)
                                                                            </option>
                                                                        )}
                                                                        {item.options.map((opt) => (
                                                                            <option
                                                                                key={String(opt.value)}
                                                                                value={String(opt.value)}
                                                                            >
                                                                                {opt.display_label ??
                                                                                    opt.label ??
                                                                                    opt.value}
                                                                            </option>
                                                                        ))}
                                                                    </>
                                                                )
                                                            })()}
                                                        </select>
                                                    ) : (
                                                        <span className="text-xs font-medium text-gray-900">
                                                            {formatValue(
                                                                item.field_type,
                                                                candidate.value,
                                                                item.options || []
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                                {canApplySuggestions && (
                                                    <div className="flex shrink-0 items-center gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleApprove(candidate, item)}
                                                            disabled={processing.has(candidate.id)}
                                                            className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            <CheckIcon className="mr-0.5 h-2.5 w-2.5" />
                                                            Approve
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowConfirmReject(candidate)}
                                                            disabled={processing.has(candidate.id)}
                                                            className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-red-600 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            <XMarkIcon className="mr-0.5 h-2.5 w-2.5" />
                                                            Reject
                                                        </button>
                                                        {!candidate.ephemeral && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDefer(candidate)}
                                                                disabled={processing.has(candidate.id)}
                                                                className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                <ClockIcon className="mr-0.5 h-2.5 w-2.5" />
                                                                Defer
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                            <div className="mt-1 flex w-full flex-wrap items-center gap-x-2 gap-y-0 leading-tight text-[10px] text-gray-500">
                                                {formatProducer(candidate.producer)}
                                                {candidate.confidence != null && (
                                                    <span title="Model-reported confidence (not calibrated)">
                                                        ~{formatConfidence(candidate.confidence)} est.
                                                    </span>
                                                )}
                                                <span className="text-gray-400">Source: {candidate.source}</span>
                                                {candidate.evidence && (
                                                    <span
                                                        className="text-gray-400"
                                                        title="Detection basis"
                                                    >
                                                        · {String(candidate.evidence).replace(/_/g, ' ')}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                </div>
            ))}
        </div>
    )

    const modals = (
        <>
            {showConfirmReject && (
                <ConfirmModal
                    title={showConfirmReject.ephemeral ? 'Dismiss suggestion' : 'Reject Candidate'}
                    message={
                        showConfirmReject.ephemeral
                            ? 'This suggestion will be dismissed and will not be suggested again for this value.'
                            : 'This candidate will be dismissed and excluded from future resolution. The candidate will be preserved for audit history.'
                    }
                    confirmText={showConfirmReject.ephemeral ? 'Dismiss' : 'Reject'}
                    confirmClass="bg-red-600 hover:bg-red-700"
                    onConfirm={() => handleReject(showConfirmReject)}
                    onCancel={() => setShowConfirmReject(null)}
                    processing={processing.has(showConfirmReject.id)}
                />
            )}
        </>
    )

    if (compactDrawerReview) {
        return (
            <>
                {list}
                {modals}
            </>
        )
    }

    return (
        <>
            <div className="px-4 py-2 border-t border-gray-200">
                <h3 className="text-xs font-semibold text-gray-900 mb-1 flex items-center gap-1.5">
                    <InformationCircleIcon className="h-3.5 w-3.5 flex-shrink-0" style={{ color: brandColor }} />
                    Metadata Candidate Review
                </h3>
                <p className="text-[11px] text-gray-500 mb-2">
                    Review and approve or reject metadata suggestions (AI or embedded file metadata). Approved values are
                    written to the asset; source is recorded where applicable.
                </p>
                {list}
            </div>
            {modals}
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

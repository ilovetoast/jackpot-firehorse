/**
 * Pending AI Suggestions Modal Component
 *
 * Modal dialog that displays all pending AI tags and metadata suggestions
 * across all assets. Allows quick approve/deny actions.
 */

import { useState, useEffect } from 'react'
import { XMarkIcon, CheckIcon, SparklesIcon, TagIcon } from '@heroicons/react/24/outline'
import { usePage } from '@inertiajs/react'

export default function PendingAiSuggestionsModal({ isOpen, onClose }) {
    const { auth, tenant } = usePage().props
    const [loading, setLoading] = useState(true)
    const [items, setItems] = useState([])
    const [currentIndex, setCurrentIndex] = useState(0)
    const [processing, setProcessing] = useState(new Set())

    // Fetch all pending suggestions
    useEffect(() => {
        if (!isOpen) return

        setLoading(true)
        fetch('/app/api/pending-ai-suggestions', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((data) => {
                setItems(data.items || [])
                setCurrentIndex(0)
                setLoading(false)
            })
            .catch((err) => {
                console.error('[PendingAiSuggestionsModal] Failed to fetch pending suggestions', err)
                setLoading(false)
            })
    }, [isOpen])

    const currentItem = items[currentIndex] || null
    const hasMore = currentIndex < items.length - 1
    const hasPrevious = currentIndex > 0

    // Handle approve
    const handleApprove = async (item) => {
        if (processing.has(item.id)) return

        setProcessing((prev) => new Set(prev).add(item.id))

        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/accept`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/approve`
            } else if (item.type === 'pending_metadata') {
                url = `/app/metadata/${item.id}/approve`
            } else {
                throw new Error('Unknown item type')
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to approve')
            }

            // Remove item from list and adjust index
            const newItems = items.filter((i) => !(i.id === item.id && i.asset_id === item.asset_id && i.type === item.type))
            setItems(newItems)
            
            // Adjust current index if needed
            if (currentIndex >= newItems.length && newItems.length > 0) {
                setCurrentIndex(newItems.length - 1)
            } else if (newItems.length === 0) {
                onClose()
            }
        } catch (error) {
            console.error('[PendingAiSuggestionsModal] Failed to approve', error)
            alert(error.message || 'Failed to approve suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(item.id)
                return next
            })
        }
    }

    // Handle reject
    const handleReject = async (item) => {
        if (processing.has(item.id)) return

        setProcessing((prev) => new Set(prev).add(item.id))

        try {
            let url
            if (item.type === 'tag') {
                url = `/app/assets/${item.asset_id}/tags/suggestions/${item.id}/dismiss`
            } else if (item.type === 'metadata_candidate') {
                url = `/app/metadata/candidates/${item.id}/reject`
            } else if (item.type === 'pending_metadata') {
                url = `/app/metadata/${item.id}/reject`
            } else {
                throw new Error('Unknown item type')
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.message || 'Failed to reject')
            }

            // Remove item from list and adjust index
            const newItems = items.filter((i) => !(i.id === item.id && i.asset_id === item.asset_id && i.type === item.type))
            setItems(newItems)
            
            // Adjust current index if needed
            if (currentIndex >= newItems.length && newItems.length > 0) {
                setCurrentIndex(newItems.length - 1)
            } else if (newItems.length === 0) {
                onClose()
            }
        } catch (error) {
            console.error('[PendingAiSuggestionsModal] Failed to reject', error)
            alert(error.message || 'Failed to reject suggestion')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(item.id)
                return next
            })
        }
    }

    // Handle skip (move to next)
    const handleSkip = () => {
        if (hasMore) {
            setCurrentIndex(currentIndex + 1)
        } else {
            onClose()
        }
    }

    // Format value for display
    const formatValue = (item) => {
        if (item.type === 'tag') {
            return item.tag || item.value
        }
        if (item.field_type === 'select' && item.options) {
            const option = item.options.find((opt) => opt.value === item.value)
            return option?.display_label || item.value
        }
        if (item.field_type === 'boolean') {
            return item.value ? 'Yes' : 'No'
        }
        if (item.field_type === 'date') {
            try {
                return new Date(item.value).toLocaleDateString()
            } catch (e) {
                return item.value
            }
        }
        return String(item.value || '')
    }

    // Format confidence
    const formatConfidence = (confidence) => {
        if (confidence === null || confidence === undefined) return null
        return `${Math.round(confidence * 100)}%`
    }

    if (!isOpen) return null

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div className="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {/* Background overlay */}
                <div
                    className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                    onClick={onClose}
                ></div>

                {/* Center modal */}
                <span className="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true">
                    &#8203;
                </span>

                {/* Modal panel */}
                <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <div className="flex items-center">
                                <SparklesIcon className="h-6 w-6 text-amber-500 mr-2" />
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Pending AI Suggestions
                                </h3>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="text-gray-400 hover:text-gray-500 focus:outline-none"
                            >
                                <XMarkIcon className="h-6 w-6" />
                            </button>
                        </div>

                        {loading ? (
                            <div className="text-center py-8">
                                <div className="text-sm text-gray-500">Loading suggestions...</div>
                            </div>
                        ) : items.length === 0 ? (
                            <div className="text-center py-8">
                                <SparklesIcon className="h-12 w-12 text-gray-300 mx-auto mb-4" />
                                <p className="text-sm font-medium text-gray-900 mb-1">All caught up!</p>
                                <p className="text-sm text-gray-500">No pending AI suggestions to review.</p>
                            </div>
                        ) : currentItem ? (
                            <div className="space-y-4">
                                {/* Progress indicator */}
                                <div className="flex items-center justify-between text-xs text-gray-500 mb-4">
                                    <span>
                                        {currentIndex + 1} of {items.length}
                                    </span>
                                    <span>
                                        {Math.round(((currentIndex + 1) / items.length) * 100)}% complete
                                    </span>
                                </div>

                                {/* Current item */}
                                <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                    {/* Asset info with thumbnail */}
                                    <div className="mb-3 pb-3 border-b border-gray-200">
                                        <div className="flex items-start gap-3">
                                            {/* Thumbnail */}
                                            {currentItem.thumbnail_url ? (
                                                <div className="flex-shrink-0">
                                                    <img
                                                        src={currentItem.thumbnail_url}
                                                        alt={currentItem.asset_title || currentItem.asset_filename || 'Asset'}
                                                        className="w-16 h-16 object-cover rounded border border-gray-200"
                                                        onError={(e) => {
                                                            // Hide image if it fails to load
                                                            e.target.style.display = 'none'
                                                        }}
                                                    />
                                                </div>
                                            ) : (
                                                <div className="flex-shrink-0 w-16 h-16 bg-gray-200 rounded border border-gray-300 flex items-center justify-center">
                                                    <span className="text-xs text-gray-400">No image</span>
                                                </div>
                                            )}
                                            {/* Asset details */}
                                            <div className="flex-1 min-w-0">
                                                <div className="text-xs text-gray-500 mb-1">Asset</div>
                                                <div className="text-sm font-medium text-gray-900 truncate">
                                                    {currentItem.asset_title || currentItem.asset_filename || `Asset #${currentItem.asset_id?.substring(0, 8)}`}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Suggestion type and value */}
                                    <div className="mb-3">
                                        {currentItem.type === 'tag' ? (
                                            <>
                                                <div className="flex items-center gap-2 mb-2">
                                                    <TagIcon className="h-4 w-4 text-blue-500" />
                                                    <span className="text-xs font-medium text-gray-500">Tag Suggestion</span>
                                                </div>
                                                <div className="text-lg font-semibold text-gray-900">
                                                    {formatValue(currentItem)}
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="text-xs font-medium text-gray-500 mb-1">
                                                    {currentItem.field_label || currentItem.field_key || 'Metadata Field'}
                                                </div>
                                                <div className="text-lg font-semibold text-gray-900">
                                                    {formatValue(currentItem)}
                                                </div>
                                            </>
                                        )}
                                    </div>

                                    {/* Confidence and source */}
                                    {(currentItem.confidence || currentItem.source) && (
                                        <div className="flex items-center gap-3 text-xs text-gray-500 mb-4">
                                            {currentItem.confidence && (
                                                <span>Confidence: {formatConfidence(currentItem.confidence)}</span>
                                            )}
                                            {currentItem.source && (
                                                <span>Source: {currentItem.source}</span>
                                            )}
                                        </div>
                                    )}

                                    {/* Actions */}
                                    <div className="flex items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={() => handleApprove(currentItem)}
                                            disabled={processing.has(currentItem.id)}
                                            className="flex-1 inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <CheckIcon className="h-4 w-4 mr-2" />
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleReject(currentItem)}
                                            disabled={processing.has(currentItem.id)}
                                            className="flex-1 inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <XMarkIcon className="h-4 w-4 mr-2" />
                                            Deny
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleSkip}
                                            disabled={processing.has(currentItem.id)}
                                            className="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Skip
                                        </button>
                                    </div>
                                </div>

                                {/* Navigation */}
                                {items.length > 1 && (
                                    <div className="flex items-center justify-between text-sm">
                                        <button
                                            type="button"
                                            onClick={() => setCurrentIndex(Math.max(0, currentIndex - 1))}
                                            disabled={!hasPrevious}
                                            className="px-3 py-1 text-gray-600 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            ← Previous
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setCurrentIndex(Math.min(items.length - 1, currentIndex + 1))}
                                            disabled={!hasMore}
                                            className="px-3 py-1 text-gray-600 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Next →
                                        </button>
                                    </div>
                                )}
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    )
}

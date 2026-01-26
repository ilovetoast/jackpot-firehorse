/**
 * UI-only metadata approval workflow
 * Does not alter approval logic or persistence
 * 
 * Pending Metadata Review Modal Component
 * 
 * Modal dialog that displays pending metadata approvals across all assets.
 * Allows quick approve/reject/edit actions per field.
 * Modeled after PendingAiSuggestionsModal pattern.
 */

import { useState, useEffect, useRef } from 'react'
import { XMarkIcon, CheckIcon, PencilIcon, XCircleIcon, CheckCircleIcon } from '@heroicons/react/24/outline'
import { usePage, router } from '@inertiajs/react'
import ThumbnailPreview from './ThumbnailPreview'
import { usePermission } from '../hooks/usePermission'

export default function PendingMetadataModal({ isOpen, onClose }) {
    const { auth, tenant } = usePage().props
    const [loading, setLoading] = useState(true)
    const [assets, setAssets] = useState([])
    const [currentIndex, setCurrentIndex] = useState(0)
    const [processing, setProcessing] = useState(new Set())
    const currentIndexRef = useRef(0) // Track current index for closures
    
    // Permission checks - only approvers can use this modal
    const { hasPermission: canApprove } = usePermission('metadata.bypass_approval')

    // Fetch all pending metadata approvals
    useEffect(() => {
        if (!isOpen || !canApprove) {
            setLoading(false)
            return
        }

        setLoading(true)
        fetch('/app/api/pending-metadata-approvals', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                if (!res.ok) {
                    const errorText = await res.text().catch(() => 'Unknown error')
                    throw new Error(`HTTP ${res.status}: ${errorText}`)
                }
                return res.json()
            })
            .then((data) => {
                // Group items by asset_id for one-asset-at-a-time display
                const items = data.items || []
                const assetsMap = new Map()
                
                items.forEach((item) => {
                    if (!item || !item.asset_id) {
                        console.warn('[PendingMetadataModal] Skipping invalid item:', item)
                        return
                    }
                    
                    if (!assetsMap.has(item.asset_id)) {
                        assetsMap.set(item.asset_id, {
                            asset_id: item.asset_id,
                            asset_title: item.asset_title || item.asset_filename || null,
                            asset_filename: item.asset_filename || null,
                            final_thumbnail_url: item.final_thumbnail_url || null,
                            preview_thumbnail_url: item.preview_thumbnail_url || null,
                            thumbnail_status: item.thumbnail_status || 'pending',
                            mime_type: item.mime_type || null,
                            file_extension: item.file_extension || null,
                            metadata: item.metadata || {},
                            fields: []
                        })
                    }
                    assetsMap.get(item.asset_id).fields.push(item)
                })
                
                const assetsArray = Array.from(assetsMap.values())
                setAssets(assetsArray)
                setCurrentIndex(0)
                currentIndexRef.current = 0
                setLoading(false)
            })
            .catch((err) => {
                console.error('[PendingMetadataModal] Failed to fetch pending metadata', err)
                alert('Failed to load pending metadata approvals. Please try again.')
                setLoading(false)
            })
    }, [isOpen, canApprove])

    const currentAsset = assets[currentIndex] || null
    const hasMore = currentIndex < assets.length - 1
    const hasPrevious = currentIndex > 0

    // Handle approve
    const handleApprove = async (field) => {
        if (processing.has(field.id)) return
        
        if (!canApprove) {
            alert('You do not have permission to approve metadata.')
            return
        }

        setProcessing((prev) => new Set(prev).add(field.id))

        try {
            const response = await fetch(`/app/metadata/${field.id}/approve`, {
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

            // Remove field from current asset's fields first (optimistic update)
            setAssets((prevAssets) => {
                const currentIdx = currentIndexRef.current
                const updatedAssets = prevAssets.map((asset, idx) => {
                    if (idx === currentIdx) {
                        return {
                            ...asset,
                            fields: asset.fields.filter((f) => f.id !== field.id)
                        }
                    }
                    return asset
                }).filter((asset) => asset.fields.length > 0) // Remove assets with no pending fields
                
                // Adjust current index if needed (use setTimeout to avoid state update conflicts)
                if (updatedAssets.length === 0) {
                    setTimeout(() => onClose(), 0)
                } else if (currentIdx >= updatedAssets.length && updatedAssets.length > 0) {
                    const newIndex = updatedAssets.length - 1
                    setTimeout(() => {
                        setCurrentIndex(newIndex)
                        currentIndexRef.current = newIndex
                    }, 0)
                }
                
                return updatedAssets
            })

            // Don't reload dashboard while modal is open - it causes the modal to close
            // The pending count will update when the modal is closed and dashboard refreshes naturally
        } catch (error) {
            console.error('[PendingMetadataModal] Failed to approve', error)
            alert(error.message || 'Failed to approve metadata')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(field.id)
                return next
            })
        }
    }

    // Handle reject
    const handleReject = async (field) => {
        if (processing.has(field.id)) return

        setProcessing((prev) => new Set(prev).add(field.id))

        try {
            const response = await fetch(`/app/metadata/${field.id}/reject`, {
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

            // Remove field from current asset's fields first (optimistic update)
            setAssets((prevAssets) => {
                const currentIdx = currentIndexRef.current
                const updatedAssets = prevAssets.map((asset, idx) => {
                    if (idx === currentIdx) {
                        return {
                            ...asset,
                            fields: asset.fields.filter((f) => f.id !== field.id)
                        }
                    }
                    return asset
                }).filter((asset) => asset.fields.length > 0) // Remove assets with no pending fields
                
                // Adjust current index if needed (use setTimeout to avoid state update conflicts)
                if (updatedAssets.length === 0) {
                    setTimeout(() => onClose(), 0)
                } else if (currentIdx >= updatedAssets.length && updatedAssets.length > 0) {
                    const newIndex = updatedAssets.length - 1
                    setTimeout(() => {
                        setCurrentIndex(newIndex)
                        currentIndexRef.current = newIndex
                    }, 0)
                }
                
                return updatedAssets
            })

            // Don't reload dashboard while modal is open - it causes the modal to close
            // The pending count will update when the modal is closed and dashboard refreshes naturally
        } catch (error) {
            console.error('[PendingMetadataModal] Failed to reject', error)
            alert(error.message || 'Failed to reject metadata')
        } finally {
            setProcessing((prev) => {
                const next = new Set(prev)
                next.delete(field.id)
                return next
            })
        }
    }

    // Handle edit & approve (navigate to asset edit page)
    const handleEditAndApprove = (field) => {
        // Navigate to assets page with asset query param to open drawer
        // Use Inertia router to navigate properly
        onClose()
        router.visit(`/app/assets?asset=${field.asset_id}&edit_metadata=${field.field_id}`, {
            preserveState: false, // Allow navigation to assets page
            preserveScroll: false,
        })
    }

    // Handle approve all fields for current asset and move to next
    const handleApproveAndNext = async () => {
        if (!currentAsset || currentAsset.fields.length === 0) {
            handleSkip()
            return
        }

        // Approve all fields for current asset sequentially
        const fieldsToApprove = [...currentAsset.fields]
        for (const field of fieldsToApprove) {
            await handleApprove(field)
        }
        
        // After all approvals, check updated state and advance
        // Use a small delay to allow all state updates to complete
        setTimeout(() => {
            setAssets((prevAssets) => {
                if (prevAssets.length === 0) {
                    onClose()
                    return prevAssets
                }
                // Advance to next asset if available
                // Use the ref to get the most current index after state updates
                const nextIndex = currentIndexRef.current < prevAssets.length - 1 
                    ? currentIndexRef.current + 1 
                    : currentIndexRef.current
                
                if (nextIndex < prevAssets.length) {
                    setCurrentIndex(nextIndex)
                    currentIndexRef.current = nextIndex
                } else {
                    // No more assets, close modal
                    onClose()
                }
                return prevAssets
            })
        }, 300)
    }

    // Handle skip (move to next asset)
    const handleSkip = () => {
        if (hasMore) {
            const nextIndex = currentIndex + 1
            setCurrentIndex(nextIndex)
            currentIndexRef.current = nextIndex
        } else {
            onClose()
        }
    }

    // Format value for display
    const formatValue = (field) => {
        if (field.field_type === 'select' && field.options) {
            const option = field.options.find((opt) => opt.value === field.value)
            return option?.display_label || field.value
        }
        if (field.field_type === 'boolean') {
            return field.value ? 'Yes' : 'No'
        }
        if (field.field_type === 'date') {
            try {
                return new Date(field.value).toLocaleDateString()
            } catch (e) {
                return field.value
            }
        }
        if (field.field_type === 'multiselect' && Array.isArray(field.value)) {
            return field.value.join(', ')
        }
        return String(field.value || '')
    }

    if (!isOpen) return null
    
    // Hide modal if user doesn't have approve permission
    if (!canApprove) {
        return null
    }

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
                                <CheckCircleIcon className="h-6 w-6 text-blue-500 mr-2" />
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Pending Metadata Approvals
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
                                <div className="text-sm text-gray-500">Loading pending metadata...</div>
                            </div>
                        ) : assets.length === 0 ? (
                            <div className="text-center py-8">
                                <CheckCircleIcon className="h-12 w-12 text-gray-300 mx-auto mb-4" />
                                <p className="text-sm font-medium text-gray-900 mb-1">All caught up!</p>
                                <p className="text-sm text-gray-500">No pending metadata approvals to review.</p>
                            </div>
                        ) : currentAsset ? (
                            <div className="space-y-4">
                                {/* Progress indicator */}
                                <div className="flex items-center justify-between text-xs text-gray-500 mb-4">
                                    <span>
                                        Asset {currentIndex + 1} of {assets.length}
                                    </span>
                                    <span>
                                        {currentAsset.fields.length} pending field{currentAsset.fields.length !== 1 ? 's' : ''}
                                    </span>
                                </div>

                                {/* Current asset */}
                                <div className="border border-gray-200 rounded-lg p-4 bg-gray-50">
                                    {/* Asset info with thumbnail */}
                                    <div className="mb-4 pb-4 border-b border-gray-200">
                                        <div className="flex items-start gap-3">
                                            {/* Thumbnail */}
                                            <div className="flex-shrink-0 w-32 h-32 rounded border border-gray-200 overflow-hidden">
                                                <ThumbnailPreview
                                                    asset={{
                                                        id: currentAsset.asset_id,
                                                        title: currentAsset.asset_title,
                                                        original_filename: currentAsset.asset_filename,
                                                        final_thumbnail_url: currentAsset.final_thumbnail_url || null,
                                                        preview_thumbnail_url: currentAsset.preview_thumbnail_url || null,
                                                        thumbnail_status: currentAsset.thumbnail_status || 'pending',
                                                        metadata: currentAsset.metadata || {},
                                                        mime_type: currentAsset.mime_type || null,
                                                        file_extension: currentAsset.file_extension || null,
                                                    }}
                                                    alt={currentAsset.asset_title || currentAsset.asset_filename || 'Asset'}
                                                    className="w-full h-full"
                                                    size="md"
                                                />
                                            </div>
                                            {/* Asset details */}
                                            <div className="flex-1 min-w-0">
                                                <div className="text-xs text-gray-500 mb-1">Asset</div>
                                                <div className="text-sm font-medium text-gray-900 truncate">
                                                    {currentAsset.asset_title || currentAsset.asset_filename || `Asset #${currentAsset.asset_id?.substring(0, 8)}`}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Pending metadata fields */}
                                    <div className="space-y-3">
                                        {currentAsset.fields.map((field) => (
                                            <div key={field.id} className="bg-white rounded border border-gray-200 p-3">
                                                <div className="text-xs font-medium text-gray-500 mb-1">
                                                    {field.field_label || field.field_key || 'Metadata Field'}
                                                </div>
                                                <div className="text-base font-semibold text-gray-900 mb-3">
                                                    {formatValue(field)}
                                                </div>
                                                
                                                {/* Field actions */}
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleApprove(field)}
                                                        disabled={processing.has(field.id)}
                                                        className="flex-1 inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-white bg-green-600 hover:bg-green-700 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <CheckIcon className="h-3 w-3 mr-1.5" />
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleEditAndApprove(field)}
                                                        disabled={processing.has(field.id)}
                                                        className="flex-1 inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <PencilIcon className="h-3 w-3 mr-1.5" />
                                                        Edit & Approve
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => handleReject(field)}
                                                        disabled={processing.has(field.id)}
                                                        className="flex-1 inline-flex items-center justify-center px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        <XCircleIcon className="h-3 w-3 mr-1.5" />
                                                        Reject
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Navigation */}
                                <div className="flex items-center justify-between gap-3">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const prevIndex = Math.max(0, currentIndex - 1)
                                            setCurrentIndex(prevIndex)
                                            currentIndexRef.current = prevIndex
                                        }}
                                        disabled={!hasPrevious}
                                        className="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        ← Previous Asset
                                    </button>
                                    
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={handleApproveAndNext}
                                            disabled={processing.size > 0 || currentAsset.fields.length === 0}
                                            className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <CheckIcon className="h-4 w-4 mr-2" />
                                            Approve & Next
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleSkip}
                                            disabled={processing.size > 0}
                                            className="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Skip Asset
                                        </button>
                                    </div>
                                    
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const nextIndex = Math.min(assets.length - 1, currentIndex + 1)
                                            setCurrentIndex(nextIndex)
                                            currentIndexRef.current = nextIndex
                                        }}
                                        disabled={!hasMore}
                                        className="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Next Asset →
                                    </button>
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    )
}

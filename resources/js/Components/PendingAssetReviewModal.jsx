/**
 * Phase J.2: Pending Asset Review Modal Component
 * 
 * Modal for admins/brand managers to review, edit metadata, and approve/reject
 * contributor-submitted assets.
 */

import { useState, useEffect, useRef } from 'react'
import { XMarkIcon, CheckIcon, XCircleIcon, ChevronLeftIcon, ChevronRightIcon, ArrowsPointingOutIcon } from '@heroicons/react/24/outline'
import { usePage, router } from '@inertiajs/react'
import ThumbnailPreview from './ThumbnailPreview'
import AssetMetadataEditForm from './AssetMetadataEditForm'
import CollectionSelector from './Collections/CollectionSelector' // C9.2
import CreateCollectionModal from './Collections/CreateCollectionModal' // C9.2

export default function PendingAssetReviewModal({ isOpen, onClose, initialAssetId = null, initialAsset = null }) {
    const { auth } = usePage().props
    const brand = auth?.activeBrand || usePage().props.brand
    const [loading, setLoading] = useState(true)
    const [assets, setAssets] = useState([])
    const [currentIndex, setCurrentIndex] = useState(0)
    const [processing, setProcessing] = useState(false)
    const [rejectionComment, setRejectionComment] = useState('')
    const [showRejectForm, setShowRejectForm] = useState(false)
    const [editingTitle, setEditingTitle] = useState('') // Local state for title input
    const [metadataValues, setMetadataValues] = useState({}) // Metadata field values (key -> value)
    const [metadataFields, setMetadataFields] = useState([]) // Store field definitions for saving
    const [showZoomModal, setShowZoomModal] = useState(false)
    const currentIndexRef = useRef(0)
    const initialAssetProcessedRef = useRef(false)
    /** C9.2: Collections support */
    const [assetCollections, setAssetCollections] = useState([])
    const [assetCollectionsLoading, setAssetCollectionsLoading] = useState(false)
    const [collectionsList, setCollectionsList] = useState([])
    const [collectionsListLoading, setCollectionsListLoading] = useState(false)
    const [collectionFieldVisible, setCollectionFieldVisible] = useState(false)
    const [showCreateCollectionModal, setShowCreateCollectionModal] = useState(false)
    
    // Reset processed flag when modal closes
    useEffect(() => {
        if (!isOpen) {
            initialAssetProcessedRef.current = false
        }
    }, [isOpen])
    
    // Fetch pending assets
    useEffect(() => {
        if (!isOpen || !brand) {
            setLoading(false)
            return
        }

        // If initialAsset is provided, use it immediately and show content right away
        // This ensures the modal shows content immediately when opened from AssetDrawer
        if (initialAsset && initialAsset.id && !initialAssetProcessedRef.current) {
            console.log('[PendingAssetReviewModal] Initial asset provided:', {
                id: initialAsset.id,
                title: initialAsset.title || initialAsset.original_filename,
                approval_status: initialAsset.approval_status,
                hasThumbnail: !!(initialAsset.thumbnail_url || initialAsset.final_thumbnail_url),
                keys: Object.keys(initialAsset)
            })
            // Ensure the asset has all required fields
            const completeAsset = {
                ...initialAsset,
                approval_status: initialAsset.approval_status || 'pending',
            }
            console.log('[PendingAssetReviewModal] Setting assets immediately with initialAsset')
            setAssets([completeAsset])
            setCurrentIndex(0)
            currentIndexRef.current = 0
            setLoading(false) // Show content immediately, don't wait for API
            initialAssetProcessedRef.current = true // Mark as processed
            console.log('[PendingAssetReviewModal] Assets set, loading=false, assets.length should be 1')
        } else if (!initialAsset || !initialAsset.id) {
            // If no initialAsset, show loading state
            console.log('[PendingAssetReviewModal] No initialAsset provided, showing loading state')
            setLoading(true)
            if (assets.length === 0) {
                setAssets([])
            }
        }
        
        // Always fetch pending assets to merge with initialAsset (if provided) or get all pending assets
        fetch(`/app/api/brands/${brand.id}/pending-assets`, {
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
                const fetchedAssets = data.assets || []
                console.log('[PendingAssetReviewModal] Fetched assets from API:', fetchedAssets.length, 'assets')
                
                // If initialAsset is provided (e.g., from AssetDrawer), ensure it's in the list
                let finalAssets = [...fetchedAssets]
                if (initialAsset && initialAsset.id) {
                    // Check if asset is already in fetched list
                    const existingIndex = finalAssets.findIndex(a => a.id === initialAsset.id)
                    if (existingIndex >= 0) {
                        // Update the asset in the list with the provided data (may have more complete data)
                        finalAssets[existingIndex] = { ...finalAssets[existingIndex], ...initialAsset }
                        console.log('[PendingAssetReviewModal] Asset found in API response, updated')
                    } else {
                        // Asset not in list - add it at the beginning
                        finalAssets.unshift(initialAsset)
                        console.log('[PendingAssetReviewModal] Asset not in API response, added to list')
                    }
                }
                
                // Ensure we always have at least the initialAsset if it was provided
                if (initialAsset && initialAsset.id && finalAssets.length === 0) {
                    console.log('[PendingAssetReviewModal] API returned empty, using initialAsset only')
                    finalAssets = [{
                        ...initialAsset,
                        approval_status: initialAsset.approval_status || 'pending',
                    }]
                }
                
                console.log('[PendingAssetReviewModal] Final assets list:', finalAssets.length, 'assets', finalAssets.map(a => ({ id: a.id, title: a.title || a.original_filename })))
                
                // Only update assets if we have something, or if we had initialAsset and need to preserve it
                if (finalAssets.length > 0 || (initialAsset && initialAsset.id)) {
                    setAssets(finalAssets.length > 0 ? finalAssets : [{
                        ...initialAsset,
                        approval_status: initialAsset.approval_status || 'pending',
                    }])
                }
                
                // If initialAssetId is provided, find its index
                if (initialAssetId && finalAssets.length > 0) {
                    const foundIndex = finalAssets.findIndex(a => a.id === initialAssetId)
                    if (foundIndex >= 0) {
                        setCurrentIndex(foundIndex)
                        currentIndexRef.current = foundIndex
                        console.log('[PendingAssetReviewModal] Set current index to:', foundIndex)
                    } else {
                        setCurrentIndex(0)
                        currentIndexRef.current = 0
                    }
                } else {
                    setCurrentIndex(0)
                    currentIndexRef.current = 0
                }
                
                // Only set loading to false if we didn't already set it (i.e., if no initialAsset was provided)
                if (!initialAsset || !initialAsset.id) {
                    setLoading(false)
                }
            })
            .catch((err) => {
                console.error('[PendingAssetReviewModal] Failed to fetch pending assets', err)
                
                // If we have an initialAsset, use it even if fetch fails
                if (initialAsset && initialAsset.id) {
                    console.log('[PendingAssetReviewModal] Using initialAsset after fetch failure')
                    setAssets([initialAsset])
                    setCurrentIndex(0)
                    currentIndexRef.current = 0
                    setLoading(false)
                } else {
                    alert('Failed to load pending assets. Please try again.')
                    setLoading(false)
                }
            })
    }, [isOpen, brand?.id, initialAssetId])
    
    // Recovery effect: if we have initialAsset but no assets, set it
    // This handles cases where the main effect didn't catch it
    useEffect(() => {
        if (isOpen && initialAsset && initialAsset.id && assets.length === 0 && !loading && !initialAssetProcessedRef.current) {
            console.log('[PendingAssetReviewModal] Recovery: initialAsset provided but assets empty, setting it now')
            const completeAsset = {
                ...initialAsset,
                approval_status: initialAsset.approval_status || 'pending',
            }
            setAssets([completeAsset])
            setCurrentIndex(0)
            currentIndexRef.current = 0
            setLoading(false)
            initialAssetProcessedRef.current = true
        }
    }, [isOpen, initialAsset?.id, assets.length, loading])

    const currentAsset = assets[currentIndex] || null
    const hasMore = currentIndex < assets.length - 1
    const hasPrevious = currentIndex > 0
    
    // Debug logging for asset state - log whenever assets change
    useEffect(() => {
        if (isOpen) {
            console.log('[PendingAssetReviewModal] State update:', {
                assetsCount: assets.length,
                assetsIds: assets.map(a => a?.id),
                currentIndex,
                currentAssetId: currentAsset?.id,
                currentAssetTitle: currentAsset?.title || currentAsset?.original_filename,
                loading,
                initialAssetId,
                hasInitialAsset: !!(initialAsset && initialAsset.id),
                initialAssetIdValue: initialAsset?.id
            })
        }
    }, [isOpen, assets.length, currentIndex, currentAsset?.id, loading, initialAssetId, initialAsset?.id])
    
    // Sync editingTitle and metadata values with currentAsset when asset changes
    useEffect(() => {
        if (currentAsset) {
            setEditingTitle(currentAsset.title || '')
            // Fetch current metadata values for editing
            fetchCurrentMetadataValues(currentAsset.id)
        }
    }, [currentAsset?.id, currentAsset?.title])

    // C9.2: Fetch collections for current asset
    useEffect(() => {
        if (!currentAsset?.id) {
            setAssetCollections([])
            return
        }
        setAssetCollectionsLoading(true)
        fetch(`/app/assets/${currentAsset.id}/collections`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setAssetCollections(data?.collections ?? [])
            })
            .catch(() => setAssetCollections([]))
            .finally(() => setAssetCollectionsLoading(false))
    }, [currentAsset?.id])

    // C9.2: Fetch collections list and check visibility
    useEffect(() => {
        if (!currentAsset?.category_id) {
            setCollectionFieldVisible(false)
            setCollectionsList([])
            return
        }

        // Check visibility
        fetch(`/app/collections/field-visibility?category_id=${currentAsset.category_id}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setCollectionFieldVisible(data?.visible ?? false)
            })
            .catch(() => {
                setCollectionFieldVisible(false)
            })

        // Fetch collections list
        setCollectionsListLoading(true)
        fetch('/app/collections/list', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setCollectionsList(data?.collections ?? [])
            })
            .catch(() => setCollectionsList([]))
            .finally(() => setCollectionsListLoading(false))
    }, [currentAsset?.category_id])
    
    // Fetch current metadata values from editable endpoint
    // This is used to populate initial values, but the schema comes from AssetMetadataEditForm
    const fetchCurrentMetadataValues = async (assetId) => {
        if (!assetId) return
        
        try {
            const response = await fetch(`/app/assets/${assetId}/metadata/editable`, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            
            if (!response.ok) {
                throw new Error('Failed to fetch metadata')
            }
            
            const data = await response.json()
            // Filter to only user-defined fields (population_mode != 'automatic', source != 'system'/'automatic')
            const userFields = (data.fields || []).filter(field => {
                const isAutomatic = field.population_mode === 'automatic'
                const isSystem = field.source === 'system' || field.source === 'automatic'
                return !isAutomatic && !isSystem
            })
            
            // Build metadata values object (preserve original types, don't stringify)
            // Include fields even if they don't have values yet (for new fields user can add)
            const valuesObj = {}
            userFields.forEach(field => {
                const value = field.current_value || field.pending_value
                // Include field even if value is null/undefined (allows user to set it)
                valuesObj[field.key] = value !== undefined ? value : null
            })
            
            setMetadataValues(valuesObj)
            setMetadataFields(userFields) // Store for saving
        } catch (error) {
            console.error('[PendingAssetReviewModal] Failed to fetch metadata values', error)
        }
    }
    
    // Handle metadata field changes from the form
    const handleMetadataChange = (fieldKey, value) => {
        setMetadataValues(prev => ({
            ...prev,
            [fieldKey]: value
        }))
        
        // Update metadataFields if this is a new field being added
        // (field might not be in metadataFields yet if it's from schema but had no value)
        const existingField = metadataFields.find(f => f.key === fieldKey)
        if (!existingField && currentAsset) {
            // Field is being edited but wasn't in the initial fetch - that's okay,
            // we'll save it using the schema's field_id when saving
        }
    }

    // C9.2: Save collection changes
    const saveCollectionChanges = async (assetId) => {
        if (!assetId || !collectionFieldVisible) return

        try {
            await fetch(`/app/assets/${assetId}/collections`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    collection_ids: assetCollections.map((c) => c.id),
                }),
            })
        } catch (error) {
            console.error('[PendingAssetReviewModal] Failed to save collections', error)
        }
    }

    // Save metadata changes
    const saveMetadataChanges = async (assetId) => {
        // Title will be saved via the approve/reject endpoint, not here
        // We'll pass it in the request body
        
        // Save user-defined metadata changes
        // First, fetch the metadata schema to get field_ids for all fields (including new ones)
        try {
            const categoryId = currentAsset?.metadata?.category_id || currentAsset?.category_id
            if (!categoryId) {
                console.warn('[PendingAssetReviewModal] No category ID for asset, skipping metadata save')
                return
            }
            
            // Determine asset type from mime_type
            const mime = currentAsset?.mime_type?.toLowerCase() || ''
            let assetType = 'image'
            if (mime.startsWith('video/')) assetType = 'video'
            else if (mime.includes('pdf') || mime.includes('document') || mime.includes('text')) assetType = 'document'
            
            // Fetch schema to get field_ids for all fields
            const schemaResponse = await fetch(`/app/uploads/metadata-schema?category_id=${categoryId}&asset_type=${assetType}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
            })
            
            if (schemaResponse.ok) {
                const schemaData = await schemaResponse.json()
                // Build map of field key -> field_id from schema
                const schemaFieldMap = {}
                if (schemaData.groups) {
                    schemaData.groups.forEach(group => {
                        (group.fields || []).forEach(field => {
                            if (field.field_id && !field.population_mode || field.population_mode !== 'automatic') {
                                schemaFieldMap[field.key] = field.field_id
                            }
                        })
                    })
                }
                
                // Update each changed metadata field
                const updatePromises = Object.entries(metadataValues).map(async ([key, value]) => {
                    // Try to get field_id from metadataFields first, then from schema
                    const existingField = metadataFields.find(f => f.key === key)
                    const fieldId = existingField?.field_id || schemaFieldMap[key]
                    
                    if (fieldId && value !== null && value !== undefined && value !== '') {
                        try {
                            // Update the metadata field using editMetadata endpoint
                            await fetch(`/app/assets/${assetId}/metadata/edit`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                                },
                                body: JSON.stringify({ 
                                    metadata_field_id: fieldId,
                                    value: value 
                                }),
                                credentials: 'same-origin',
                            })
                        } catch (error) {
                            console.error(`[PendingAssetReviewModal] Failed to save metadata field ${key}`, error)
                        }
                    }
                })
                
                await Promise.all(updatePromises)
            }
        } catch (error) {
            console.error('[PendingAssetReviewModal] Failed to fetch schema for saving metadata', error)
        }
    }
    
    // Handle approve
    const handleApprove = async () => {
        if (!currentAsset || processing || !brand?.id) {
            if (!brand?.id) {
                console.error('[PendingAssetReviewModal] Cannot approve: brand is not available')
                alert('Brand information is not available. Please refresh the page.')
            }
            return
        }
        
        setProcessing(true)
        try {
            // Save metadata changes before approving
            await saveMetadataChanges(currentAsset.id)
            // C9.2: Save collection changes before approving
            await saveCollectionChanges(currentAsset.id)
            
            // Always include title in approval request (even if unchanged, to ensure it's saved)
            const requestBody = {
                title: editingTitle || currentAsset?.title || '',
            }
            // Note: comment is handled separately if needed
            
            const response = await fetch(`/app/brands/${brand.id}/assets/${currentAsset.id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify(requestBody),
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.error || 'Failed to approve asset')
            }

            // Remove asset from list and advance
            setAssets((prevAssets) => {
                const updated = prevAssets.filter((a) => a.id !== currentAsset.id)
                return updated
            })
            
            // Auto-advance to next asset or close
            setTimeout(() => {
                setAssets((prevAssets) => {
                    if (prevAssets.length === 0) {
                        onClose()
                        return prevAssets
                    }
                    const nextIndex = currentIndexRef.current < prevAssets.length 
                        ? currentIndexRef.current 
                        : prevAssets.length - 1
                    if (nextIndex >= 0 && nextIndex < prevAssets.length) {
                        setCurrentIndex(nextIndex)
                        currentIndexRef.current = nextIndex
                    } else {
                        onClose()
                    }
                    return prevAssets
                })
            }, 100)
        } catch (error) {
            console.error('[PendingAssetReviewModal] Failed to approve', error)
            alert(error.message || 'Failed to approve asset')
        } finally {
            setProcessing(false)
        }
    }

    // Handle reject
    const handleReject = async () => {
        if (!currentAsset || processing || !brand?.id || !rejectionComment.trim() || rejectionComment.trim().length < 10) {
            if (!brand?.id) {
                console.error('[PendingAssetReviewModal] Cannot reject: brand is not available')
                alert('Brand information is not available. Please refresh the page.')
            } else if (rejectionComment.trim().length < 10) {
                alert('Rejection reason must be at least 10 characters.')
            }
            return
        }
        
        setProcessing(true)
        try {
            // Save metadata changes before rejecting
            await saveMetadataChanges(currentAsset.id)
            // C9.2: Save collection changes before rejecting
            await saveCollectionChanges(currentAsset.id)
            
            // Always include title in rejection request (even if unchanged, to ensure it's saved)
            const rejectBody = {
                rejection_reason: rejectionComment.trim(),
                title: editingTitle || currentAsset?.title || '',
            }
            
            const response = await fetch(`/app/brands/${brand.id}/assets/${currentAsset.id}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify(rejectBody),
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const data = await response.json()
                throw new Error(data.error || 'Failed to reject asset')
            }

            // Update asset status to rejected (don't remove from list - keep it visible with rejected status)
            const responseData = await response.json()
            setAssets((prevAssets) => {
                return prevAssets.map((asset) => {
                    if (asset.id === currentAsset.id) {
                        return {
                            ...asset,
                            approval_status: 'rejected',
                            rejection_reason: rejectionComment.trim(),
                            rejected_at: responseData.rejected_at || new Date().toISOString(),
                        }
                    }
                    return asset
                })
            })
            
            setRejectionComment('')
            setShowRejectForm(false)
            
            // Don't auto-advance - keep rejected asset visible so user can see the rejection status
        } catch (error) {
            console.error('[PendingAssetReviewModal] Failed to reject', error)
            alert(error.message || 'Failed to reject asset')
        } finally {
            setProcessing(false)
        }
    }

    // Handle title update (inline editing) - only updates local state
    // Title will be saved when approving/rejecting the asset
    const handleTitleUpdate = (newTitle) => {
        if (!currentAsset || processing) return
        
        // Don't update if title hasn't changed
        if (newTitle === (currentAsset.title || '')) {
            return
        }
        
        // Update title locally (will be saved on approve/reject)
        setAssets((prevAssets) => {
            return prevAssets.map((asset) => {
                if (asset.id === currentAsset.id) {
                    return { ...asset, title: newTitle }
                }
                return asset
            })
        })
        // Note: Title is saved to backend when approving/rejecting, not on blur
    }

    // Navigation
    const handleNext = () => {
        if (hasMore) {
            const nextIndex = currentIndex + 1
            setCurrentIndex(nextIndex)
            currentIndexRef.current = nextIndex
            setShowRejectForm(false)
            setRejectionComment('')
        }
    }

    const handlePrevious = () => {
        if (hasPrevious) {
            const prevIndex = currentIndex - 1
            setCurrentIndex(prevIndex)
            currentIndexRef.current = prevIndex
            setShowRejectForm(false)
            setRejectionComment('')
        }
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
                <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                        {/* Header */}
                        <div className="flex items-center justify-between mb-4">
                            <div className="flex items-center">
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Pending Publication Review
                                </h3>
                                {assets.length > 0 && (
                                    <span className="ml-3 text-sm text-gray-500">
                                        ({currentIndex + 1} of {assets.length})
                                    </span>
                                )}
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
                            <div className="text-center py-12">
                                <div className="text-sm text-gray-500">Loading pending assets...</div>
                            </div>
                        ) : assets.length === 0 ? (
                            <div className="text-center py-12">
                                <p className="text-sm text-gray-500">No pending assets to review.</p>
                                {initialAsset && initialAsset.id && (
                                    <p className="text-xs text-gray-400 mt-2">
                                        Debug: initialAsset provided but not in assets list. ID: {initialAsset.id}
                                    </p>
                                )}
                            </div>
                        ) : currentAsset ? (
                            <div className="space-y-6">
                                {/* Asset Preview Section */}
                                <div className="border-b border-gray-200 pb-4">
                                    <div className="flex items-start space-x-4">
                                        {/* Thumbnail with enlarge button */}
                                        <div className="flex-shrink-0 relative group">
                                            <div 
                                                className="cursor-pointer"
                                                onClick={() => setShowZoomModal(true)}
                                            >
                                                <ThumbnailPreview
                                                    asset={currentAsset}
                                                    className="w-32 h-32 object-cover rounded border border-gray-200"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setShowZoomModal(true)}
                                                className="absolute top-2 right-2 p-1.5 bg-black/60 hover:bg-black/80 rounded text-white opacity-0 group-hover:opacity-100 transition-opacity"
                                                aria-label="Enlarge image"
                                            >
                                                <ArrowsPointingOutIcon className="h-4 w-4" />
                                            </button>
                                        </div>
                                        
                                        {/* Asset Info */}
                                        <div className="flex-1 min-w-0">
                                            <h4 className="text-lg font-medium text-gray-900 truncate">
                                                {currentAsset.title || currentAsset.original_filename || 'Untitled'}
                                            </h4>
                                            <div className="mt-2 space-y-1 text-sm text-gray-500">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">Uploaded by:</span>
                                                    {currentAsset.uploader?.avatar_url ? (
                                                        <img
                                                            src={currentAsset.uploader.avatar_url}
                                                            alt={currentAsset.uploader.name || 'User'}
                                                            className="h-5 w-5 rounded-full object-cover flex-shrink-0"
                                                        />
                                                    ) : (
                                                        <div className="h-5 w-5 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0">
                                                            <span className="text-xs font-medium text-gray-600">
                                                                {(currentAsset.uploader?.first_name?.[0] || currentAsset.uploader?.name?.[0] || currentAsset.uploader?.email?.[0] || '?').toUpperCase()}
                                                            </span>
                                                        </div>
                                                    )}
                                                    <span>
                                                        {(currentAsset.uploader?.name && currentAsset.uploader.name.trim()) || 
                                                         (currentAsset.uploader?.first_name && currentAsset.uploader?.last_name && `${currentAsset.uploader.first_name} ${currentAsset.uploader.last_name}`.trim()) ||
                                                         currentAsset.uploader?.email || 
                                                         'Unknown User'}
                                                    </span>
                                                </div>
                                                {currentAsset.created_at && (
                                                    <div>
                                                        <span className="font-medium">Upload date:</span>{' '}
                                                        {new Date(currentAsset.created_at).toLocaleDateString()}
                                                    </div>
                                                )}
                                                <div>
                                                    {currentAsset.approval_status === 'rejected' ? (
                                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                            Rejected
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                            Pending Publication
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Rejection Comment Section - Show if rejected */}
                                {currentAsset.approval_status === 'rejected' && currentAsset.rejection_reason && (
                                    <div className="border border-red-200 bg-red-50 rounded-md p-4">
                                        <div className="flex items-start">
                                            <XCircleIcon className="h-5 w-5 text-red-400 mt-0.5 mr-3 flex-shrink-0" />
                                            <div className="flex-1">
                                                <h5 className="text-sm font-medium text-red-900 mb-1">
                                                    Rejection Reason
                                                </h5>
                                                <p className="text-sm text-red-700 whitespace-pre-wrap">
                                                    {currentAsset.rejection_reason}
                                                </p>
                                                {currentAsset.rejected_at && (
                                                    <p className="mt-2 text-xs text-red-600">
                                                        Rejected on {new Date(currentAsset.rejected_at).toLocaleDateString('en-US', {
                                                            year: 'numeric',
                                                            month: 'short',
                                                            day: 'numeric',
                                                            hour: 'numeric',
                                                            minute: '2-digit',
                                                        })}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Metadata Section - User-defined fields only */}
                                <div className="border-b border-gray-200 pb-4">
                                    <h5 className="text-sm font-medium text-gray-900 mb-3">User-Defined Metadata</h5>
                                    
                                    {/* Title - Editable (separate from metadata form) */}
                                    <div className="mb-4">
                                        <label className="block text-xs font-medium text-gray-700 mb-1">
                                            Title
                                        </label>
                                        <input
                                            type="text"
                                            value={editingTitle}
                                            onChange={(e) => setEditingTitle(e.target.value)}
                                            onBlur={(e) => handleTitleUpdate(e.target.value)}
                                            onKeyDown={(e) => {
                                                // Save on Enter key
                                                if (e.key === 'Enter') {
                                                    e.target.blur()
                                                }
                                                // Cancel on Escape key
                                                if (e.key === 'Escape') {
                                                    setEditingTitle(currentAsset.title || '')
                                                    e.target.blur()
                                                }
                                            }}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                            disabled={processing}
                                        />
                                    </div>
                                    
                                    {/* Shared Asset Metadata Edit Form */}
                                    {currentAsset && (
                                        <AssetMetadataEditForm
                                            asset={currentAsset}
                                            values={metadataValues}
                                            onChange={handleMetadataChange}
                                            disabled={processing}
                                            showErrors={false}
                                            filterUserDefinedOnly={true}
                                        />
                                    )}

                                    {/* C9.2: Collections field (if visible for category) */}
                                    {collectionFieldVisible && (
                                        <div className="mt-4 pt-4 border-t border-gray-200">
                                            <label className="block text-xs font-medium text-gray-700 mb-2">
                                                Collections
                                            </label>
                                            {collectionsListLoading || assetCollectionsLoading ? (
                                                <p className="text-sm text-gray-500">Loading collections…</p>
                                            ) : (
                                                <CollectionSelector
                                                    collections={collectionsList}
                                                    selectedIds={assetCollections.map((c) => c.id)}
                                                    onChange={async (newCollectionIds) => {
                                                        if (processing) return
                                                        try {
                                                            await fetch(`/app/assets/${currentAsset.id}/collections`, {
                                                                method: 'PUT',
                                                                headers: {
                                                                    'Content-Type': 'application/json',
                                                                    'Accept': 'application/json',
                                                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                                                                },
                                                                credentials: 'same-origin',
                                                                body: JSON.stringify({
                                                                    collection_ids: newCollectionIds,
                                                                }),
                                                            })
                                                            .then((r) => r.json())
                                                            .then((data) => {
                                                                // Refresh collections from backend
                                                                return fetch(`/app/assets/${currentAsset.id}/collections`, {
                                                                    headers: { Accept: 'application/json' },
                                                                    credentials: 'same-origin',
                                                                })
                                                            })
                                                            .then((r) => r.json())
                                                            .then((data) => {
                                                                setAssetCollections(data?.collections ?? [])
                                                            })
                                                        } catch (error) {
                                                            console.error('[PendingAssetReviewModal] Failed to update collections', error)
                                                        }
                                                    }}
                                                    disabled={processing}
                                                    placeholder="Select collections…"
                                                    showCreateButton={true}
                                                    onCreateClick={() => setShowCreateCollectionModal(true)}
                                                />
                                            )}
                                        </div>
                                    )}
                                </div>

                                {/* Rejection Form */}
                                {showRejectForm && (
                                    <div className="border border-red-200 bg-red-50 rounded-md p-4">
                                        <label className="block text-sm font-medium text-gray-900 mb-2">
                                            Rejection Reason <span className="text-red-600">*</span>
                                        </label>
                                        <textarea
                                            value={rejectionComment}
                                            onChange={(e) => setRejectionComment(e.target.value)}
                                            placeholder="Please provide a reason for rejection (minimum 10 characters)..."
                                            rows={3}
                                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                                        />
                                        <p className="mt-1 text-xs text-gray-500">
                                            {rejectionComment.length}/10 minimum characters
                                        </p>
                                    </div>
                                )}

                                {/* Actions */}
                                <div className="flex items-center justify-between pt-4 border-t border-gray-200">
                                    {/* Navigation */}
                                    <div className="flex items-center space-x-2">
                                        <button
                                            type="button"
                                            onClick={handlePrevious}
                                            disabled={!hasPrevious || processing}
                                            className="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            <ChevronLeftIcon className="h-4 w-4 mr-1" />
                                            Previous
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleNext}
                                            disabled={!hasMore || processing}
                                            className="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Next
                                            <ChevronRightIcon className="h-4 w-4 ml-1" />
                                        </button>
                                    </div>

                                    {/* Approve/Reject Buttons */}
                                    <div className="flex items-center space-x-3">
                                        {!showRejectForm ? (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => setShowRejectForm(true)}
                                                    disabled={processing}
                                                    className="inline-flex items-center px-4 py-2 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    <XCircleIcon className="h-4 w-4 mr-2" />
                                                    Reject Asset
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={handleApprove}
                                                    disabled={processing}
                                                    className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    <CheckIcon className="h-4 w-4 mr-2" />
                                                    Approve Asset
                                                </button>
                                            </>
                                        ) : (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setShowRejectForm(false)
                                                        setRejectionComment('')
                                                    }}
                                                    disabled={processing}
                                                    className="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    Cancel
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={handleReject}
                                                    disabled={processing || rejectionComment.trim().length < 10}
                                                    className="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                                                >
                                                    <XCircleIcon className="h-4 w-4 mr-2" />
                                                    Confirm Rejection
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>
            
            {/* Enlarge/Zoom Modal */}
            {showZoomModal && currentAsset && (
                <div
                    className="fixed inset-0 z-[60] bg-black/90 flex items-center justify-center p-4"
                    onClick={() => setShowZoomModal(false)}
                >
                    <button
                        type="button"
                        onClick={() => setShowZoomModal(false)}
                        className="absolute top-4 right-4 z-10 text-white hover:text-gray-300 transition-colors"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-8 w-8" />
                    </button>
                    
                    <div 
                        className="relative w-full h-full flex items-center justify-center overflow-hidden"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <ThumbnailPreview
                            asset={currentAsset}
                            className="max-w-full max-h-full object-contain"
                        />
                    </div>
                </div>
            )}

            {/* C9.2: Create Collection Modal */}
            <CreateCollectionModal
                open={showCreateCollectionModal}
                onClose={() => setShowCreateCollectionModal(false)}
                onCreated={async (newCollection) => {
                    // Add new collection to list and select it
                    setCollectionsList((prev) => {
                        if (prev.some((c) => c.id === newCollection.id)) {
                            return prev
                        }
                        return [...prev, { id: newCollection.id, name: newCollection.name }]
                    })
                    setAssetCollections((prev) => [...prev, { id: newCollection.id, name: newCollection.name }])
                    setShowCreateCollectionModal(false)
                }}
            />
        </div>
    )
}

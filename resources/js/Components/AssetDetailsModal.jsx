/**
 * AssetDetailsModal Component
 * 
 * Modal for displaying all asset metadata fields (read-only) for testing/verification.
 * Shows preview, category, and all metadata fields including AI/automated ones.
 * 
 * Actions available (permission-protected):
 * - Regenerate System Metadata (orientation, color_space, resolution_class)
 * - Regenerate AI Metadata (Photo Type and other AI-eligible fields via metadata_generator agent)
 * - Regenerate AI Tagging (general/freeform tags - not yet fully implemented)
 * - Thumbnail Management (regenerate preview/thumbnails)
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object
 * @param {boolean} props.isOpen - Whether modal is open
 * @param {Function} props.onClose - Callback when modal should close
 */
import { useEffect, useState, useRef, useMemo } from 'react'
import { XMarkIcon, ArrowPathIcon, ChevronDownIcon, TrashIcon, LockClosedIcon, CheckCircleIcon, XCircleIcon, ArchiveBoxIcon, ArrowUturnLeftIcon, CheckIcon } from '@heroicons/react/24/outline'
import ThumbnailPreview from './ThumbnailPreview'
import DominantColorsSwatches from './DominantColorsSwatches'
import AssetTagManager from './AssetTagManager'
import { usePermission } from '../hooks/usePermission'
import { router, usePage } from '@inertiajs/react'
import { supportsThumbnail } from '../utils/thumbnailUtils'

export default function AssetDetailsModal({ asset, isOpen, onClose }) {
    const [metadata, setMetadata] = useState(null)
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState(null)
    
    // Permission checks
    const { auth } = usePage().props
    const { hasPermission: canRegenerateAiMetadata } = usePermission('assets.ai_metadata.regenerate')
    const { hasPermission: canRegenerateThumbnailsAdmin } = usePermission('assets.regenerate_thumbnails_admin')
    const { hasPermission: canPublish } = usePermission('asset.publish')
    const { hasPermission: canUnpublish } = usePermission('asset.unpublish')
    const { hasPermission: canArchive } = usePermission('asset.archive')
    const { hasPermission: canRestore } = usePermission('asset.restore')
    
    // For troubleshooting: Also allow owners/admins even if permission check fails
    const tenantRole = auth?.tenant_role || null
    const isOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
    const canRegenerateAiMetadataForTroubleshooting = canRegenerateAiMetadata || isOwnerOrAdmin
    
    // Check if user is a contributor
    const isContributor = auth?.user?.brand_role === 'contributor' && 
                          !['owner', 'admin'].includes(auth?.user?.tenant_role?.toLowerCase() || '');
    
    // Check if approvals are enabled
    const approvalsEnabled = auth?.approval_features?.approvals_enabled;
    
    // Contributors cannot publish/archive when approval is enabled
    const contributorBlocked = isContributor && approvalsEnabled;
    
    // Tenant admins/owners typically have all asset permissions, so allow them to see lifecycle actions
    // This is a fallback in case permissions aren't properly assigned to the role
    // But contributors are blocked when approval is enabled
    const canPublishWithFallback = (canPublish || isOwnerOrAdmin) && !contributorBlocked
    const canUnpublishWithFallback = canUnpublish || isOwnerOrAdmin
    const canArchiveWithFallback = (canArchive || isOwnerOrAdmin) && !contributorBlocked
    const canRestoreWithFallback = canRestore || isOwnerOrAdmin
    
    // System Metadata Regeneration state
    const [regeneratingSystemMetadata, setRegeneratingSystemMetadata] = useState(false)
    const [systemMetadataError, setSystemMetadataError] = useState(null)
    const [systemMetadataSuccess, setSystemMetadataSuccess] = useState(false)
    
    // AI Metadata Regeneration state
    const [regeneratingAiMetadata, setRegeneratingAiMetadata] = useState(false)
    const [aiMetadataError, setAiMetadataError] = useState(null)
    const [aiMetadataSuccess, setAiMetadataSuccess] = useState(false)
    
    // AI Tagging Regeneration state
    const [regeneratingAiTagging, setRegeneratingAiTagging] = useState(false)
    const [aiTaggingError, setAiTaggingError] = useState(null)
    const [aiTaggingSuccess, setAiTaggingSuccess] = useState(false)
    
    // Thumbnail Management state
    const [showThumbnailManagement, setShowThumbnailManagement] = useState(false)
    const [showThumbnailDropdown, setShowThumbnailDropdown] = useState(false)
    const [regeneratingThumbnails, setRegeneratingThumbnails] = useState(false)
    const [thumbnailError, setThumbnailError] = useState(null)
    const [selectedThumbnailStyles, setSelectedThumbnailStyles] = useState(['thumb', 'medium', 'large'])
    const [forceImageMagick, setForceImageMagick] = useState(false)
    const thumbnailDropdownRef = useRef(null)
    
    // Phase V-1: Video regeneration state
    const [regeneratingVideoThumbnail, setRegeneratingVideoThumbnail] = useState(false)
    const [regeneratingVideoPreview, setRegeneratingVideoPreview] = useState(false)
    const [videoThumbnailError, setVideoThumbnailError] = useState(null)
    const [videoPreviewError, setVideoPreviewError] = useState(null)
    
    // Phase V-1: Detect if asset is a video
    const isVideo = useMemo(() => {
        if (!asset) return false
        const mimeType = asset.mime_type || ''
        const filename = asset.original_filename || ''
        const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
        const ext = filename.split('.').pop()?.toLowerCase() || ''
        return mimeType.startsWith('video/') || videoExtensions.includes(ext)
    }, [asset])
    
    // Remove preview state
    const [removePreviewLoading, setRemovePreviewLoading] = useState(false)
    const [removePreviewError, setRemovePreviewError] = useState(null)
    
    // Actions dropdown state
    const [showActionsDropdown, setShowActionsDropdown] = useState(false)
    const actionsDropdownRef = useRef(null)
    
    // Check if user can approve metadata (for display purposes only - approval happens in drawer)
    const { hasPermission: canApproveMetadata } = usePermission('metadata.bypass_approval')
    const metadataApprovalEnabled = auth?.metadata_approval_features?.metadata_approval_enabled === true
    
    // Lifecycle actions state
    const [publishing, setPublishing] = useState(false)
    const [unpublishing, setUnpublishing] = useState(false)
    const [archiving, setArchiving] = useState(false)
    const [restoring, setRestoring] = useState(false)
    const [lifecycleError, setLifecycleError] = useState(null)
    
    // Close actions dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (actionsDropdownRef.current && !actionsDropdownRef.current.contains(event.target)) {
                setShowActionsDropdown(false)
            }
        }
        
        if (showActionsDropdown) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [showActionsDropdown])
    
    // Available thumbnail styles
    const availableThumbnailStyles = [
        { name: 'thumb', label: 'Thumb (320×320)', description: 'Grid thumbnails' },
        { name: 'medium', label: 'Medium (1024×1024)', description: 'Drawer previews' },
        { name: 'large', label: 'Large (4096×4096)', description: 'Full-screen previews' },
    ]
    
    // Close thumbnail dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (thumbnailDropdownRef.current && !thumbnailDropdownRef.current.contains(event.target)) {
                setShowThumbnailDropdown(false)
            }
        }
        
        if (showThumbnailDropdown) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [showThumbnailDropdown])

    useEffect(() => {
        if (isOpen && asset?.id) {
            fetchMetadata()
        }
    }, [isOpen, asset?.id])

    const fetchMetadata = async () => {
        if (!asset?.id) return

        setLoading(true)
        setError(null)

        try {
            const response = await window.axios.get(`/app/assets/${asset.id}/metadata/all`)
            setMetadata(response.data)
        } catch (err) {
            console.error('Failed to fetch metadata:', err)
            setError(err.response?.data?.message || 'Failed to load metadata')
        } finally {
            setLoading(false)
        }
    }

    // NOTE: Approve/Reject handlers removed - use drawer's PendingMetadataList component instead
    // The drawer uses the proper /pending endpoint and refreshes correctly

    // Check if field has a value
    const hasValue = (value, type) => {
        if (value === null || value === undefined) return false
        if (type === 'multiselect' && Array.isArray(value)) {
            return value.length > 0
        }
        return value !== ''
    }

    const formatValue = (value, type) => {
        if (!hasValue(value, type)) {
            return null // Return null instead of "Not set" text
        }

        if (type === 'multiselect' && Array.isArray(value)) {
            return (
                <span className="text-gray-700">
                    {value.map((v, idx) => (
                        <span key={idx}>
                            {String(v)}
                            {idx < value.length - 1 && ', '}
                        </span>
                    ))}
                </span>
            )
        }

        if (type === 'boolean') {
            return (
                <span className={value ? 'text-green-700' : 'text-gray-600'}>
                    {value ? 'Yes' : 'No'}
                </span>
            )
        }

        if (type === 'date') {
            try {
                const date = new Date(value)
                return date.toLocaleDateString()
            } catch {
                return String(value)
            }
        }

        return <span className="text-gray-700">{String(value)}</span>
    }

    const getSourceBadge = (field) => {
        if (!field.metadata) return null

        const { source, producer, confidence, is_overridden } = field.metadata

        if (is_overridden) {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                    Manual Override
                </span>
            )
        }

        // Check for AI origin first (either source='ai' or producer='ai')
        // This ensures AI suggestions show as "AI" even if source was changed during processing
        if (source === 'ai' || producer === 'ai') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-pink-100 text-pink-800">
                    AI {confidence ? `(${(confidence * 100).toFixed(0)}%)` : ''}
                </span>
            )
        }

        if (source === 'user') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                    User
                </span>
            )
        }

        if (source === 'automatic' || source === 'system') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                    {producer === 'system' ? 'System' : 'Automatic'}
                </span>
            )
        }

        return null
    }
    
    // Handle System Metadata Regeneration
    const handleRegenerateSystemMetadata = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        
        setRegeneratingSystemMetadata(true)
        setSystemMetadataError(null)
        setSystemMetadataSuccess(false)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/system-metadata/regenerate`)
            
            if (response.data.success) {
                setSystemMetadataSuccess(true)
                setTimeout(() => {
                    fetchMetadata()
                    setSystemMetadataSuccess(false)
                }, 2000)
            } else {
                setSystemMetadataError(response.data.error || 'Failed to regenerate system metadata')
            }
        } catch (err) {
            console.error('System metadata regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate system metadata'
                
                if (status === 403) {
                    setSystemMetadataError('You do not have permission to regenerate system metadata')
                } else if (status === 404) {
                    setSystemMetadataError('Asset not found')
                } else {
                    setSystemMetadataError(errorMessage)
                }
            } else {
                setSystemMetadataError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingSystemMetadata(false)
        }
    }
    
    // Handle AI Metadata Regeneration
    const handleRegenerateAiMetadata = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        
        setRegeneratingAiMetadata(true)
        setAiMetadataError(null)
        setAiMetadataSuccess(false)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/ai-metadata/regenerate`)
            
            if (response.data.success) {
                setAiMetadataSuccess(true)
                // Refresh metadata after a short delay to show updated values
                setTimeout(() => {
                    fetchMetadata()
                    setAiMetadataSuccess(false)
                }, 2000)
            } else {
                setAiMetadataError(response.data.error || 'Failed to regenerate AI metadata')
            }
        } catch (err) {
            console.error('AI metadata regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate AI metadata'
                
                if (status === 403) {
                    setAiMetadataError('You do not have permission to regenerate AI metadata')
                } else if (status === 404) {
                    setAiMetadataError('Asset not found')
                } else {
                    setAiMetadataError(errorMessage)
                }
            } else {
                setAiMetadataError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingAiMetadata(false)
        }
    }
    
    // Handle AI Tagging Regeneration
    const handleRegenerateAiTagging = async () => {
        if (!asset?.id || !canRegenerateAiMetadataForTroubleshooting) return
        
        setRegeneratingAiTagging(true)
        setAiTaggingError(null)
        setAiTaggingSuccess(false)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/ai-tagging/regenerate`)
            
            if (response.data.success) {
                setAiTaggingSuccess(true)
                setTimeout(() => {
                    fetchMetadata()
                    setAiTaggingSuccess(false)
                }, 2000)
            } else {
                setAiTaggingError(response.data.error || 'Failed to regenerate AI tagging')
            }
        } catch (err) {
            console.error('AI tagging regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate AI tagging'
                
                if (status === 403) {
                    setAiTaggingError('You do not have permission to regenerate AI tagging')
                } else if (status === 404) {
                    setAiTaggingError('Asset not found')
                } else {
                    setAiTaggingError(errorMessage)
                }
            } else {
                setAiTaggingError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingAiTagging(false)
        }
    }
    
    // Handle Publish
    const handlePublish = async () => {
        if (!asset?.id || !canPublish) return
        
        setPublishing(true)
        setLifecycleError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/publish`)
            
            if (response.status === 200) {
                // Refresh page to get updated asset data
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                throw new Error(response.data?.message || 'Failed to publish asset')
            }
        } catch (err) {
            const errorMessage = err.response?.data?.message || err.message || 'Failed to publish asset'
            setLifecycleError(errorMessage)
            setPublishing(false)
        }
    }
    
    // Handle Unpublish
    const handleUnpublish = async () => {
        if (!asset?.id || !canUnpublish) return
        
        setUnpublishing(true)
        setLifecycleError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/unpublish`)
            
            if (response.status === 200) {
                // Refresh page to get updated asset data
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                throw new Error(response.data?.message || 'Failed to unpublish asset')
            }
        } catch (err) {
            const errorMessage = err.response?.data?.message || err.message || 'Failed to unpublish asset'
            setLifecycleError(errorMessage)
            setUnpublishing(false)
        }
    }
    
    // Handle Archive
    const handleArchive = async () => {
        if (!asset?.id || !canArchive) return
        
        setArchiving(true)
        setLifecycleError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/archive`)
            
            if (response.status === 200) {
                // Refresh page to get updated asset data
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                throw new Error(response.data?.message || 'Failed to archive asset')
            }
        } catch (err) {
            const errorMessage = err.response?.data?.message || err.message || 'Failed to archive asset'
            setLifecycleError(errorMessage)
            setArchiving(false)
        }
    }
    
    // Handle Restore
    const handleRestore = async () => {
        if (!asset?.id || !canRestore) return
        
        setRestoring(true)
        setLifecycleError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/restore`)
            
            if (response.status === 200) {
                // Refresh page to get updated asset data
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                throw new Error(response.data?.message || 'Failed to restore asset')
            }
        } catch (err) {
            const errorMessage = err.response?.data?.message || err.message || 'Failed to restore asset'
            setLifecycleError(errorMessage)
            setRestoring(false)
        }
    }
    
    // Handle Thumbnail Regeneration
    const handleRegenerateThumbnails = async () => {
        if (!asset?.id || !canRegenerateThumbnailsAdmin || selectedThumbnailStyles.length === 0) return
        
        setRegeneratingThumbnails(true)
        setThumbnailError(null)
        setShowThumbnailDropdown(false)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-styles`, {
                styles: selectedThumbnailStyles,
                force_imagick: forceImageMagick
            })
            
            if (response.data.success) {
                // Refresh metadata to show updated thumbnails
                fetchMetadata()
                // Refresh page to show updated thumbnails
                router.reload({ only: ['asset', 'auth'], preserveState: false })
            } else {
                setThumbnailError(response.data.error || 'Failed to regenerate thumbnails')
            }
        } catch (err) {
            console.error('Thumbnail regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate thumbnails'
                
                if (status === 403) {
                    setThumbnailError('You do not have permission to regenerate thumbnails')
                } else if (status === 404) {
                    setThumbnailError('Asset not found')
                } else {
                    setThumbnailError(errorMessage)
                }
            } else {
                setThumbnailError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingThumbnails(false)
        }
    }
    
    // Phase V-1: Handle Video Thumbnail Regeneration
    const handleRegenerateVideoThumbnail = async () => {
        if (!asset?.id) return
        
        setRegeneratingVideoThumbnail(true)
        setVideoThumbnailError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-video-thumbnail`)
            
            if (response.data.success) {
                // Refresh page to show updated thumbnail
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                setVideoThumbnailError(response.data.error || 'Failed to regenerate video thumbnail')
            }
        } catch (err) {
            console.error('Video thumbnail regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate video thumbnail'
                
                if (status === 403) {
                    setVideoThumbnailError('You do not have permission to regenerate video thumbnails')
                } else if (status === 404) {
                    setVideoThumbnailError('Asset not found')
                } else if (status === 422) {
                    setVideoThumbnailError(errorMessage)
                } else {
                    setVideoThumbnailError(errorMessage)
                }
            } else {
                setVideoThumbnailError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingVideoThumbnail(false)
        }
    }
    
    // Phase V-1: Handle Video Preview Regeneration
    const handleRegenerateVideoPreview = async () => {
        if (!asset?.id) return
        
        setRegeneratingVideoPreview(true)
        setVideoPreviewError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${asset.id}/thumbnails/regenerate-video-preview`)
            
            if (response.data.success) {
                // Refresh page to show updated preview
                router.reload({ 
                    preserveState: true,
                    preserveScroll: true,
                })
            } else {
                setVideoPreviewError(response.data.error || 'Failed to regenerate video preview')
            }
        } catch (err) {
            console.error('Video preview regeneration error:', err)
            
            if (err.response) {
                const status = err.response.status
                const errorMessage = err.response.data?.error || 'Failed to regenerate video preview'
                
                if (status === 403) {
                    setVideoPreviewError('You do not have permission to regenerate video previews')
                } else if (status === 404) {
                    setVideoPreviewError('Asset not found')
                } else if (status === 422) {
                    setVideoPreviewError(errorMessage)
                } else {
                    setVideoPreviewError(errorMessage)
                }
            } else {
                setVideoPreviewError('Network error. Please try again.')
            }
        } finally {
            setRegeneratingVideoPreview(false)
        }
    }
    
    // Handle Remove Preview
    const handleRemovePreview = async () => {
        if (!asset?.id) {
            setRemovePreviewError('Asset ID is missing')
            return
        }
        
        setRemovePreviewLoading(true)
        setRemovePreviewError(null)
        
        try {
            console.log('[AssetDetailsModal] Attempting to remove preview thumbnails for asset:', asset.id)
            console.log('[AssetDetailsModal] Asset data:', {
                id: asset.id,
                preview_thumbnail_url: asset.preview_thumbnail_url,
                metadata: asset.metadata,
                metadata_preview_thumbnails: asset.metadata?.preview_thumbnails
            })
            
            const response = await window.axios.delete(`/app/assets/${asset.id}/thumbnails/preview`, {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                }
            })
            
            console.log('[AssetDetailsModal] Remove preview response:', response)
            console.log('[AssetDetailsModal] Response status:', response.status)
            console.log('[AssetDetailsModal] Response data:', response.data)
            
            // Check for success in response - backend returns { success: true, message: ... }
            if (response.data?.success === true || response.status === 200) {
                const message = response.data?.message || 'Preview thumbnails removed successfully'
                console.log('[AssetDetailsModal] Preview thumbnails removed successfully:', message)
                
                // Check if there were actually any thumbnails to remove
                if (message.includes('No preview thumbnails to remove')) {
                    setRemovePreviewError('No preview thumbnails found to remove')
                    setRemovePreviewLoading(false)
                    return
                }
                
                // Refresh metadata first
                await fetchMetadata()
                // Then reload the page to show updated thumbnails
                router.reload({ 
                    preserveScroll: true,
                    only: ['asset', 'auth'],
                    onSuccess: () => {
                        console.log('[AssetDetailsModal] Page reloaded after preview removal')
                        setRemovePreviewLoading(false)
                    },
                    onError: (error) => {
                        console.error('[AssetDetailsModal] Error reloading page:', error)
                        setRemovePreviewError('Preview removed but page reload failed')
                        setRemovePreviewLoading(false)
                    }
                })
            } else {
                const errorMsg = response.data?.error || response.data?.message || 'Failed to remove preview thumbnails'
                console.error('[AssetDetailsModal] Remove preview failed:', errorMsg)
                throw new Error(errorMsg)
            }
        } catch (error) {
            console.error('[AssetDetailsModal] Failed to remove preview thumbnails', error)
            console.error('[AssetDetailsModal] Error details:', {
                message: error.message,
                response: error.response,
                status: error.response?.status,
                data: error.response?.data
            })
            
            const errorMessage = error.response?.data?.error || 
                                error.response?.data?.message || 
                                error.message || 
                                'Failed to remove preview thumbnails'
            setRemovePreviewError(errorMessage)
            setRemovePreviewLoading(false)
        }
    }

    if (!isOpen) return null

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            {/* Backdrop */}
            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={onClose} />

            {/* Modal */}
            <div className="flex min-h-full items-center justify-center p-4">
                <div className="relative transform overflow-hidden rounded-lg bg-white shadow-xl transition-all w-full max-w-4xl max-h-[90vh] flex flex-col">
                    {/* Header */}
                    <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4">
                        <div className="flex items-center justify-between mb-3">
                            <h3 id="modal-title" className="text-lg font-semibold text-gray-900">
                                Asset Details - {asset?.title || asset?.original_filename || 'Asset'}
                            </h3>
                            <div className="flex items-center gap-3">
                                {/* Lifecycle Error Message */}
                                {lifecycleError && (
                                    <div className="px-3 py-2 bg-red-50 border border-red-200 rounded-md text-sm text-red-800">
                                        {lifecycleError}
                                    </div>
                                )}
                                
                            {/* Actions Dropdown */}
                            {(canRegenerateAiMetadataForTroubleshooting || canRegenerateThumbnailsAdmin || canPublishWithFallback || canUnpublishWithFallback || canArchiveWithFallback || canRestoreWithFallback || isVideo) && (
                                    <div className="relative" ref={actionsDropdownRef}>
                                    <button
                                        type="button"
                                        onClick={() => setShowActionsDropdown(!showActionsDropdown)}
                                        className="inline-flex items-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                                    >
                                        Actions
                                        <ChevronDownIcon className={`ml-2 h-4 w-4 transition-transform ${showActionsDropdown ? 'rotate-180' : ''}`} />
                                    </button>
                                    
                                    {/* Dropdown menu */}
                                    {showActionsDropdown && (
                                        <div className="absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                            <div className="py-1">
                                                {/* Lifecycle Actions Section */}
                                                {(canPublishWithFallback || canUnpublishWithFallback || canArchiveWithFallback || canRestoreWithFallback) && (
                                                    <>
                                                        {/* Publish */}
                                                        {canPublishWithFallback && !asset?.published_at && !asset?.archived_at && (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setShowActionsDropdown(false)
                                                                    handlePublish()
                                                                }}
                                                                disabled={publishing || unpublishing || archiving || restoring}
                                                                className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                            >
                                                                <CheckCircleIcon className={`h-4 w-4 ${publishing ? 'animate-spin' : ''}`} />
                                                                Publish
                                                            </button>
                                                        )}
                                                        
                                                        {/* Unpublish */}
                                                        {canUnpublishWithFallback && asset?.published_at && !asset?.archived_at && (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setShowActionsDropdown(false)
                                                                    handleUnpublish()
                                                                }}
                                                                disabled={publishing || unpublishing || archiving || restoring}
                                                                className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                            >
                                                                <XCircleIcon className={`h-4 w-4 ${unpublishing ? 'animate-spin' : ''}`} />
                                                                Unpublish
                                                            </button>
                                                        )}
                                                        
                                                        {/* Archive */}
                                                        {canArchiveWithFallback && !asset?.archived_at && (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setShowActionsDropdown(false)
                                                                    handleArchive()
                                                                }}
                                                                disabled={publishing || unpublishing || archiving || restoring}
                                                                className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                            >
                                                                <ArchiveBoxIcon className={`h-4 w-4 ${archiving ? 'animate-spin' : ''}`} />
                                                                Archive
                                                            </button>
                                                        )}
                                                        
                                                        {/* Restore */}
                                                        {canRestoreWithFallback && asset?.archived_at && (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setShowActionsDropdown(false)
                                                                    handleRestore()
                                                                }}
                                                                disabled={publishing || unpublishing || archiving || restoring}
                                                                className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                            >
                                                                <ArrowUturnLeftIcon className={`h-4 w-4 ${restoring ? 'animate-spin' : ''}`} />
                                                                Restore
                                                            </button>
                                                        )}
                                                        
                                                        {/* Divider if there are other actions */}
                                                        {(canRegenerateAiMetadataForTroubleshooting || canRegenerateThumbnailsAdmin) && (
                                                            <div className="border-t border-gray-200 my-1" />
                                                        )}
                                                    </>
                                                )}
                                                
                                                {/* System Metadata Regeneration */}
                                                {canRegenerateAiMetadataForTroubleshooting && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowActionsDropdown(false)
                                                            handleRegenerateSystemMetadata()
                                                        }}
                                                        disabled={regeneratingSystemMetadata}
                                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <ArrowPathIcon className={`h-4 w-4 ${regeneratingSystemMetadata ? 'animate-spin' : ''}`} />
                                                        Regenerate System Metadata
                                                    </button>
                                                )}
                                                
                                                {/* AI Metadata Regeneration */}
                                                {canRegenerateAiMetadataForTroubleshooting && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowActionsDropdown(false)
                                                            handleRegenerateAiMetadata()
                                                        }}
                                                        disabled={regeneratingAiMetadata}
                                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <ArrowPathIcon className={`h-4 w-4 ${regeneratingAiMetadata ? 'animate-spin' : ''}`} />
                                                        Regenerate AI Metadata
                                                    </button>
                                                )}
                                                
                                                {/* AI Tagging Regeneration */}
                                                {canRegenerateAiMetadataForTroubleshooting && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowActionsDropdown(false)
                                                            handleRegenerateAiTagging()
                                                        }}
                                                        disabled={regeneratingAiTagging}
                                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <ArrowPathIcon className={`h-4 w-4 ${regeneratingAiTagging ? 'animate-spin' : ''}`} />
                                                        Regenerate AI Tagging
                                                    </button>
                                                )}
                                                
                                                {/* Phase V-1: Video-specific regeneration options */}
                                                {isVideo && (
                                                    <>
                                                        {/* Divider if there are other actions above */}
                                                        {(canRegenerateAiMetadataForTroubleshooting || canRegenerateThumbnailsAdmin) && (
                                                            <div className="border-t border-gray-200 my-1" />
                                                        )}
                                                        
                                                        {/* Regenerate Video Thumbnail */}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setShowActionsDropdown(false)
                                                                handleRegenerateVideoThumbnail()
                                                            }}
                                                            disabled={regeneratingVideoThumbnail}
                                                            className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                        >
                                                            <ArrowPathIcon className={`h-4 w-4 ${regeneratingVideoThumbnail ? 'animate-spin' : ''}`} />
                                                            Regenerate Video Thumbnail
                                                        </button>
                                                        
                                                        {/* Regenerate Video Preview */}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setShowActionsDropdown(false)
                                                                handleRegenerateVideoPreview()
                                                            }}
                                                            disabled={regeneratingVideoPreview}
                                                            className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                        >
                                                            <ArrowPathIcon className={`h-4 w-4 ${regeneratingVideoPreview ? 'animate-spin' : ''}`} />
                                                            Regenerate Preview Video
                                                        </button>
                                                        
                                                        {/* Divider if there are other actions below */}
                                                        {(canRegenerateThumbnailsAdmin || supportsThumbnail(asset?.mime_type, asset?.file_extension || asset?.original_filename?.split('.').pop())) && (
                                                            <div className="border-t border-gray-200 my-1" />
                                                        )}
                                                    </>
                                                )}
                                                
                                                {/* Remove Preview */}
                                                {supportsThumbnail(asset?.mime_type, asset?.file_extension || asset?.original_filename?.split('.').pop()) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowActionsDropdown(false)
                                                            handleRemovePreview()
                                                        }}
                                                        disabled={removePreviewLoading}
                                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                                                    >
                                                        <TrashIcon className={`h-4 w-4 ${removePreviewLoading ? 'animate-spin' : ''}`} />
                                                        Remove Preview
                                                    </button>
                                                )}
                                                
                                                {/* Thumbnail Management */}
                                                {canRegenerateThumbnailsAdmin && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setShowActionsDropdown(false)
                                                            setShowThumbnailManagement(!showThumbnailManagement)
                                                        }}
                                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2"
                                                    >
                                                        <ArrowPathIcon className="h-4 w-4" />
                                                        Thumbnail Management
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                    </div>
                                )}
                                
                                {/* Close button */}
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    <span className="sr-only">Close</span>
                                    <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Content */}
                    <div className="flex-1 overflow-y-auto px-6 py-6">
                        {loading && (
                            <div className="text-center py-8">
                                <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                                <p className="mt-2 text-sm text-gray-500">Loading metadata...</p>
                            </div>
                        )}

                        {error && (
                            <div className="rounded-md bg-red-50 p-4 mb-4">
                                <p className="text-sm text-red-800">{error}</p>
                            </div>
                        )}

                        {!loading && !error && metadata && (
                            <div className="space-y-6">
                                {/* Preview */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-3 hidden">Preview</h4>
                                    <div className="bg-gray-50 rounded-lg overflow-hidden border border-gray-200" style={{ aspectRatio: '16/9', minHeight: '200px' }}>
                                        {asset?.id && (
                                            <ThumbnailPreview
                                                asset={asset}
                                                alt={asset?.title || asset?.original_filename || 'Asset preview'}
                                                className="w-full h-full"
                                                size="lg"
                                            />
                                        )}
                                    </div>
                                    
                                    {/* Success/Error Messages */}
                                    {systemMetadataSuccess && (
                                        <div className="mt-4 rounded-md bg-green-50 p-3">
                                            <p className="text-sm text-green-800">System metadata regeneration queued successfully</p>
                                        </div>
                                    )}
                                    {systemMetadataError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{systemMetadataError}</p>
                                        </div>
                                    )}
                                    {aiMetadataSuccess && (
                                        <div className="mt-4 rounded-md bg-green-50 p-3">
                                            <p className="text-sm text-green-800">AI metadata regeneration queued successfully</p>
                                        </div>
                                    )}
                                    {aiMetadataError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{aiMetadataError}</p>
                                        </div>
                                    )}
                                    {aiTaggingSuccess && (
                                        <div className="mt-4 rounded-md bg-green-50 p-3">
                                            <p className="text-sm text-green-800">AI tagging regeneration queued successfully</p>
                                        </div>
                                    )}
                                    {aiTaggingError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{aiTaggingError}</p>
                                        </div>
                                    )}
                                    {removePreviewError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{removePreviewError}</p>
                                        </div>
                                    )}
                                    {/* Phase V-1: Video regeneration error messages */}
                                    {videoThumbnailError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{videoThumbnailError}</p>
                                        </div>
                                    )}
                                    {videoPreviewError && (
                                        <div className="mt-4 rounded-md bg-red-50 p-3">
                                            <p className="text-sm text-red-800">{videoPreviewError}</p>
                                        </div>
                                    )}
                                    
                                    {/* Thumbnail Management Section */}
                                    {showThumbnailManagement && canRegenerateThumbnailsAdmin && (
                                        <div className="mt-4">
                                            <div>
                                                <button
                                                    type="button"
                                                    onClick={() => setShowThumbnailManagement(false)}
                                                    className="w-full px-4 py-2 flex items-center justify-between text-left text-sm font-medium text-gray-700 bg-white rounded-md border border-gray-300 shadow-sm hover:bg-gray-50"
                                                >
                                                    <span>Thumbnail Management</span>
                                                    <ChevronDownIcon className="h-4 w-4 text-gray-500 transition-transform rotate-180" />
                                                </button>
                                                    
                                                <div className="mt-2 p-4 bg-white border border-gray-200 rounded-md">
                                                    <div className="relative" ref={thumbnailDropdownRef}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowThumbnailDropdown(!showThumbnailDropdown)}
                                                            disabled={regeneratingThumbnails}
                                                            className="inline-flex items-center justify-between w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                        >
                                                            <span>
                                                                {regeneratingThumbnails ? 'Regenerating...' : 'Regenerate Thumbnails'}
                                                            </span>
                                                            <ChevronDownIcon className={`h-4 w-4 ml-2 transition-transform ${showThumbnailDropdown ? 'rotate-180' : ''}`} />
                                                        </button>
                                                        
                                                        {/* Dropdown menu */}
                                                        {showThumbnailDropdown && !regeneratingThumbnails && (
                                                            <div className="absolute z-10 mt-1 w-full rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                                                <div className="py-2 px-3">
                                                                    <p className="text-xs font-medium text-gray-700 mb-2">Select styles to regenerate:</p>
                                                                    
                                                                    <div className="space-y-2">
                                                                        {availableThumbnailStyles.map((style) => (
                                                                            <label
                                                                                key={style.name}
                                                                                className="flex items-start gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                                                                            >
                                                                                <input
                                                                                    type="checkbox"
                                                                                    checked={selectedThumbnailStyles.includes(style.name)}
                                                                                    onChange={(e) => {
                                                                                        if (e.target.checked) {
                                                                                            setSelectedThumbnailStyles([...selectedThumbnailStyles, style.name])
                                                                                        } else {
                                                                                            setSelectedThumbnailStyles(selectedThumbnailStyles.filter(s => s !== style.name))
                                                                                        }
                                                                                    }}
                                                                                    className="mt-0.5 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                                                />
                                                                                <div className="flex-1">
                                                                                    <div className="text-xs font-medium text-gray-900">{style.label}</div>
                                                                                    <div className="text-xs text-gray-500">{style.description}</div>
                                                                                </div>
                                                                            </label>
                                                                        ))}
                                                                    </div>
                                                                    
                                                                    {/* Force ImageMagick option */}
                                                                    <div className="mt-3 pt-3 border-t border-gray-200">
                                                                        <label className="flex items-start gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                                                                            <input
                                                                                type="checkbox"
                                                                                checked={forceImageMagick}
                                                                                onChange={(e) => setForceImageMagick(e.target.checked)}
                                                                                className="mt-0.5"
                                                                            />
                                                                            <div className="flex-1">
                                                                                <div className="text-xs font-medium text-gray-900">Force ImageMagick</div>
                                                                                <div className="text-xs text-gray-500">Bypass file type checks (testing only)</div>
                                                                            </div>
                                                                        </label>
                                                                    </div>
                                                                    
                                                                    {thumbnailError && (
                                                                        <div className="mt-3 bg-red-50 border border-red-200 rounded-md p-2">
                                                                            <p className="text-xs text-red-800">{thumbnailError}</p>
                                                                        </div>
                                                                    )}
                                                                    
                                                                    <div className="mt-3 flex justify-end gap-2">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => setShowThumbnailDropdown(false)}
                                                                            className="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                                        >
                                                                            Cancel
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={handleRegenerateThumbnails}
                                                                            disabled={selectedThumbnailStyles.length === 0}
                                                                            className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                        >
                                                                            Regenerate Selected
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                    
                                                    <p className="mt-2 text-xs text-gray-500">
                                                        Site roles can regenerate specific thumbnail styles for troubleshooting or testing new file types.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Category */}
                                {metadata.category && (
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Category</h4>
                                        <p className="text-sm font-semibold text-gray-900">{metadata.category.name}</p>
                                    </div>
                                )}

                                {/* Phase L.4: Lifecycle Information (read-only) */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-2">Lifecycle</h4>
                                    <div className="space-y-2">
                                        {/* Lifecycle Badges */}
                                        <div className="flex flex-wrap gap-2">
                                            {/* Show all lifecycle badges independently */}
                                            {/* Archived badge - show if archived */}
                                            {asset?.archived_at && (
                                                <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">
                                                    Archived
                                                </span>
                                            )}
                                            {/* Published badge - show if published (regardless of archived status) */}
                                            {asset?.published_at && (
                                                <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-green-100 text-green-700 border border-green-300">
                                                    Published
                                                </span>
                                            )}
                                            {/* Unpublished badge - show if not published (regardless of archived status) */}
                                            {!asset?.published_at && (
                                                <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-300">
                                                    Unpublished
                                                </span>
                                            )}
                                        </div>
                                        
                                        {/* Lifecycle Details */}
                                        {/* Show all lifecycle details independently */}
                                        {asset?.published_at && (
                                            <div className="text-sm text-gray-600">
                                                <span className="font-medium text-gray-900">Published:</span>{' '}
                                                {new Date(asset.published_at).toLocaleDateString('en-US', {
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric',
                                                })}
                                                {asset.published_by && (
                                                    <span className="ml-2 text-gray-500">
                                                        by {asset.published_by.name || `${asset.published_by.first_name || ''} ${asset.published_by.last_name || ''}`.trim() || 'Unknown'}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                        {!asset?.published_at && (
                                            <div className="text-sm text-gray-600">
                                                <span className="font-medium text-gray-900">Unpublished</span>
                                                {asset?.category?.name && (
                                                    <span className="ml-2 text-gray-500">
                                                        ({asset.category.name})
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                        {asset?.archived_at && (
                                            <div className="text-sm text-gray-600">
                                                <span className="font-medium text-gray-900">Archived:</span>{' '}
                                                {new Date(asset.archived_at).toLocaleDateString('en-US', {
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric',
                                                })}
                                                {asset.archived_by && (
                                                    <span className="ml-2 text-gray-500">
                                                        by {asset.archived_by.name || `${asset.archived_by.first_name || ''} ${asset.archived_by.last_name || ''}`.trim() || 'Unknown'}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                        {/* Phase M: Expiration date display (read-only) */}
                        {asset?.expires_at && (
                            <div className="text-sm text-gray-600">
                                <span className="font-medium text-gray-900">
                                    {new Date(asset.expires_at) < new Date() ? 'Expired on:' : 'Expires on:'}
                                </span>{' '}
                                {new Date(asset.expires_at).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}
                            </div>
                        )}
                        {/* Phase AF-1: Approval information (read-only) */}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {auth?.approval_features?.approvals_enabled && asset?.approval_status === 'approved' && asset?.approved_at && (
                            <div className="text-sm text-gray-600">
                                <span className="font-medium text-gray-900">Approved on:</span>{' '}
                                {new Date(asset.approved_at).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}
                                {asset.approved_by && (
                                    <span className="ml-2 text-gray-500">
                                        by {asset.approved_by.name || 'Unknown'}
                                    </span>
                                )}
                            </div>
                        )}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {auth?.approval_features?.approvals_enabled && asset?.approval_status === 'rejected' && asset?.rejected_at && asset?.approval_capable && (
                            <div className="text-sm text-gray-600">
                                <span className="font-medium text-gray-900">Rejected on:</span>{' '}
                                {new Date(asset.rejected_at).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}
                                {asset.rejection_reason && (
                                    <div className="mt-1 text-gray-500">
                                        Reason: {asset.rejection_reason}
                                    </div>
                                )}
                            </div>
                        )}
                                    </div>
                                </div>

                                {/* Dominant Colors standalone section removed - now displayed as a metadata field in "All Metadata Fields" */}

                                {/* Metadata Fields */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-3">All Metadata Fields</h4>
                                    <div className="space-y-1">
                                        {metadata && metadata.fields && metadata.fields.length > 0 ? (
                                            metadata.fields
                                                .filter((field) => field.key !== 'tags') // Hide Tags field as we show it separately below
                                                .map((field) => {
                                                const typeLabel = field.type + 
                                                    (field.population_mode !== 'manual' ? ` (${field.population_mode})` : '') +
                                                    (field.readonly ? ' (read-only)' : '') +
                                                    (field.is_ai_related ? ' (AI-related)' : '');
                                                
                                                // Special handling for dominant_colors - show color swatches
                                                const isDominantColors = (field.key === 'dominant_colors' || field.field_key === 'dominant_colors')
                                                
                                                // For dominant_colors, check if we have valid color objects
                                                let dominantColorsArray = null
                                                if (isDominantColors && Array.isArray(field.current_value) && field.current_value.length > 0) {
                                                    const validColors = field.current_value.filter(color => 
                                                        color && 
                                                        color.hex && 
                                                        Array.isArray(color.rgb) && 
                                                        color.rgb.length >= 3
                                                    )
                                                    if (validColors.length > 0) {
                                                        dominantColorsArray = validColors
                                                    }
                                                }
                                                
                                                const fieldHasValue = isDominantColors 
                                                    ? !!dominantColorsArray 
                                                    : hasValue(field.current_value, field.type)
                                                const formattedValue = isDominantColors 
                                                    ? null // Don't format, will show swatches
                                                    : formatValue(field.current_value, field.type)
                                                
                                                return (
                                                    <div
                                                        key={field.metadata_field_id}
                                                        className="flex items-start justify-between py-1.5 border-b border-gray-100 last:border-b-0"
                                                    >
                                                        <div className="flex-1 min-w-0">
                                                            <div className="text-sm text-gray-900">
                                                                <span className="flex items-center gap-2">
                                                                    <span className="text-gray-500">{field.display_label}</span>
                                                                    {/* Show pending badge if field has pending approval */}
                                                                    {((field.has_pending || field.is_value_pending) && 
                                                                     field.population_mode !== 'automatic' &&
                                                                     !field.readonly) && (
                                                                        <span
                                                                            className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                                                                            title="This field has pending changes awaiting approval"
                                                                        >
                                                                            Pending
                                                                        </span>
                                                                    )}
                                                                </span>
                                                                <span className="text-gray-400 text-xs ml-1">({typeLabel})</span>
                                                                {(formattedValue || dominantColorsArray) && (
                                                                    <>
                                                                        <span className="text-gray-400 mx-2">:</span>
                                                                        <span className="font-semibold text-gray-900">
                                                                            {dominantColorsArray ? (
                                                                                <DominantColorsSwatches dominantColors={dominantColorsArray} />
                                                                            ) : (
                                                                                formattedValue
                                                                            )}
                                                                        </span>
                                                                    </>
                                                                )}
                                                            </div>
                                                            {/* Defensive guard: Only show approval UI for non-automatic fields */}
                                                            {field.metadata && 
                                                             field.population_mode !== 'automatic' &&
                                                             !field.readonly &&
                                                             (field.metadata.approved_at || field.metadata.is_pending || field.is_value_pending || field.metadata.confidence !== null) && (
                                                                <div className="mt-1 text-xs">
                                                                    {/* Show pending status if value is pending approval */}
                                                                    {(field.metadata.is_pending || field.is_value_pending) && !field.metadata.approved_at && (
                                                                        <span className="text-amber-600 font-medium">
                                                                            Pending approval
                                                                        </span>
                                                                    )}
                                                                    {/* Show approved status if value is approved */}
                                                                    {field.metadata.approved_at && !(field.metadata.is_pending || field.is_value_pending) && (
                                                                        <span className="text-gray-400">
                                                                            {field.metadata.source === 'ai' ? 'AI suggestion accepted' : 'Approved'} {new Date(field.metadata.approved_at).toLocaleDateString()}
                                                                        </span>
                                                                    )}
                                                                    {/* Show confidence for AI fields */}
                                                                    {field.metadata.confidence !== null && (
                                                                        <span className={`${field.metadata.approved_at || field.metadata.is_pending || field.is_value_pending ? ' ml-2' : ''} text-gray-400`}>
                                                                            {field.metadata.confidence ? `${(field.metadata.confidence * 100).toFixed(0)}% confidence` : ''}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="ml-3 flex-shrink-0 flex items-center gap-2">
                                                            {getSourceBadge(field)}
                                                            {/* Approve/Reject buttons removed - use drawer's PendingMetadataList component instead */}
                                                            {/* The drawer's approval functionality works correctly and uses the proper /pending endpoint */}
                                                            {/* Show "Auto" badge for readonly/automatic fields */}
                                                            {(field.readonly || field.population_mode === 'automatic') && (
                                                                <span
                                                                    className="inline-flex items-center gap-1 text-xs text-gray-500"
                                                                    title="This field is automatically populated and cannot be edited"
                                                                >
                                                                    <LockClosedIcon className="h-3 w-3" />
                                                                    <span className="italic">Auto</span>
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                );
                                            })
                                        ) : (
                                            <p className="text-sm text-gray-500">No metadata fields available</p>
                                        )}
                                    </div>
                                </div>

                                {/* Tags Section - Detailed view at bottom */}
                                <div className="mt-6 pt-4 border-t border-gray-200">
                                    <AssetTagManager 
                                        asset={asset}
                                        showTitle={true}
                                        showInput={false}
                                        detailed={true}
                                        className="mb-4"
                                    />
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer */}
                    <div className="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-4 flex justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}

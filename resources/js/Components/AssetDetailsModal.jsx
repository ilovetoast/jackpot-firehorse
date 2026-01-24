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
import { useEffect, useState, useRef } from 'react'
import { XMarkIcon, ArrowPathIcon, ChevronDownIcon, TrashIcon } from '@heroicons/react/24/outline'
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
    
    // For troubleshooting: Also allow owners/admins even if permission check fails
    const tenantRole = auth?.tenant_role || null
    const isOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
    const canRegenerateAiMetadataForTroubleshooting = canRegenerateAiMetadata || isOwnerOrAdmin
    
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
    
    // Remove preview state
    const [removePreviewLoading, setRemovePreviewLoading] = useState(false)
    const [removePreviewError, setRemovePreviewError] = useState(null)
    
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
                    <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <h3 id="modal-title" className="text-lg font-semibold text-gray-900">
                            Asset Details - {asset?.title || asset?.original_filename || 'Asset'}
                        </h3>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        >
                            <span className="sr-only">Close</span>
                            <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                        </button>
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
                                    <h4 className="text-sm font-medium text-gray-900 mb-3">Preview</h4>
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
                                    
                                    {/* Action Buttons Section - Under Preview */}
                                    {(canRegenerateAiMetadataForTroubleshooting || canRegenerateThumbnailsAdmin) && (
                                        <div className="mt-4 space-y-3">
                                            {/* System Metadata Regeneration */}
                                            {canRegenerateAiMetadataForTroubleshooting && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        onClick={handleRegenerateSystemMetadata}
                                                        disabled={regeneratingSystemMetadata}
                                                        className="inline-flex items-center rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {regeneratingSystemMetadata ? (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                                Regenerating...
                                                            </>
                                                        ) : (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2" />
                                                                Regenerate System Metadata
                                                            </>
                                                        )}
                                                    </button>
                                                    {systemMetadataSuccess && (
                                                        <p className="mt-2 text-sm text-green-600">System metadata regeneration queued successfully</p>
                                                    )}
                                                    {systemMetadataError && (
                                                        <p className="mt-2 text-sm text-red-600">{systemMetadataError}</p>
                                                    )}
                                                    <p className="mt-1 text-xs text-gray-500">Orientation, Color Space, Resolution Class</p>
                                                </div>
                                            )}
                                            
                                            {/* AI Metadata Regeneration */}
                                            {canRegenerateAiMetadataForTroubleshooting && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        onClick={handleRegenerateAiMetadata}
                                                        disabled={regeneratingAiMetadata}
                                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {regeneratingAiMetadata ? (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                                Regenerating...
                                                            </>
                                                        ) : (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2" />
                                                                Regenerate AI Metadata
                                                            </>
                                                        )}
                                                    </button>
                                                    {aiMetadataSuccess && (
                                                        <p className="mt-2 text-sm text-green-600">AI metadata regeneration queued successfully</p>
                                                    )}
                                                    {aiMetadataError && (
                                                        <p className="mt-2 text-sm text-red-600">{aiMetadataError}</p>
                                                    )}
                                                    <p className="mt-1 text-xs text-gray-500">Photo Type and other AI-eligible fields</p>
                                                </div>
                                            )}
                                            
                                            {/* AI Tagging Regeneration */}
                                            {canRegenerateAiMetadataForTroubleshooting && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        onClick={handleRegenerateAiTagging}
                                                        disabled={regeneratingAiTagging}
                                                        className="inline-flex items-center rounded-md bg-purple-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {regeneratingAiTagging ? (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                                Regenerating...
                                                            </>
                                                        ) : (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2" />
                                                                Regenerate AI Tagging
                                                            </>
                                                        )}
                                                    </button>
                                                    {aiTaggingSuccess && (
                                                        <p className="mt-2 text-sm text-green-600">AI tagging regeneration queued successfully</p>
                                                    )}
                                                    {aiTaggingError && (
                                                        <p className="mt-2 text-sm text-red-600">{aiTaggingError}</p>
                                                    )}
                                                    <p className="mt-1 text-xs text-gray-500">General/freeform tags (not yet fully implemented)</p>
                                                </div>
                                            )}
                                            
                                            {/* Remove Preview */}
                                            {/* Show button if asset supports thumbnails (backend will handle if none exist) */}
                                            {supportsThumbnail(asset?.mime_type, asset?.file_extension || asset?.original_filename?.split('.').pop()) && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        onClick={handleRemovePreview}
                                                        disabled={removePreviewLoading}
                                                        className="inline-flex items-center rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    >
                                                        {removePreviewLoading ? (
                                                            <>
                                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                                Removing...
                                                            </>
                                                        ) : (
                                                            <>
                                                                <TrashIcon className="h-4 w-4 mr-2" />
                                                                Remove Preview
                                                            </>
                                                        )}
                                                    </button>
                                                    {removePreviewError && (
                                                        <p className="mt-2 text-sm text-red-600">{removePreviewError}</p>
                                                    )}
                                                    <p className="mt-1 text-xs text-gray-500">Remove preview thumbnails to force the file type icon to display instead</p>
                                                </div>
                                            )}
                                            
                                            {/* Thumbnail Management */}
                                            {canRegenerateThumbnailsAdmin && (
                                                <div>
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowThumbnailManagement(!showThumbnailManagement)}
                                                        className="w-full px-4 py-2 flex items-center justify-between text-left text-sm font-medium text-gray-700 bg-white rounded-md border border-gray-300 shadow-sm hover:bg-gray-50"
                                                    >
                                                        <span>Thumbnail Management</span>
                                                        <ChevronDownIcon className={`h-4 w-4 text-gray-500 transition-transform ${showThumbnailManagement ? 'rotate-180' : ''}`} />
                                                    </button>
                                                    
                                                    {showThumbnailManagement && (
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
                                                    )}
                                                </div>
                                            )}
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

                                {/* Dominant Colors */}
                                {asset?.metadata?.dominant_colors && Array.isArray(asset.metadata.dominant_colors) && asset.metadata.dominant_colors.length > 0 && (
                                    <div>
                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Dominant Colors</h4>
                                        <div className="text-sm text-gray-700">
                                            <DominantColorsSwatches dominantColors={asset.metadata.dominant_colors} />
                                        </div>
                                    </div>
                                )}

                                {/* Metadata Fields */}
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 mb-3">All Metadata Fields</h4>
                                    <div className="space-y-1">
                                        {metadata.fields && metadata.fields.length > 0 ? (
                                            metadata.fields
                                                .filter((field) => field.key !== 'tags') // Hide Tags field as we show it separately below
                                                .map((field) => {
                                                const typeLabel = field.type + 
                                                    (field.population_mode !== 'manual' ? ` (${field.population_mode})` : '') +
                                                    (field.readonly ? ' (read-only)' : '') +
                                                    (field.is_ai_related ? ' (AI-related)' : '');
                                                
                                                const fieldHasValue = hasValue(field.current_value, field.type)
                                                const formattedValue = formatValue(field.current_value, field.type)
                                                
                                                return (
                                                    <div
                                                        key={field.metadata_field_id}
                                                        className="flex items-start justify-between py-1.5 border-b border-gray-100 last:border-b-0"
                                                    >
                                                        <div className="flex-1 min-w-0">
                                                            <div className="text-sm text-gray-900">
                                                                <span className="text-gray-500">{field.display_label}</span>
                                                                <span className="text-gray-400 text-xs ml-1">({typeLabel})</span>
                                                                {formattedValue && (
                                                                    <>
                                                                        <span className="text-gray-400 mx-2">:</span>
                                                                        <span className="font-semibold text-gray-900">
                                                                            {formattedValue}
                                                                        </span>
                                                                    </>
                                                                )}
                                                            </div>
                                                            {field.metadata && (field.metadata.approved_at || field.metadata.confidence !== null) && (
                                                                <div className="mt-1 text-xs text-gray-400">
                                                                    {field.metadata.approved_at && (
                                                                        <span>
                                                                            {field.metadata.source === 'ai' ? 'AI suggestion accepted' : 'Approved'} {new Date(field.metadata.approved_at).toLocaleDateString()}
                                                                        </span>
                                                                    )}
                                                                    {field.metadata.confidence !== null && (
                                                                        <span className={field.metadata.approved_at ? ' ml-2' : ''}>
                                                                            {field.metadata.confidence ? `${(field.metadata.confidence * 100).toFixed(0)}% confidence` : ''}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="ml-3 flex-shrink-0">
                                                            {getSourceBadge(field)}
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

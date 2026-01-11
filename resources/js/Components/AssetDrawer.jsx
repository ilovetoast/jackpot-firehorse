/**
 * AssetDrawer Component
 * 
 * Right-side drawer panel for displaying asset details.
 * Pushes the grid content when open (desktop/tablet), overlays on mobile.
 * 
 * Features:
 * - Large preview using /app/assets/{id}/thumbnail/medium
 * - Asset header (title, file type, status indicators)
 * - Metadata summary (category, file size, MIME type, created date)
 * - Activity timeline
 * - Processing state (thumbnail status, errors)
 * - Keyboard accessible (Esc to close)
 * - Focus trap on mobile
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with id, title, metadata, etc.
 * @param {Function} props.onClose - Callback when drawer should close
 */
import { useEffect, useRef, useState } from 'react'
import { XMarkIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import AssetImage from './AssetImage'
import AssetTimeline from './AssetTimeline'

export default function AssetDrawer({ asset, onClose }) {
    const drawerRef = useRef(null)
    const closeButtonRef = useRef(null)
    const [showZoomModal, setShowZoomModal] = useState(false)
    const [activityEvents, setActivityEvents] = useState([])
    const [activityLoading, setActivityLoading] = useState(false)
    // Track layout settling to prevent preview jump during grid recalculation
    // When drawer opens, grid container padding animates (300ms), causing CSS Grid to recalculate
    // This state delays preview render until layout stabilizes
    const [isLayoutSettling, setIsLayoutSettling] = useState(true)

    // Handle ESC key to close drawer
    useEffect(() => {
        const handleEscape = (e) => {
            if (e.key === 'Escape') {
                onClose()
            }
        }

        document.addEventListener('keydown', handleEscape)
        return () => {
            document.removeEventListener('keydown', handleEscape)
        }
    }, [onClose])

    // Delay preview render until grid layout settles
    // Grid container padding animates over 300ms when drawer opens
    // Delay by 350ms to allow layout recalculation to complete
    useEffect(() => {
        if (!asset) {
            setIsLayoutSettling(true)
            return
        }

        setIsLayoutSettling(true)
        const timer = setTimeout(() => {
            setIsLayoutSettling(false)
        }, 350) // Slightly longer than grid transition (300ms)

        return () => clearTimeout(timer)
    }, [asset])

    // Fetch activity events when asset is set
    useEffect(() => {
        if (!asset || !asset.id) {
            setActivityEvents([])
            setActivityLoading(false)
            return
        }

        setActivityLoading(true)
        window.axios.get(`/app/assets/${asset.id}/activity`)
            .then(response => {
                if (response.data && response.data.events) {
                    setActivityEvents(response.data.events)
                } else {
                    setActivityEvents([])
                }
                setActivityLoading(false)
            })
            .catch(error => {
                console.error('Error fetching activity events:', error)
                setActivityEvents([])
                setActivityLoading(false)
            })
    }, [asset])

    // Focus trap on mobile (when drawer is full-width)
    useEffect(() => {
        if (!drawerRef.current) return

        const handleTab = (e) => {
            if (e.key !== 'Tab') return

            const focusableElements = drawerRef.current.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            )
            const firstElement = focusableElements[0]
            const lastElement = focusableElements[focusableElements.length - 1]

            if (e.shiftKey) {
                if (document.activeElement === firstElement) {
                    e.preventDefault()
                    lastElement?.focus()
                }
            } else {
                if (document.activeElement === lastElement) {
                    e.preventDefault()
                    firstElement?.focus()
                }
            }
        }

        // Only trap focus on mobile (when drawer is full-width)
        const isMobile = window.innerWidth < 768
        if (isMobile) {
            drawerRef.current.addEventListener('keydown', handleTab)
            closeButtonRef.current?.focus()
        }

        return () => {
            drawerRef.current?.removeEventListener('keydown', handleTab)
        }
    }, [])

    if (!asset) {
        return null
    }

    // Extract file extension
    const fileExtension = asset.file_extension || asset.original_filename?.split('.').pop()?.toUpperCase() || 'FILE'
    const isImage = asset.mime_type?.startsWith('image/') || ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF'].includes(fileExtension.toUpperCase())

    // Check thumbnail status
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status
    const thumbnailsComplete = thumbnailStatus === 'completed' || !thumbnailStatus
    const thumbnailsProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'
    const thumbnailsFailed = thumbnailStatus === 'failed'

    // Check processing state
    const isProcessing = asset.status && asset.status !== 'completed'
    const isCompleted = asset.status === 'completed'

    // Format file size
    const formatFileSize = (bytes) => {
        if (!bytes) return 'Unknown size'
        if (bytes < 1024) return `${bytes} B`
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(2)} KB`
        if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(2)} MB`
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
    }

    // Format date
    const formatDate = (dateString) => {
        if (!dateString) return 'Unknown date'
        try {
            const date = new Date(dateString)
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
            })
        } catch (e) {
            return 'Unknown date'
        }
    }

    // Get category name
    const categoryName = asset.category?.name || 'Uncategorized'

    return (
        <div
            ref={drawerRef}
            className="fixed inset-y-0 right-0 z-50 bg-white shadow-xl overflow-y-auto w-full md:w-auto"
            style={{
                maxWidth: '480px',
            }}
            role="dialog"
            aria-modal="true"
            aria-labelledby="drawer-title"
        >
            {/* Header */}
            <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                <h2 id="drawer-title" className="text-lg font-semibold text-gray-900 truncate pr-4">
                    {asset.title || asset.original_filename || 'Asset Details'}
                </h2>
                <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    className="flex-shrink-0 rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    aria-label="Close drawer"
                >
                    <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                </button>
            </div>

            {/* Content */}
            <div className="px-6 py-6 space-y-6">
                {/* Large Preview */}
                <div className="space-y-3">
                    <h3 className="text-sm font-medium text-gray-900">Preview</h3>
                    
                    <div className="bg-gray-100 rounded-lg overflow-hidden border border-gray-200 flex items-center justify-center" style={{ aspectRatio: '16/9', minHeight: '240px' }}>
                        {thumbnailsProcessing ? (
                            <div className="text-center">
                                <ArrowPathIcon className="h-12 w-12 text-gray-400 mx-auto animate-spin" />
                                <p className="mt-3 text-sm text-gray-500">Processing preview...</p>
                                <p className="mt-1 text-xs text-gray-400">This may take a moment</p>
                            </div>
                        ) : thumbnailsFailed ? (
                            <div className="text-center p-6">
                                <div className="text-red-500 text-sm font-medium mb-2">Thumbnail generation failed</div>
                                {asset.thumbnail_error && (
                                    <p className="text-xs text-gray-500">{asset.thumbnail_error}</p>
                                )}
                            </div>
                        ) : isImage && asset.id && thumbnailsComplete ? (
                            <div 
                                className={`relative transition-opacity duration-200 ${isLayoutSettling ? 'opacity-0' : 'opacity-100'}`}
                            >
                                <div
                                    className="aspect-video bg-gray-100 rounded-lg overflow-hidden border border-gray-200 cursor-pointer group w-full h-full"
                                    onClick={() => setShowZoomModal(true)}
                                >
                                    <AssetImage
                                        assetId={asset.id}
                                        alt={asset.title || asset.original_filename || 'Asset preview'}
                                        className="w-full h-full object-contain"
                                        containerWidth={448}
                                        lazy={false}
                                    />
                                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                                        <span className="text-white text-sm font-medium">Click to zoom</span>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="text-center p-6">
                                <div className="text-gray-400 text-sm font-medium mb-2 uppercase">{fileExtension}</div>
                                <p className="text-xs text-gray-500">Preview not available</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* File Information */}
                <div className="space-y-3 border-t border-gray-200 pt-6">
                    <h3 className="text-sm font-medium text-gray-900">File Information</h3>
                    <dl className="space-y-3">
                        <div className="flex justify-between">
                            <dt className="text-sm text-gray-500">File Type</dt>
                            <dd className="text-sm font-medium text-gray-900 uppercase">
                                {fileExtension || 'Unknown'}
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-sm text-gray-500">File Size</dt>
                            <dd className="text-sm font-medium text-gray-900">
                                {formatFileSize(asset.size_bytes)}
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-sm text-gray-500">Status</dt>
                            <dd className="text-sm font-medium">
                                <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                                    isCompleted
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-yellow-100 text-yellow-800'
                                }`}>
                                    {isCompleted ? 'Completed' : 'Processing'}
                                </span>
                            </dd>
                        </div>
                        {asset.created_at && (
                            <div className="flex justify-between">
                                <dt className="text-sm text-gray-500">Uploaded</dt>
                                <dd className="text-sm font-medium text-gray-900">
                                    {formatDate(asset.created_at)}
                                </dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt className="text-sm text-gray-500">Category</dt>
                            <dd className="text-sm font-medium text-gray-900">
                                {categoryName}
                            </dd>
                        </div>
                        {asset.uploaded_by && (
                            <div>
                                <dt className="text-sm text-gray-500 mb-1">Uploaded By</dt>
                                <dd className="flex items-center space-x-2">
                                    {asset.uploaded_by.avatar_url ? (
                                        <img
                                            src={asset.uploaded_by.avatar_url}
                                            alt={asset.uploaded_by.name || 'User'}
                                            className="h-6 w-6 rounded-full object-cover"
                                        />
                                    ) : (
                                        <div className="h-6 w-6 rounded-full bg-gray-300 flex items-center justify-center">
                                            <span className="text-xs font-medium text-gray-600">
                                                {(asset.uploaded_by.first_name?.[0] || asset.uploaded_by.name?.[0] || '?').toUpperCase()}
                                            </span>
                                        </div>
                                    )}
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium text-gray-900">
                                            {asset.uploaded_by.name || `${asset.uploaded_by.first_name || ''} ${asset.uploaded_by.last_name || ''}`.trim() || 'Unknown User'}
                                        </span>
                                        {asset.uploaded_by.email && (
                                            <span className="text-xs text-gray-500">
                                                {asset.uploaded_by.email}
                                            </span>
                                        )}
                                    </div>
                                </dd>
                            </div>
                        )}
                        {asset.original_filename && (
                            <div>
                                <dt className="text-sm text-gray-500 mb-1">Filename</dt>
                                <dd className="text-sm font-mono text-gray-700 break-all">
                                    {asset.original_filename}
                                </dd>
                            </div>
                        )}
                    </dl>
                </div>

                {/* Processing State */}
                {thumbnailsFailed && asset.thumbnail_error && (
                    <div className="border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900 mb-2">Processing Error</h3>
                        <div className="bg-red-50 border border-red-200 rounded-md p-3">
                            <p className="text-sm text-red-800">{asset.thumbnail_error}</p>
                        </div>
                    </div>
                )}

                {/* Asset Timeline */}
                <AssetTimeline events={activityEvents} loading={activityLoading} />
            </div>

            {/* Zoom Modal for Images */}
            {showZoomModal && isImage && asset.id && (
                <div
                    className="fixed inset-0 z-[60] bg-black/90 flex items-center justify-center p-4"
                    onClick={() => setShowZoomModal(false)}
                >
                    <button
                        type="button"
                        onClick={() => setShowZoomModal(false)}
                        className="absolute top-4 right-4 text-white hover:text-gray-300"
                    >
                        <XMarkIcon className="h-8 w-8" />
                    </button>
                    <img
                        src={`/app/assets/${asset.id}/thumbnail/large`}
                        alt={asset.title || asset.original_filename || 'Asset preview'}
                        className="max-w-full max-h-full object-contain"
                        onClick={(e) => e.stopPropagation()}
                    />
                </div>
            )}
        </div>
    )
}

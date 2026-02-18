/**
 * AssetDetailDrawer Component
 * 
 * Right-side drawer panel for displaying asset details.
 * Slides in from the right when an asset is selected.
 * 
 * Features:
 * - Preview/thumbnail display (with signed URL support)
 * - File information (type, size, upload date)
 * - Metadata display (read-only)
 * - Processing state awareness
 * - Keyboard and backdrop close support
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with id, title, metadata, etc.
 * @param {Function} props.onClose - Callback when drawer should close
 */
import { useEffect, useState, useRef } from 'react'
import {
    XMarkIcon,
    ArrowDownTrayIcon,
    PhotoIcon,
    DocumentIcon,
    FilmIcon,
    ArrowPathIcon,
} from '@heroicons/react/24/outline'
import AssetImage from './AssetImage'
import AssetTimeline from './AssetTimeline'

export default function AssetDetailDrawer({ asset, onClose }) {
    const drawerRef = useRef(null)
    const [showZoomModal, setShowZoomModal] = useState(false)
    const [isOpen, setIsOpen] = useState(false) // For animation control
    const [activityEvents, setActivityEvents] = useState([])
    const [activityLoading, setActivityLoading] = useState(false)

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

    // Trigger slide-in animation when drawer mounts
    useEffect(() => {
        // Small delay to ensure initial render with translate-x-full happens first
        // This allows the slide-in animation to be visible
        const timer = setTimeout(() => {
            setIsOpen(true)
        }, 10)
        return () => clearTimeout(timer)
    }, [])

    // Prevent body scroll when drawer is open
    useEffect(() => {
        document.body.style.overflow = 'hidden'
        return () => {
            document.body.style.overflow = 'unset'
        }
    }, [])

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


    if (!asset) {
        return null
    }

    // Determine file type and if it's an image
    const fileExtension = asset.file_extension || asset.original_filename?.split('.').pop()?.toLowerCase() || 'file'
    const isImage = asset.mime_type?.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tif', 'tiff'].includes(fileExtension.toLowerCase())
    const isVideo = asset.mime_type?.startsWith('video/') || ['mp4', 'mov', 'avi', 'webm', 'mpg', 'mpeg'].includes(fileExtension.toLowerCase())
    const isPDF = fileExtension.toLowerCase() === 'pdf'
    const isDesignFile = ['psd', 'psb', 'ai', 'eps', 'sketch', 'xd'].includes(fileExtension.toLowerCase())

    // Check if asset is processing (not completed)
    const isProcessing = asset.status && asset.status !== 'completed'
    const isCompleted = asset.status === 'completed'
    
    // Check thumbnail status - hide loading spinner when thumbnails are complete
    // Handle both string and object (enum) values from backend
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status
    const thumbnailsComplete = thumbnailStatus === 'completed' || !thumbnailStatus // null means legacy asset (considered complete for display)
    const thumbnailsProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'

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

    // Get file type icon
    const getFileTypeIcon = () => {
        if (isVideo) {
            return <FilmIcon className="h-16 w-16 text-gray-400" />
        } else if (isPDF) {
            return (
                <div className="flex flex-col items-center">
                    <DocumentIcon className="h-16 w-16 text-red-500" />
                    <span className="mt-2 text-xs font-medium text-gray-500 uppercase">{fileExtension}</span>
                </div>
            )
        } else if (isDesignFile) {
            return (
                <div className="flex flex-col items-center">
                    <PhotoIcon className="h-16 w-16 text-indigo-500" />
                    <span className="mt-2 text-xs font-medium text-gray-500 uppercase">{fileExtension}</span>
                </div>
            )
        } else {
            return (
                <div className="flex flex-col items-center">
                    <DocumentIcon className="h-16 w-16 text-gray-400" />
                    <span className="mt-2 text-xs font-medium text-gray-500 uppercase">{fileExtension}</span>
                </div>
            )
        }
    }

    // Get category name from category object or metadata
    const categoryName = asset.category?.name 
        || (asset.metadata?.category_id && !asset.category ? `Category ID: ${asset.metadata.category_id}` : null)
        || 'Uncategorized'

    return (
        <>
            {/* Backdrop overlay */}
            <div
                className="fixed inset-0 bg-black/50 z-40 transition-opacity"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Drawer panel - slides in from right */}
            <div
                ref={drawerRef}
                className={`fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white shadow-xl transform transition-transform duration-300 ease-in-out overflow-y-auto ${
                    isOpen ? 'translate-x-0' : 'translate-x-full'
                }`}
                style={{
                    width: '100%',
                    maxWidth: '480px',
                }}
            >
                {/* Header */}
                <div className="sticky top-0 z-10 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-gray-900 truncate pr-4">
                        {asset.title || asset.original_filename || 'Asset Details'}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="flex-shrink-0 rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        <span className="sr-only">Close</span>
                        <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                    </button>
                </div>

                {/* Content */}
                <div className="px-6 py-6 space-y-6">
                    {/* Preview Section */}
                    <div className="space-y-3">
                        <h3 className="text-sm font-medium text-gray-900">Preview</h3>
                        
                        {thumbnailsProcessing ? (
                            // Thumbnail processing state
                            <div className="aspect-video bg-gray-100 rounded-lg flex items-center justify-center border border-gray-200">
                                <div className="text-center">
                                    <ArrowPathIcon className="h-12 w-12 text-gray-400 mx-auto animate-spin" />
                                    <p className="mt-3 text-sm text-gray-500">Processing preview...</p>
                                    <p className="mt-1 text-xs text-gray-400">This may take a moment</p>
                                </div>
                            </div>
                        ) : isImage && asset.id && thumbnailsComplete ? (
                            // Image preview using AssetImage component
                            <div className="relative">
                                <div
                                    className="aspect-video bg-gray-100 rounded-lg overflow-hidden border border-gray-200 cursor-pointer group"
                                    onClick={() => setShowZoomModal(true)}
                                >
                                    <AssetImage
                                        assetId={asset.id}
                                        alt={asset.title || asset.original_filename || 'Asset preview'}
                                        className="w-full h-full object-contain"
                                        containerWidth={448} // Drawer max-width 480px - padding (32px) = 448px
                                        lazy={false} // Load immediately in detail view
                                    />
                                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100">
                                        <span className="text-white text-sm font-medium">Click to zoom</span>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            // Non-image file type
                            <div className="aspect-video bg-gray-50 rounded-lg flex items-center justify-center border border-gray-200">
                                {getFileTypeIcon()}
                            </div>
                        )}
                    </div>

                    {/* File Information */}
                    <div className="space-y-3 border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900">File Information</h3>
                        <dl className="space-y-3">
                            {asset?.id && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-sm text-gray-500 flex-shrink-0">Asset ID</dt>
                                    <dd className="text-sm font-mono text-gray-900 break-all text-right" title={asset.id}>
                                        {asset.id}
                                    </dd>
                                </div>
                            )}
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

                    {/* Metadata Section */}
                    {asset.metadata && asset.metadata.fields && Object.keys(asset.metadata.fields).length > 0 && (
                        <div className="space-y-3 border-t border-gray-200 pt-6">
                            <h3 className="text-sm font-medium text-gray-900">Metadata</h3>
                            <dl className="space-y-3">
                                {Object.entries(asset.metadata.fields).map(([key, value]) => (
                                    <div key={key}>
                                        <dt className="text-sm text-gray-500 capitalize mb-1">
                                            {key.replace(/_/g, ' ')}
                                        </dt>
                                        <dd className="text-sm font-medium text-gray-900">
                                            {typeof value === 'object' ? JSON.stringify(value) : String(value)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}

                    {/* Asset Timeline */}
                    <AssetTimeline events={activityEvents} loading={activityLoading} />

                    {/* Actions (placeholder - read-only for now) */}
                    <div className="border-t border-gray-200 pt-6">
                        <button
                            type="button"
                            disabled
                            className="w-full inline-flex items-center justify-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed"
                        >
                            <ArrowDownTrayIcon className="h-5 w-5 mr-2" />
                            Download (coming soon)
                        </button>
                    </div>
                </div>
            </div>

            {/* Zoom Modal for Images (optional enhancement) */}
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
        </>
    )
}

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
import { useEffect, useRef, useState, useMemo } from 'react'
import { XMarkIcon, ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline'
import AssetImage from './AssetImage'
import AssetTimeline from './AssetTimeline'
import ThumbnailPreview from './ThumbnailPreview'
import { getThumbnailState, getThumbnailVersion } from '../utils/thumbnailUtils'

export default function AssetDrawer({ asset, onClose, assets = [], currentAssetIndex = null }) {
    const drawerRef = useRef(null)
    const closeButtonRef = useRef(null)
    const [showZoomModal, setShowZoomModal] = useState(false)
    const [activityEvents, setActivityEvents] = useState([])
    const [activityLoading, setActivityLoading] = useState(false)
    // Track layout settling to prevent preview jump during grid recalculation
    // When drawer opens, grid container padding animates (300ms), causing CSS Grid to recalculate
    // This state delays preview render until layout stabilizes
    const [isLayoutSettling, setIsLayoutSettling] = useState(true)
    // Phase 3.0C: Track thumbnail retry count (UI only, max 2 retries)
    const [thumbnailRetryCount, setThumbnailRetryCount] = useState(0)
    
    // Phase 3.1: Get image assets for carousel (filter to image assets only)
    const imageAssets = useMemo(() => {
        if (!assets || assets.length === 0) return []
        return assets.filter(a => {
            const ext = (a.file_extension || a.original_filename?.split('.').pop() || '').toUpperCase()
            return a.mime_type?.startsWith('image/') || ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF'].includes(ext)
        })
    }, [assets])

    // Phase 3.1: Carousel state for zoom modal
    // Track current asset index in carousel (for navigation)
    const [carouselIndex, setCarouselIndex] = useState(0)
    const [transitionDirection, setTransitionDirection] = useState(null) // 'left' or 'right' for animation
    const [isTransitioning, setIsTransitioning] = useState(false)
    
    // Phase 3.1: Initialize and update carousel index when asset or imageAssets change
    // Only update if not in zoom modal (to allow carousel navigation)
    useEffect(() => {
        if (!showZoomModal && imageAssets.length > 0 && asset?.id) {
            const index = imageAssets.findIndex(a => a.id === asset.id)
            if (index >= 0 && index !== carouselIndex) {
                setCarouselIndex(index)
            }
        }
    }, [asset?.id, imageAssets, carouselIndex, showZoomModal])

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

    const currentCarouselAsset = imageAssets[carouselIndex] || asset
    const canNavigateLeft = carouselIndex > 0
    const canNavigateRight = carouselIndex < imageAssets.length - 1

    // Phase 3.1: Carousel navigation handlers with smooth transitions
    const handlePrevious = (e) => {
        e.stopPropagation()
        if (canNavigateLeft && !isTransitioning) {
            setIsTransitioning(true)
            setTransitionDirection('right')
            setTimeout(() => {
                setCarouselIndex(prev => prev - 1)
                setTransitionDirection(null)
                setTimeout(() => setIsTransitioning(false), 300)
            }, 150) // Half of transition duration
        }
    }

    const handleNext = (e) => {
        e.stopPropagation()
        if (canNavigateRight && !isTransitioning) {
            setIsTransitioning(true)
            setTransitionDirection('left')
            setTimeout(() => {
                setCarouselIndex(prev => prev + 1)
                setTransitionDirection(null)
                setTimeout(() => setIsTransitioning(false), 300)
            }, 150) // Half of transition duration
        }
    }

    // Phase 3.1: Keyboard navigation for carousel
    useEffect(() => {
        if (!showZoomModal) return

        const handleKeyDown = (e) => {
            if (e.key === 'ArrowLeft' && canNavigateLeft && !isTransitioning) {
                e.preventDefault()
                setIsTransitioning(true)
                setTransitionDirection('right')
                setTimeout(() => {
                    setCarouselIndex(prev => prev - 1)
                    setTransitionDirection(null)
                    setTimeout(() => setIsTransitioning(false), 300)
                }, 150)
            } else if (e.key === 'ArrowRight' && canNavigateRight && !isTransitioning) {
                e.preventDefault()
                setIsTransitioning(true)
                setTransitionDirection('left')
                setTimeout(() => {
                    setCarouselIndex(prev => prev + 1)
                    setTransitionDirection(null)
                    setTimeout(() => setIsTransitioning(false), 300)
                }, 150)
            } else if (e.key === 'Escape') {
                setShowZoomModal(false)
            }
        }

        document.addEventListener('keydown', handleKeyDown)
        return () => document.removeEventListener('keydown', handleKeyDown)
    }, [showZoomModal, canNavigateLeft, canNavigateRight, isTransitioning])

    // Extract file extension
    const fileExtension = asset.file_extension || asset.original_filename?.split('.').pop()?.toUpperCase() || 'FILE'
    const isImage = asset.mime_type?.startsWith('image/') || ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF'].includes(fileExtension.toUpperCase())

    // Phase 3.1: Derive stable thumbnail version signal
    // This ensures ThumbnailPreview re-evaluates after background reconciliation updates asset props
    const thumbnailVersion = useMemo(() => getThumbnailVersion(asset), [
        asset?.id,
        asset?.thumbnail_url,
        asset?.thumbnail_status?.value || asset?.thumbnail_status,
        asset?.updated_at,
    ])

    // Check thumbnail status (for legacy compatibility - ThumbnailPreview handles state machine)
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || 'pending'
    const thumbnailsComplete = thumbnailStatus === 'completed'
    const thumbnailsProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'
    const thumbnailsFailed = thumbnailStatus === 'failed'

    // Status badge uses Asset.status (visibility only: VISIBLE, HIDDEN, FAILED)
    // If status is VISIBLE, asset was uploaded correctly (not processing)
    // Asset.status represents visibility only, not processing state
    // Phase 3.1: Processing badge should only appear when thumbnail state is 'pending'
    // Do not rely on legacy asset.status flags alone - thumbnail state is the source of truth
    const thumbnailState = useMemo(() => getThumbnailState(asset, thumbnailRetryCount), [
        asset?.id,
        thumbnailVersion,
        thumbnailRetryCount,
    ])
    // Phase 3.1E: Processing badge shows only when thumbnail state is 'PENDING'
    const isThumbnailProcessing = thumbnailState.state === 'PENDING'
    
    // Phase 3.1E: Detect meaningful state transitions for thumbnail animation
    // Track previous state to detect transitions from non-AVAILABLE → AVAILABLE
    // Animation should ONLY trigger on meaningful state changes (e.g., after background reconciliation)
    // NEVER animate on initial render - prevents UI jank
    // Smart poll authority: only polling/reconciliation may promote to AVAILABLE
    const [shouldAnimateThumbnail, setShouldAnimateThumbnail] = useState(false)
    const prevThumbnailStateRef = useRef(null)
    
    useEffect(() => {
        const prevState = prevThumbnailStateRef.current
        const currentState = thumbnailState.state
        
        // Phase 3.1E: Detect transition from non-AVAILABLE → AVAILABLE (meaningful state change)
        // This happens when background reconciliation detects thumbnail completion
        // Log when polling promotes thumbnail to AVAILABLE
        if (prevState !== null && prevState !== 'AVAILABLE' && currentState === 'AVAILABLE') {
            console.log('[ThumbnailPoll] Polling promoted thumbnail to AVAILABLE', {
                assetId: asset.id,
                prevState,
                currentState,
                thumbnailUrl: thumbnailState.thumbnailUrl,
            })
            setShouldAnimateThumbnail(true)
            // Reset after animation completes (handled by ThumbnailPreview)
        } else {
            // No meaningful transition - don't animate
            setShouldAnimateThumbnail(false)
        }
        
        prevThumbnailStateRef.current = currentState
    }, [thumbnailState.state, asset?.id, thumbnailState.thumbnailUrl])
    
    // Status badge uses Asset.status (visibility only: VISIBLE, HIDDEN, FAILED)
    // If status is VISIBLE, asset was uploaded correctly (not processing)
    // Asset.status represents visibility only, not processing state
    const assetStatus = asset.status?.value || asset.status || 'visible'
    const isVisible = assetStatus === 'visible'

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
                {/* Phase 3.1: Uploaded By - moved above preview for better visual hierarchy */}
                {asset.uploaded_by && (
                    <div className="flex items-center gap-2 pb-4 border-b border-gray-200">
                        {asset.uploaded_by.avatar_url ? (
                            <img
                                src={asset.uploaded_by.avatar_url}
                                alt={asset.uploaded_by.name || 'User'}
                                className="h-6 w-6 rounded-full object-cover flex-shrink-0"
                            />
                        ) : (
                            <div className="h-6 w-6 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0">
                                <span className="text-xs font-medium text-gray-600">
                                    {(asset.uploaded_by.first_name?.[0] || asset.uploaded_by.name?.[0] || '?').toUpperCase()}
                                </span>
                            </div>
                        )}
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-gray-900 truncate">
                                {asset.uploaded_by.name || `${asset.uploaded_by.first_name || ''} ${asset.uploaded_by.last_name || ''}`.trim() || 'Unknown User'}
                            </p>
                            {asset.uploaded_by.email && (
                                <p className="text-xs text-gray-500 truncate">
                                    {asset.uploaded_by.email}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* Large Preview */}
                <div className="space-y-3">
                    <h3 className="text-sm font-medium text-gray-900">Preview</h3>
                    
                    {/* Phase 3.0C: Thumbnail preview with state machine and fade-in */}
                    <div className="bg-gray-50 rounded-lg overflow-hidden border border-gray-200 relative" style={{ aspectRatio: '16/9', minHeight: '240px' }}>
                        <div 
                            className={`relative w-full h-full transition-opacity duration-200 ${isLayoutSettling ? 'opacity-0' : 'opacity-100'}`}
                        >
                            {isImage && asset.id ? (
                                // Image files: Use ThumbnailPreview with state machine
                                <div
                                    className="w-full h-full cursor-pointer group"
                                    onClick={() => {
                                        // Only allow zoom if thumbnail is available
                                        const { state } = getThumbnailState(asset, thumbnailRetryCount)
                                        if (state === 'AVAILABLE') {
                                            setShowZoomModal(true)
                                        }
                                    }}
                                >
                                    <ThumbnailPreview
                                        asset={asset}
                                        alt={asset.title || asset.original_filename || 'Asset preview'}
                                        className="w-full h-full"
                                        retryCount={thumbnailRetryCount}
                                        onRetry={() => {
                                            // Phase 3.0C: UI-only retry (max 2 retries)
                                            if (thumbnailRetryCount < 2) {
                                                setThumbnailRetryCount(prev => prev + 1)
                                                // Trigger a re-render by updating asset reference
                                                // This is UI-only - no backend call
                                                // The thumbnail will be re-checked on next render
                                            }
                                        }}
                                        size="lg"
                                        thumbnailVersion={thumbnailVersion}
                                        shouldAnimateThumbnail={shouldAnimateThumbnail}
                                    />
                                    {/* Zoom overlay (only shown when thumbnail is available) */}
                                    {asset.thumbnail_url && (
                                        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100 pointer-events-none">
                                            <span className="text-white text-sm font-medium">Click to zoom</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                // Non-image files: Use ThumbnailPreview for consistent icon display
                                <ThumbnailPreview
                                    asset={asset}
                                    alt={asset.title || asset.original_filename || 'Asset preview'}
                                    className="w-full h-full"
                                    retryCount={thumbnailRetryCount}
                                    onRetry={() => {
                                        if (thumbnailRetryCount < 2) {
                                            setThumbnailRetryCount(prev => prev + 1)
                                        }
                                    }}
                                    size="lg"
                                    thumbnailVersion={thumbnailVersion}
                                    shouldAnimateThumbnail={shouldAnimateThumbnail}
                                />
                            )}
                        </div>
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
                                    isVisible
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-yellow-100 text-yellow-800'
                                }`}>
                                    {isVisible ? 'Completed' : 'Processing'}
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
                <AssetTimeline 
                    events={activityEvents} 
                    loading={activityLoading}
                    onThumbnailRetry={() => {
                        // Phase 3.0C: UI-only retry (max 2 retries)
                        if (thumbnailRetryCount < 2) {
                            setThumbnailRetryCount(prev => prev + 1)
                            // Trigger a re-render by updating asset reference
                            // This is UI-only - no backend call
                            // The thumbnail will be re-checked on next render
                        }
                    }}
                    thumbnailRetryCount={thumbnailRetryCount}
                />
            </div>

            {/* Phase 3.1: Zoom Modal with Carousel for Images */}
            {showZoomModal && isImage && currentCarouselAsset?.id && (
                <div
                    className="fixed inset-0 z-[60] bg-black/90 flex items-center justify-center p-4"
                    onClick={() => setShowZoomModal(false)}
                >
                    {/* Close button */}
                    <button
                        type="button"
                        onClick={() => setShowZoomModal(false)}
                        className="absolute top-4 right-4 z-10 text-white hover:text-gray-300 transition-colors"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-8 w-8" />
                    </button>

                    {/* Left arrow - Previous asset */}
                    {canNavigateLeft && (
                        <button
                            type="button"
                            onClick={handlePrevious}
                            className="absolute left-4 z-10 text-white hover:text-gray-300 transition-colors p-2 rounded-full hover:bg-white/10"
                            aria-label="Previous asset"
                        >
                            <ChevronLeftIcon className="h-10 w-10" />
                        </button>
                    )}

                    {/* Right arrow - Next asset */}
                    {canNavigateRight && (
                        <button
                            type="button"
                            onClick={handleNext}
                            className="absolute right-4 z-10 text-white hover:text-gray-300 transition-colors p-2 rounded-full hover:bg-white/10"
                            aria-label="Next asset"
                        >
                            <ChevronRightIcon className="h-10 w-10" />
                        </button>
                    )}

                    {/* Image with smooth slide transition */}
                    <div 
                        className="relative w-full h-full flex items-center justify-center overflow-hidden"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <img
                            key={currentCarouselAsset.id} // Key forces remount for clean transition
                            src={`/app/assets/${currentCarouselAsset.id}/thumbnail/large`}
                            alt={currentCarouselAsset.title || currentCarouselAsset.original_filename || 'Asset preview'}
                            className="max-w-full max-h-full object-contain transition-all duration-300 ease-in-out"
                            style={{
                                transform: transitionDirection === 'left' 
                                    ? 'translateX(30px)' 
                                    : transitionDirection === 'right' 
                                    ? 'translateX(-30px)' 
                                    : 'translateX(0)',
                                opacity: transitionDirection ? 0 : 1,
                            }}
                        />
                    </div>

                    {/* Title at bottom center - subtle and small */}
                    <div className="absolute bottom-8 left-1/2 transform -translate-x-1/2 z-10 pointer-events-none">
                        <p className="text-white/80 text-sm font-medium text-center px-4 py-2 bg-black/40 backdrop-blur-sm rounded-lg">
                            {currentCarouselAsset.title || currentCarouselAsset.original_filename || 'Untitled Asset'}
                        </p>
                    </div>
                </div>
            )}
        </div>
    )
}

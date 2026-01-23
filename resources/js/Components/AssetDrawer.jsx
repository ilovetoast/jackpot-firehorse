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
/**
 * AssetDrawer Component
 * 
 * Right-side drawer panel for displaying asset details.
 * Pushes the grid content when open (desktop/tablet), overlays on mobile.
 * 
 * LIVE THUMBNAIL BEHAVIOR: This component implements live thumbnail polling
 * for the active asset ONLY. Polling is completely isolated from grid state.
 * 
 * Features:
 * - Large preview using /app/assets/{id}/thumbnail/medium
 * - Asset header (title, file type, status indicators)
 * - Metadata summary (category, file size, MIME type, created date)
 * - Activity timeline
 * - Processing state (thumbnail status, errors)
 * - Keyboard accessible (Esc to close)
 * - Focus trap on mobile
 * - Live thumbnail updates (preview → final swap)
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with id, title, metadata, etc.
 * @param {Function} props.onClose - Callback when drawer should close
 * @param {Array} props.assets - Array of all assets (for carousel navigation)
 * @param {number|null} props.currentAssetIndex - Current asset index in carousel
 */
import { useEffect, useRef, useState, useMemo } from 'react'
import { XMarkIcon, ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon, ExclamationTriangleIcon, ChevronDownIcon, EyeIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline'
import { usePage, router } from '@inertiajs/react'
import AssetImage from './AssetImage'
import AssetTimeline from './AssetTimeline'
import AiMetadataSuggestionsInline from './AiMetadataSuggestionsInline'
import AssetMetadataDisplay from './AssetMetadataDisplay'
import PendingMetadataList from './PendingMetadataList'
import MetadataCandidateReview from './MetadataCandidateReview'
import ThumbnailPreview from './ThumbnailPreview'
import AssetDetailsModal from './AssetDetailsModal'
import DominantColorsSwatches from './DominantColorsSwatches'
import { getThumbnailState, getThumbnailVersion } from '../utils/thumbnailUtils'
import { usePermission } from '../hooks/usePermission'
import { useDrawerThumbnailPoll } from '../hooks/useDrawerThumbnailPoll'
import { useAssetMetrics } from '../hooks/useAssetMetrics'

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
    // Thumbnail retry state
    const [showRetryModal, setShowRetryModal] = useState(false)
    const [retryLoading, setRetryLoading] = useState(false)
    const [retryError, setRetryError] = useState(null)
    // Thumbnail generation state (for existing assets without thumbnails)
    const [generateLoading, setGenerateLoading] = useState(false)
    const [generateError, setGenerateError] = useState(null)
    const [generateTimeoutId, setGenerateTimeoutId] = useState(null)
    // Details modal state
    const [showDetailsModal, setShowDetailsModal] = useState(false)
    
    // Phase 3.1: Get assets with thumbnail support for carousel (images and PDFs)
    const imageAssets = useMemo(() => {
        if (!assets || assets.length === 0) return []
        return assets.filter(a => {
            const ext = (a.file_extension || a.original_filename?.split('.').pop() || '').toUpperCase()
            // Include images and PDFs (both support thumbnail generation)
            return a.mime_type?.startsWith('image/') || 
                   a.mime_type === 'application/pdf' ||
                   ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF', 'PDF'].includes(ext)
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

    // Initialize metrics tracking hook (must be before useEffects that use it)
    const { trackView, getViewCount, getDownloadCount } = useAssetMetrics()
    
    // Analytics/metrics state
    const [viewCount, setViewCount] = useState(null)
    const [downloadCount, setDownloadCount] = useState(null)
    const [metricsLoading, setMetricsLoading] = useState(false)

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

    // Track view when drawer opens (when asset changes)
    // Use ref to track if we've already tracked this asset to prevent double counting
    const trackedDrawerViewsRef = useRef(new Set())
    
    useEffect(() => {
        if (asset?.id) {
            // Check if we've already tracked this asset in this session
            const trackingKey = `${asset.id}_drawer`
            if (trackedDrawerViewsRef.current.has(trackingKey)) {
                return
            }

            // Track drawer view with slight delay to ensure drawer is fully open
            const timer = setTimeout(() => {
                trackView(asset.id, 'drawer')
                trackedDrawerViewsRef.current.add(trackingKey)
            }, 500) // Delay to ensure drawer is fully opened

            return () => clearTimeout(timer)
        } else {
            // Reset tracking when drawer closes (asset becomes null)
            trackedDrawerViewsRef.current.clear()
        }
    }, [asset?.id, trackView])

    // Track large view when zoom modal opens
    // Use ref to track if we've already tracked this asset's large view
    const trackedLargeViewsRef = useRef(new Set())
    
    useEffect(() => {
        if (showZoomModal && asset?.id) {
            // Check if we've already tracked this asset's large view
            const trackingKey = `${asset.id}_large_view`
            if (trackedLargeViewsRef.current.has(trackingKey)) {
                return
            }

            trackView(asset.id, 'large_view')
            trackedLargeViewsRef.current.add(trackingKey)
        } else if (!showZoomModal && asset?.id) {
            // Reset tracking for this asset when modal closes
            const trackingKey = `${asset.id}_large_view`
            trackedLargeViewsRef.current.delete(trackingKey)
        }
    }, [showZoomModal, asset?.id, trackView])

    // Fetch analytics/metrics when asset changes
    useEffect(() => {
        if (!asset?.id) {
            setViewCount(null)
            setDownloadCount(null)
            return
        }

        setMetricsLoading(true)
        
        // Fetch both counts in parallel
        Promise.all([
            getViewCount(asset.id),
            getDownloadCount(asset.id)
        ]).then(([views, downloads]) => {
            setViewCount(views)
            setDownloadCount(downloads)
            setMetricsLoading(false)
        }).catch(() => {
            setMetricsLoading(false)
        })
    }, [asset?.id, getViewCount, getDownloadCount])

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

    // LIVE THUMBNAIL BEHAVIOR: Poll thumbnail status for drawer asset only
    // This is completely isolated from grid state - drawer updates never affect grid
    const { drawerAsset } = useDrawerThumbnailPoll({
        asset,
        onAssetUpdate: (updatedAsset) => {
            // Update local state for drawer display only
            // This does NOT mutate grid assets
            // The drawer will re-render with updated thumbnail URLs
        },
    })

    // Use drawerAsset (with live updates) for thumbnail display
    // Fallback to prop asset if drawerAsset not yet initialized
    const displayAsset = drawerAsset || asset

    // Use displayAsset for carousel (with live updates)
    const currentCarouselAsset = imageAssets[carouselIndex] || displayAsset
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
    // Use displayAsset (with live updates) instead of prop asset
    const fileExtension = displayAsset.file_extension || displayAsset.original_filename?.split('.').pop()?.toUpperCase() || 'FILE'
    const isImage = displayAsset.mime_type?.startsWith('image/') || ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF'].includes(fileExtension.toUpperCase())
    
    // Assets that support thumbnail preview (images and PDFs)
    const hasThumbnailSupport = isImage || displayAsset.mime_type === 'application/pdf' || fileExtension.toUpperCase() === 'PDF'

    // Phase 3.1: Derive stable thumbnail version signal
    // This ensures ThumbnailPreview re-evaluates after live polling updates
    const thumbnailVersion = useMemo(() => getThumbnailVersion(displayAsset), [
        displayAsset?.id,
        displayAsset?.thumbnail_url,
        displayAsset?.thumbnail_status?.value || displayAsset?.thumbnail_status,
        displayAsset?.updated_at,
    ])

    // Check thumbnail status (for legacy compatibility - ThumbnailPreview handles state machine)
    // Use displayAsset (with live updates) instead of prop asset
    const thumbnailStatus = displayAsset.thumbnail_status?.value || displayAsset.thumbnail_status || 'pending'
    const thumbnailsComplete = thumbnailStatus === 'completed'
    const thumbnailsProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'
    const thumbnailsFailed = thumbnailStatus === 'failed'
    const thumbnailsSkipped = thumbnailStatus === 'skipped'

    // Status badge uses Asset.status (visibility only: VISIBLE, HIDDEN, FAILED)
    // If status is VISIBLE, asset was uploaded correctly (not processing)
    // Asset.status represents visibility only, not processing state
    // Phase 3.1: Processing badge should only appear when thumbnail state is 'pending'
    // Do not rely on legacy asset.status flags alone - thumbnail state is the source of truth
    // Use displayAsset (with live updates) instead of prop asset
    const thumbnailState = useMemo(() => getThumbnailState(displayAsset, thumbnailRetryCount), [
        displayAsset?.id,
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
        // This happens when drawer polling detects thumbnail completion
        // Log when drawer polling promotes thumbnail to AVAILABLE
        if (prevState !== null && prevState !== 'AVAILABLE' && currentState === 'AVAILABLE') {
            console.log('[DrawerThumbnailPoll] Drawer polling promoted thumbnail to AVAILABLE', {
                assetId: displayAsset.id,
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
    }, [thumbnailState.state, displayAsset?.id, thumbnailState.thumbnailUrl])
    
    // Status badge uses Asset.status (visibility only: VISIBLE, HIDDEN, FAILED)
    // If status is VISIBLE, asset was uploaded correctly (not processing)
    // Asset.status represents visibility only, not processing state
    // Use displayAsset (with live updates) instead of prop asset
    const assetStatus = displayAsset.status?.value || displayAsset.status || 'visible'
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
    // Use displayAsset (with live updates) instead of prop asset
    const categoryName = displayAsset.category?.name || 'Uncategorized'

    // Check if user has permission to generate/retry thumbnails
    const { hasPermission: canRetryThumbnails } = usePermission('assets.retry_thumbnails')
    
    // Check if user has admin permission to regenerate specific styles (site roles only)
    const { hasPermission: canRegenerateStylesAdmin } = usePermission('assets.regenerate_thumbnails_admin')
    const { auth } = usePage().props
    
    // Admin thumbnail regeneration state
    const [showThumbnailManagement, setShowThumbnailManagement] = useState(false) // Collapsible toggle state
    const [showRegenerateDropdown, setShowRegenerateDropdown] = useState(false)
    const [regenerateStylesLoading, setRegenerateStylesLoading] = useState(false)
    const [regenerateStylesError, setRegenerateStylesError] = useState(null)
    const [selectedStyles, setSelectedStyles] = useState(['thumb', 'medium', 'large']) // Default to all final styles
    const [forceImageMagick, setForceImageMagick] = useState(false) // Admin override: bypass file type checks
    const regenerateDropdownRef = useRef(null)
    
    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (regenerateDropdownRef.current && !regenerateDropdownRef.current.contains(event.target)) {
                setShowRegenerateDropdown(false)
            }
        }
        
        if (showRegenerateDropdown) {
            document.addEventListener('mousedown', handleClickOutside)
            return () => document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [showRegenerateDropdown])

    // Check if asset can have thumbnail generated (for previously skipped assets)
    // IMPORTANT: This is for existing assets that were skipped but are now supported
    // (e.g., PDFs before PDF support was added)
    // This is a manual, user-triggered action only - does not modify the thumbnail pipeline
    // PERMISSION CHECK: User must have assets.retry_thumbnails permission
    // BUTTON HIDING: Button is hidden during generation (processing/pending) or while loading
    const canGenerateThumbnail = useMemo(() => {
        if (!displayAsset) return false
        
        // Permission check: User must have assets.retry_thumbnails permission
        if (!canRetryThumbnails) {
            return false
        }
        
        // Hide button if currently generating (loading state)
        if (generateLoading) {
            return false
        }
        
        // Hide button if thumbnail is being processed (status changed from skipped)
        // The drawer polling will detect this change
        if (thumbnailStatus === 'processing' || thumbnailStatus === 'pending') {
            return false
        }
        
        // Must be in skipped state (was previously unsupported)
        if (thumbnailStatus !== 'skipped') {
            return false
        }
        
        // Must be a PDF (currently the main use case)
        // Could be extended to other file types in the future
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        
        if (mimeType === 'application/pdf' || extension === 'pdf') {
            return true
        }
        
        // Could also support images that were skipped for other reasons
        // For now, focus on PDFs
        return false
    }, [displayAsset, thumbnailStatus, canRetryThumbnails, generateLoading])

    // Handle manual thumbnail generation (for previously skipped assets)
    const handleGenerateThumbnail = async () => {
        if (!displayAsset?.id || !canGenerateThumbnail) return
        
        setGenerateLoading(true)
        setGenerateError(null)
        
        // Clear any existing timeout
        if (generateTimeoutId) {
            clearTimeout(generateTimeoutId)
            setGenerateTimeoutId(null)
        }
        
        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/thumbnails/generate`)
            
            if (response.data.success) {
                // Success - the drawer polling will detect the status change automatically
                // No need to manually update - respects non-realtime design
                // Button will be hidden because status will change to 'processing' or 'pending'
                setGenerateError(null)
                
                // Set a timeout fallback: if status doesn't change within 30 seconds,
                // show the button again (in case of job queue issues)
                const timeout = setTimeout(() => {
                    // Only reset if still in skipped state (polling didn't detect change)
                    if (displayAsset?.thumbnail_status === 'skipped' || !displayAsset?.thumbnail_status) {
                        setGenerateLoading(false)
                        setGenerateError('Generation may be in progress. Please refresh the page to check status.')
                    }
                }, 30000) // 30 second timeout
                
                setGenerateTimeoutId(timeout)
            } else {
                setGenerateError(response.data.error || 'Failed to generate thumbnail')
                setGenerateLoading(false)
            }
        } catch (error) {
            console.error('Thumbnail generation error:', error)
            
            // Handle different error types
            if (error.response) {
                const status = error.response.status
                const errorMessage = error.response.data?.error || 'Failed to generate thumbnail'
                
                if (status === 409) {
                    setGenerateError('Thumbnail generation is already in progress')
                } else if (status === 422) {
                    setGenerateError(errorMessage)
                } else if (status === 403) {
                    setGenerateError('You do not have permission to generate thumbnails')
                } else if (status === 404) {
                    setGenerateError('Asset not found')
                } else {
                    setGenerateError(errorMessage)
                }
            } else {
                setGenerateError('Network error. Please try again.')
            }
            
            setGenerateLoading(false)
        }
    }
    
    // Handle admin thumbnail style regeneration
    const handleRegenerateStyles = async () => {
        if (!displayAsset?.id || !canRegenerateStylesAdmin || selectedStyles.length === 0) return
        
        setRegenerateStylesLoading(true)
        setRegenerateStylesError(null)
        setShowRegenerateDropdown(false)
        
        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/thumbnails/regenerate-styles`, {
                styles: selectedStyles,
                force_imagick: forceImageMagick
            })
            
            if (response.data.success) {
                // Success - refresh the page to show updated thumbnails
                router.reload({ only: ['asset', 'auth'], preserveState: false })
            } else {
                setRegenerateStylesError(response.data.error || 'Failed to regenerate thumbnails')
                setRegenerateStylesLoading(false)
            }
        } catch (error) {
            console.error('Thumbnail style regeneration error:', error)
            
            if (error.response) {
                const status = error.response.status
                const errorMessage = error.response.data?.error || 'Failed to regenerate thumbnails'
                
                if (status === 403) {
                    setRegenerateStylesError('You do not have permission to regenerate thumbnails')
                } else if (status === 404) {
                    setRegenerateStylesError('Asset not found')
                } else {
                    setRegenerateStylesError(errorMessage)
                }
            } else {
                setRegenerateStylesError('Network error. Please try again.')
            }
            
            setRegenerateStylesLoading(false)
        }
    }
    
    // Available thumbnail styles (final styles only - preview is excluded from admin regeneration)
    const availableStyles = [
        { name: 'thumb', label: 'Thumb (320×320)', description: 'Grid thumbnails' },
        { name: 'medium', label: 'Medium (1024×1024)', description: 'Drawer previews' },
        { name: 'large', label: 'Large (4096×4096)', description: 'Full-screen previews' },
    ]
    
    // Cleanup timeout on unmount or when asset changes
    useEffect(() => {
        return () => {
            if (generateTimeoutId) {
                clearTimeout(generateTimeoutId)
            }
        }
    }, [generateTimeoutId])
    
    // Clear loading state if status changes from skipped (polling detected the change)
    useEffect(() => {
        if (generateLoading && thumbnailStatus !== 'skipped' && thumbnailStatus !== 'pending' && thumbnailStatus !== 'processing') {
            // Status changed - clear loading and timeout
            setGenerateLoading(false)
            if (generateTimeoutId) {
                clearTimeout(generateTimeoutId)
                setGenerateTimeoutId(null)
            }
        }
    }, [thumbnailStatus, generateLoading, generateTimeoutId])

    // Check if thumbnail retry is allowed
    // IMPORTANT: This feature respects the locked thumbnail pipeline:
    // - Does not modify existing GenerateThumbnailsJob
    // - Does not mutate Asset.status
    // - Retry attempts are tracked for audit purposes
    // PERMISSION CHECK: User must have assets.retry_thumbnails permission
    const canRetryThumbnail = useMemo(() => {
        if (!displayAsset) return false
        
        // Permission check: User must have assets.retry_thumbnails permission
        if (!canRetryThumbnails) {
            return false
        }
        
        // Must be in failed or missing state
        if (thumbnailStatus !== 'failed' && thumbnailStatus !== 'pending') {
            return false
        }
        
        // Check retry limit (default: 3)
        const maxRetries = 3
        const retryCount = displayAsset.thumbnail_retry_count || 0
        if (retryCount >= maxRetries) {
            return false
        }
        
        // Check if file type is supported (same logic as backend)
        // Includes both images (GD) and PDFs (ImageMagick/Ghostscript)
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        const supportedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']
        const supportedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']
        
        // AVIF is explicitly unsupported
        if (mimeType === 'image/avif' || extension === 'avif') {
            return false
        }
        
        // Check if MIME type or extension is supported
        if (mimeType && !supportedMimeTypes.includes(mimeType)) {
            if (!extension || !supportedExtensions.includes(extension)) {
                return false
            }
        }
        
        // Must not be currently processing
        if (thumbnailStatus === 'processing') {
            return false
        }
        
        return true
    }, [displayAsset, thumbnailStatus, canRetryThumbnails])

    // Get retry error message
    const getRetryErrorMessage = () => {
        if (!displayAsset) return null
        
        const retryCount = displayAsset.thumbnail_retry_count || 0
        const maxRetries = 3
        
        if (retryCount >= maxRetries) {
            return `Retry limit reached (${maxRetries}/${maxRetries} attempts used)`
        }
        
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        const supportedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp']
        const supportedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']
        
        if (mimeType === 'image/avif' || extension === 'avif') {
            return 'Thumbnail generation is not supported for AVIF files'
        }
        
        if (mimeType && !supportedMimeTypes.includes(mimeType)) {
            if (!extension || !supportedExtensions.includes(extension)) {
                return 'Thumbnail generation is not supported for this file type'
            }
        }
        
        if (thumbnailStatus === 'processing') {
            return 'Thumbnail generation is already in progress'
        }
        
        return null
    }

    // Handle thumbnail retry
    const handleRetryThumbnail = async () => {
        if (!displayAsset?.id || !canRetryThumbnail) return
        
        setRetryLoading(true)
        setRetryError(null)
        
        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/thumbnails/retry`)
            
            if (response.data.success) {
                // Close modal and show success
                setShowRetryModal(false)
                // The drawer polling will detect the status change automatically
                // No need to manually update - respects non-realtime design
            } else {
                setRetryError(response.data.error || 'Failed to retry thumbnail generation')
            }
        } catch (error) {
            console.error('Thumbnail retry error:', error)
            
            // Handle different error types
            if (error.response) {
                const status = error.response.status
                const errorMessage = error.response.data?.error || 'Failed to retry thumbnail generation'
                
                if (status === 429) {
                    setRetryError(`Retry limit exceeded: ${errorMessage}`)
                } else if (status === 422) {
                    setRetryError(errorMessage)
                } else if (status === 409) {
                    setRetryError('Thumbnail generation is already in progress')
                } else if (status === 403) {
                    setRetryError('You do not have permission to retry thumbnails')
                } else if (status === 404) {
                    setRetryError('Asset not found')
                } else {
                    setRetryError(errorMessage)
                }
            } else {
                setRetryError('Network error. Please try again.')
            }
        } finally {
            setRetryLoading(false)
        }
    }

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
            <div className="sticky top-0 z-10 bg-white border-b border-gray-200">
                <div className="px-6 py-4 flex items-center justify-between">
                    <h2 id="drawer-title" className="text-lg font-semibold text-gray-900 truncate pr-4">
                        {displayAsset.title || displayAsset.original_filename || 'Asset Details'}
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
                
                {/* Thumbnail Management - Collapsible section under title (admin only) */}
                {canRegenerateStylesAdmin && (
                    <div className="border-t border-gray-200">
                        <button
                            type="button"
                            onClick={() => setShowThumbnailManagement(!showThumbnailManagement)}
                            className="w-full px-6 py-3 flex items-center justify-between text-left hover:bg-gray-50 transition-colors"
                        >
                            <span className="text-sm font-medium text-gray-900">Thumbnail Management</span>
                            <ChevronDownIcon className={`h-4 w-4 text-gray-500 transition-transform ${showThumbnailManagement ? 'rotate-180' : ''}`} />
                        </button>
                        
                        {showThumbnailManagement && (
                            <div className="px-6 py-4 bg-gray-50 border-t border-gray-200">
                                <div className="relative" ref={regenerateDropdownRef}>
                                    <button
                                        type="button"
                                        onClick={() => setShowRegenerateDropdown(!showRegenerateDropdown)}
                                        disabled={regenerateStylesLoading}
                                        className="inline-flex items-center justify-between w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <span>
                                            {regenerateStylesLoading ? 'Regenerating...' : 'Regenerate Thumbnails'}
                                        </span>
                                        <ChevronDownIcon className={`h-4 w-4 ml-2 transition-transform ${showRegenerateDropdown ? 'rotate-180' : ''}`} />
                                    </button>
                                    
                                    {/* Dropdown menu */}
                                    {showRegenerateDropdown && !regenerateStylesLoading && (
                                        <div className="absolute z-10 mt-1 w-full rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                            <div className="py-2 px-3">
                                                <p className="text-xs font-medium text-gray-700 mb-2">Select styles to regenerate:</p>
                                                
                                                <div className="space-y-2">
                                                    {availableStyles.map((style) => (
                                                        <label
                                                            key={style.name}
                                                            className="flex items-start gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={selectedStyles.includes(style.name)}
                                                                onChange={(e) => {
                                                                    if (e.target.checked) {
                                                                        setSelectedStyles([...selectedStyles, style.name])
                                                                    } else {
                                                                        setSelectedStyles(selectedStyles.filter(s => s !== style.name))
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
                                                
                                                {/* Force ImageMagick option (admin override for testing unsupported file types) */}
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
                                                            <div className="text-xs text-gray-500">Bypass file type checks and use ImageMagick for any file type (testing only)</div>
                                                        </div>
                                                    </label>
                                                </div>
                                                
                                                {regenerateStylesError && (
                                                    <div className="mt-3 bg-red-50 border border-red-200 rounded-md p-2">
                                                        <p className="text-xs text-red-800">{regenerateStylesError}</p>
                                                    </div>
                                                )}
                                                
                                                <div className="mt-3 flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowRegenerateDropdown(false)}
                                                        className="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                    >
                                                        Cancel
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={handleRegenerateStyles}
                                                        disabled={selectedStyles.length === 0}
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

            {/* Content */}
            <div className="px-6 py-6 space-y-6">
                {/* Large Preview */}
                <div className="space-y-3">
                    <h3 className="text-sm font-medium text-gray-900">Preview</h3>
                    
                    {/* Phase 3.0C: Thumbnail preview with state machine and fade-in */}
                    <div className="bg-gray-50 rounded-lg overflow-hidden border border-gray-200 relative" style={{ aspectRatio: '16/9', minHeight: '240px' }}>
                        <div 
                            className={`relative w-full h-full transition-opacity duration-200 ${isLayoutSettling ? 'opacity-0' : 'opacity-100'}`}
                        >
                            {hasThumbnailSupport && displayAsset.id ? (
                                // Assets with thumbnail support (images and PDFs): Use ThumbnailPreview with state machine
                                // Use displayAsset (with live updates) instead of prop asset
                                <div
                                    className="w-full h-full cursor-pointer group"
                                    onClick={() => {
                                        // Only allow zoom if thumbnail is available
                                        const { state } = getThumbnailState(displayAsset, thumbnailRetryCount)
                                        if (state === 'AVAILABLE') {
                                            setShowZoomModal(true)
                                        }
                                    }}
                                >
                                    <ThumbnailPreview
                                        asset={displayAsset}
                                        alt={displayAsset.title || displayAsset.original_filename || 'Asset preview'}
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
                                    {(displayAsset.thumbnail_url || displayAsset.final_thumbnail_url || displayAsset.preview_thumbnail_url) && (
                                        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100 pointer-events-none">
                                            <span className="text-white text-sm font-medium">Click to zoom</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                // Non-image files: Use ThumbnailPreview for consistent icon display
                                // Use displayAsset (with live updates) instead of prop asset
                                <ThumbnailPreview
                                    asset={displayAsset}
                                    alt={displayAsset.title || displayAsset.original_filename || 'Asset preview'}
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

                {/* Analytics/Metrics */}
                <div className="border-t border-gray-200 pt-6">
                    <div className="flex items-center gap-4">
                        <div className="flex items-center gap-2 text-sm text-gray-600">
                            <EyeIcon className="h-4 w-4 text-gray-400" />
                            <span className="font-medium text-gray-900">
                                {metricsLoading ? '...' : (viewCount ?? 0)}
                            </span>
                            <span className="text-gray-500">views</span>
                        </div>
                        <div className="flex items-center gap-2 text-sm text-gray-600">
                            <ArrowDownTrayIcon className="h-4 w-4 text-gray-400" />
                            <span className="font-medium text-gray-900">
                                {metricsLoading ? '...' : (downloadCount ?? 0)}
                            </span>
                            <span className="text-gray-500">downloads</span>
                        </div>
                    </div>
                </div>

                {/* Phase 2 – Step 5.5: AI Metadata Suggestions */}
                {displayAsset?.id && (
                    <AiMetadataSuggestionsInline key={displayAsset.id} assetId={displayAsset.id} />
                )}

                {/* Dominant Colors Display (read-only) */}
                {displayAsset?.metadata?.dominant_colors && Array.isArray(displayAsset.metadata.dominant_colors) && displayAsset.metadata.dominant_colors.length > 0 && (
                    <div className="px-6 py-4 border-t border-gray-200">
                        <div className="flex items-center gap-3">
                            <span className="text-sm font-medium text-gray-700">Dominant Colors</span>
                            <DominantColorsSwatches dominantColors={displayAsset.metadata.dominant_colors} />
                        </div>
                    </div>
                )}

                {/* Phase 2 – Step 6: Manual Metadata Editing */}
                {displayAsset?.id && (
                    <AssetMetadataDisplay assetId={displayAsset.id} />
                )}

                {/* Phase 8: Pending Metadata Approval */}
                {displayAsset?.id && (
                    <PendingMetadataList assetId={displayAsset.id} />
                )}

                {/* Phase B9: Metadata Candidate Review */}
                {displayAsset?.id && (
                    <MetadataCandidateReview assetId={displayAsset.id} />
                )}

                {/* Phase 3.1 — Minimal drawer download action.
                    This is a temporary test-only UI.
                    Do not expand into full download UX here. */}
                {displayAsset?.id && (
                    <div className="border-t border-gray-200 pt-6 space-y-3">
                        <button
                            type="button"
                            onClick={() => setShowDetailsModal(true)}
                            className="w-full inline-flex items-center justify-center rounded-md bg-gray-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                        >
                            <EyeIcon className="h-5 w-5 mr-2" />
                            Details
                        </button>
                        <a
                            href={`/app/assets/${displayAsset.id}/download`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="w-full inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            <ArrowDownTrayIcon className="h-5 w-5 mr-2" />
                            Download
                        </a>
                    </div>
                )}

                {/* File Information */}
                <div className="space-y-3 border-t border-gray-200 pt-6">
                    <h3 className="text-sm font-medium text-gray-900">File Information</h3>
                    
                    {/* Created By - moved below preview, at top of file info */}
                    {/* Use displayAsset (with live updates) instead of prop asset */}
                    {displayAsset.uploaded_by && (
                        <div className="flex items-center gap-2 pb-3 mb-3 border-b border-gray-100">
                            {displayAsset.uploaded_by.avatar_url ? (
                                <img
                                    src={displayAsset.uploaded_by.avatar_url}
                                    alt={displayAsset.uploaded_by.name || 'User'}
                                    className="h-6 w-6 rounded-full object-cover flex-shrink-0"
                                />
                            ) : (
                                <div className="h-6 w-6 rounded-full bg-gray-300 flex items-center justify-center flex-shrink-0">
                                    <span className="text-xs font-medium text-gray-600">
                                        {(displayAsset.uploaded_by.first_name?.[0] || displayAsset.uploaded_by.name?.[0] || '?').toUpperCase()}
                                    </span>
                                </div>
                            )}
                            <p className="text-sm text-gray-600">
                                Created by{' '}
                                <span className="font-medium text-gray-900">
                                    {displayAsset.uploaded_by.name || `${displayAsset.uploaded_by.first_name || ''} ${displayAsset.uploaded_by.last_name || ''}`.trim() || 'Unknown User'}
                                </span>
                            </p>
                        </div>
                    )}
                    
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
                                {formatFileSize(displayAsset.size_bytes)}
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
                        {displayAsset.created_at && (
                            <div className="flex justify-between">
                                <dt className="text-sm text-gray-500">Uploaded</dt>
                                <dd className="text-sm font-medium text-gray-900">
                                    {formatDate(displayAsset.created_at)}
                                </dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt className="text-sm text-gray-500">Category</dt>
                            <dd className="text-sm font-medium text-gray-900">
                                {categoryName}
                            </dd>
                        </div>
                        {displayAsset.original_filename && (
                            <div>
                                <dt className="text-sm text-gray-500 mb-1">Filename</dt>
                                <dd className="text-sm font-mono text-gray-700 break-all">
                                    {displayAsset.original_filename}
                                </dd>
                            </div>
                        )}
                        {/* File Dimensions - if available from metadata */}
                        {(() => {
                            // Try to get dimensions from metadata
                            // Dimensions are stored as "widthxheight" (e.g., "1920x1080")
                            // Check metadata.fields structure (as merged by AssetController)
                            let dimensionsValue = null
                            
                            // Check metadata.fields object (keyed by field key)
                            if (displayAsset.metadata?.fields && typeof displayAsset.metadata.fields === 'object') {
                                dimensionsValue = displayAsset.metadata.fields.dimensions || displayAsset.metadata.fields['dimensions']
                            }
                            
                            // Fallback: try metadata_fields array (if available)
                            if (!dimensionsValue && displayAsset.metadata_fields && Array.isArray(displayAsset.metadata_fields)) {
                                const dimensionsField = displayAsset.metadata_fields.find(f => f.field_key === 'dimensions' || f.key === 'dimensions')
                                dimensionsValue = dimensionsField?.value
                            }
                            
                            // Parse and display if valid
                            if (dimensionsValue && typeof dimensionsValue === 'string' && dimensionsValue.includes('x')) {
                                const [width, height] = dimensionsValue.split('x')
                                if (width && height && !isNaN(width) && !isNaN(height)) {
                                    return (
                                        <div className="flex justify-between">
                                            <dt className="text-sm text-gray-500">Dimensions</dt>
                                            <dd className="text-sm font-medium text-gray-900">
                                                {parseInt(width).toLocaleString()} × {parseInt(height).toLocaleString()} px
                                            </dd>
                                        </div>
                                    )
                                }
                            }
                            return null
                        })()}
                    </dl>
                </div>

                {/* Processing State - Skipped (informational, not error) */}
                {/* Show different UI for assets that can now generate thumbnails vs truly unsupported */}
                {thumbnailsSkipped && (
                    <div className="border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900 mb-2">Preview Status</h3>
                        
                        {/* Generate Preview Button - For PDFs that can now be processed */}
                        {canGenerateThumbnail ? (
                            <div className="bg-indigo-50 border border-indigo-200 rounded-md p-4">
                                <p className="text-sm font-medium text-indigo-900 mb-2">
                                    Preview not generated yet
                                </p>
                                <p className="text-xs text-indigo-700 mb-3">
                                    {displayAsset.mime_type === 'application/pdf' || displayAsset.original_filename?.toLowerCase().endsWith('.pdf')
                                        ? 'PDF previews generate from page 1'
                                        : 'Thumbnail generation is now available for this file type'}
                                </p>
                                
                                {/* PDF Size Limit Info */}
                                {(displayAsset.mime_type === 'application/pdf' || displayAsset.original_filename?.toLowerCase().endsWith('.pdf')) && (
                                    <p className="text-xs text-indigo-600 mb-3">
                                        Maximum file size: 150 MB
                                    </p>
                                )}
                                
                                {generateError && (
                                    <div className="mb-3 bg-red-50 border border-red-200 rounded-md p-2">
                                        <p className="text-xs text-red-800">{generateError}</p>
                                    </div>
                                )}
                                
                                <button
                                    type="button"
                                    onClick={handleGenerateThumbnail}
                                    disabled={generateLoading}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {generateLoading ? (
                                        <>
                                            <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                            Generating...
                                        </>
                                    ) : (
                                        <>
                                            Generate Preview
                                        </>
                                    )}
                                </button>
                            </div>
                        ) : (
                            /* Truly unsupported file types - show static message */
                            <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
                                <p className="text-sm font-medium text-blue-800 mb-1">
                                    Preview not available for this file type.
                                </p>
                                {/* Show skip reason if available in metadata */}
                                {/* Use displayAsset (with live updates) instead of prop asset */}
                                {displayAsset.metadata?.thumbnail_skip_reason && (
                                    <p className="text-xs text-blue-600 mt-1">
                                        Reason: {displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:tiff' 
                                            ? 'Unsupported file type (TIFF)' 
                                            : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:avif'
                                            ? 'Unsupported file type (AVIF)'
                                            : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:bmp'
                                            ? 'Unsupported file type (BMP)'
                                            : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg'
                                            ? 'Unsupported file type (SVG)'
                                            : 'Unsupported file type'}
                                    </p>
                                )}
                                {/* Fallback to thumbnail_error if skip_reason not in metadata */}
                                {!displayAsset.metadata?.thumbnail_skip_reason && displayAsset.thumbnail_error && (
                                    <p className="text-xs text-blue-600 mt-1">
                                        {displayAsset.thumbnail_error}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                )}


                {/* Processing State - Failed (error with details) */}
                {/* Use displayAsset (with live updates) instead of prop asset */}
                {thumbnailsFailed && displayAsset.thumbnail_error && (
                    <div className="border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900 mb-2">Processing Error</h3>
                        <div className="bg-red-50 border border-red-200 rounded-md p-3">
                            <p className="text-sm font-medium text-red-800 mb-1">
                                Preview failed to generate
                            </p>
                            <p className="text-sm text-red-700 mb-3">{displayAsset.thumbnail_error}</p>
                            
                            {/* PDF Size Limit Error - Show additional info for admins */}
                            {displayAsset.thumbnail_error?.includes('exceeds maximum allowed size') && (
                                <div className="mt-3 pt-3 border-t border-red-200">
                                    <p className="text-xs text-red-700 mb-1">
                                        <strong>File size limit:</strong> PDFs larger than 150 MB cannot be processed for thumbnail generation.
                                    </p>
                                    <p className="text-xs text-red-600">
                                        This limit prevents memory exhaustion and processing timeouts. Consider using a smaller PDF or splitting the file.
                                    </p>
                                </div>
                            )}
                            
                            {/* Retry button - only show if retry is allowed */}
                            {canRetryThumbnail && (
                                <button
                                    type="button"
                                    onClick={() => setShowRetryModal(true)}
                                    className="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                >
                                    <ArrowPathIcon className="h-4 w-4 mr-2" />
                                    Retry Thumbnail Generation
                                </button>
                            )}
                            
                            {/* Retry limit or unsupported type message */}
                            {!canRetryThumbnail && getRetryErrorMessage() && (
                                <p className="text-xs text-red-600 mt-2">
                                    {getRetryErrorMessage()}
                                </p>
                            )}
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

            {/* Phase 3.1: Zoom Modal with Carousel for Assets with Thumbnails (Images and PDFs) */}
            {showZoomModal && hasThumbnailSupport && currentCarouselAsset?.id && (
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

            {/* Retry Confirmation Modal */}
            {showRetryModal && (
                <div className="fixed inset-0 z-[70] bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center mb-4">
                            <ExclamationTriangleIcon className="h-6 w-6 text-yellow-600 mr-3" />
                            <h3 className="text-lg font-semibold text-gray-900">Retry Thumbnail Generation</h3>
                        </div>
                        
                        <p className="text-sm text-gray-600 mb-4">
                            This will attempt to regenerate thumbnails for this asset. 
                            {displayAsset.thumbnail_retry_count > 0 && (
                                <span className="block mt-1">
                                    Previous attempts: {displayAsset.thumbnail_retry_count} of 3
                                </span>
                            )}
                        </p>
                        
                        {retryError && (
                            <div className="mb-4 bg-red-50 border border-red-200 rounded-md p-3">
                                <p className="text-sm text-red-800">{retryError}</p>
                            </div>
                        )}
                        
                        <div className="flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowRetryModal(false)
                                    setRetryError(null)
                                }}
                                disabled={retryLoading}
                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleRetryThumbnail}
                                disabled={retryLoading || !canRetryThumbnail}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            >
                                {retryLoading ? (
                                    <>
                                        <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                        Retrying...
                                    </>
                                ) : (
                                    <>
                                        <ArrowPathIcon className="h-4 w-4 mr-2" />
                                        Retry
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Asset Details Modal */}
            {displayAsset && (
                <AssetDetailsModal
                    asset={displayAsset}
                    isOpen={showDetailsModal}
                    onClose={() => setShowDetailsModal(false)}
                />
            )}
        </div>
    )
}

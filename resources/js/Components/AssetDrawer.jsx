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
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { XMarkIcon, ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon, ExclamationTriangleIcon, EyeIcon, ArrowDownTrayIcon, CheckCircleIcon, CheckIcon, ArrowUturnLeftIcon, ClockIcon, XCircleIcon, CloudArrowUpIcon, RectangleStackIcon, TicketIcon } from '@heroicons/react/24/outline'
import { usePage, router } from '@inertiajs/react'
import AssetImage from './AssetImage'
import AssetTimeline from './AssetTimeline'
import AiTagSuggestionsInline from './AiTagSuggestionsInline'
import AssetTagManager from './AssetTagManager'
import AssetMetadataDisplay from './AssetMetadataDisplay'
import PendingMetadataList from './PendingMetadataList'
import MetadataCandidateReview from './MetadataCandidateReview'
import ThumbnailPreview from './ThumbnailPreview'
import ReplaceFileModal from './ReplaceFileModal'
import AssetDetailPanel from './AssetDetailPanel'
import CollapsibleSection from './CollapsibleSection'
import ApprovalHistory from './ApprovalHistory'
import PendingAssetReviewModal from './PendingAssetReviewModal'
import PDFViewer from './PDFViewer'
import { getThumbnailState, getThumbnailVersion } from '../utils/thumbnailUtils'
import { getPipelineStageLabel, getPipelineStageIndex, PIPELINE_STAGES } from '../utils/pipelineStatusUtils'
import { getAssetCategoryId } from '../utils/assetUtils'
import { usePermission } from '../hooks/usePermission'
import { useDrawerThumbnailPoll } from '../hooks/useDrawerThumbnailPoll'
import { useAssetMetrics } from '../hooks/useAssetMetrics'
import { CheckCircleIcon as CheckCircleIconSolid } from '@heroicons/react/24/solid'
import CollectionSelector from './Collections/CollectionSelector' // C9.1
import CreateCollectionModal from './Collections/CreateCollectionModal' // C9.1
import { useSelectionOptional } from '../contexts/SelectionContext'

export default function AssetDrawer({ asset, onClose, assets = [], currentAssetIndex = null, onAssetUpdate = null, collectionContext = null, bucketAssetIds = [], onBucketToggle = null, primaryColor, selectionAssetType = 'asset' }) {
    const { auth, download_policy_disable_single_asset: policyDisableSingleAsset = false } = usePage().props
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
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
    const [reprocessLoading, setReprocessLoading] = useState(false)
    const [extractAllLoading, setExtractAllLoading] = useState(false)
    const [extractAllError, setExtractAllError] = useState(null)
    const [extractAllBatchId, setExtractAllBatchId] = useState(null)
    // Details modal state
    const [showDetailsModal, setShowDetailsModal] = useState(false)
    // Publish confirmation modal state
    const [showPublishModal, setShowPublishModal] = useState(false)
    const [publishLoading, setPublishLoading] = useState(false)
    // Phase AF-2: Resubmit state
    const [showResubmitModal, setShowResubmitModal] = useState(false)
    const [resubmitComment, setResubmitComment] = useState('')
    const [resubmitLoading, setResubmitLoading] = useState(false)
    const [resubmitFile, setResubmitFile] = useState(null)
    const [resubmitUploadProgress, setResubmitUploadProgress] = useState(0)
    const [resubmitError, setResubmitError] = useState(null)
    const resubmitFileInputRef = useRef(null)
    // Quick approve/reject modal state
    const [showReviewModal, setShowReviewModal] = useState(false)
    
    // Phase J.3.1: Replace file state
    const [showReplaceFileModal, setShowReplaceFileModal] = useState(false)
    
    // Asset delete (soft delete) confirmation state
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
    const [deleteLoading, setDeleteLoading] = useState(false)
    
    // Phase J.3: Approval comments for rejection role display
    const [approvalComments, setApprovalComments] = useState([])
    const [commentsLoading, setCommentsLoading] = useState(false)

    // Unified Operations: Unresolved incidents for asset (processing issues)
    const [assetIncidents, setAssetIncidents] = useState([])
    const [incidentsLoading, setIncidentsLoading] = useState(false)
    // Reliability Timeline: all incidents (resolved + unresolved) for collapsible section
    const [reliabilityTimeline, setReliabilityTimeline] = useState([])
    const [reliabilityTimelineLoading, setReliabilityTimelineLoading] = useState(false)
    const [reliabilityTimelineExpanded, setReliabilityTimelineExpanded] = useState(false)
    const [retryProcessingLoading, setRetryProcessingLoading] = useState(false)
    const [submitTicketLoading, setSubmitTicketLoading] = useState(false)
    
    // Metadata approval state
    const [pendingMetadataCount, setPendingMetadataCount] = useState(0)
    const [approvingAllMetadata, setApprovingAllMetadata] = useState(false)

    // C5: Collections (In X collections + Add to Collection)
    const [assetCollections, setAssetCollections] = useState([])
    const [assetCollectionsLoading, setAssetCollectionsLoading] = useState(false)
    const [dropdownCollections, setDropdownCollections] = useState([])
    const [dropdownCollectionsLoading, setDropdownCollectionsLoading] = useState(false)
    const [addToCollectionLoading, setAddToCollectionLoading] = useState(false)
    const [showCreateCollectionModal, setShowCreateCollectionModal] = useState(false) // C9.1: Modal state
    const [showCollectionsModal, setShowCollectionsModal] = useState(false) // C9.1: Modal for inline collections edit
    // PDF text extraction (OCR): extraction data, loading, trigger loading, preview modal
    const [pdfTextExtraction, setPdfTextExtraction] = useState(null)
    const [pdfTextExtractionLoading, setPdfTextExtractionLoading] = useState(false)
    const [pdfOcrTriggerLoading, setPdfOcrTriggerLoading] = useState(false)
    const [showPdfTextModal, setShowPdfTextModal] = useState(false)
    const pdfOcrPollRef = useRef(null)
    /** C9.2: Collection field visibility (category-driven, matches Tags behavior) */
    // Collections follow the same visibility resolution as Tags - check if collection field appears in metadata schema
    const [collectionFieldVisible, setCollectionFieldVisible] = useState(false)
    
    // Toast notification state
    const [toastMessage, setToastMessage] = useState(null)
    const [toastType, setToastType] = useState('success')
    const [toastTicketUrl, setToastTicketUrl] = useState(null)
    
    // Phase 3.1: Get assets with thumbnail support or video support for carousel (images, PDFs, PSDs, and videos)
    const imageAssets = useMemo(() => {
        const safe = (assets || []).filter(Boolean)
        if (safe.length === 0) return []
        return safe.filter(a => {
            const ext = (a.file_extension || a.original_filename?.split('.').pop() || '').toUpperCase()
            const mimeType = a.mime_type || ''
            const isVideoFile = mimeType.startsWith('video/') || ['MP4', 'MOV', 'AVI', 'MKV', 'WEBM', 'M4V'].includes(ext)
            // Include images, PDFs, PSDs (all support thumbnail generation), and videos with posters
            return mimeType.startsWith('image/') || 
                   mimeType === 'application/pdf' ||
                   mimeType === 'image/vnd.adobe.photoshop' || // PSD/PSB files
                   (isVideoFile && (a.video_poster_url || a.thumbnail_url || a.final_thumbnail_url)) ||
                   ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG', 'BMP', 'TIF', 'TIFF', 'PDF', 'PSD', 'PSB'].includes(ext)
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
            const index = imageAssets.findIndex(a => a?.id === asset?.id)
            if (index >= 0 && index !== carouselIndex) {
                setCarouselIndex(index)
            }
        }
    }, [asset?.id, imageAssets, carouselIndex, showZoomModal])

    // Initialize metrics tracking hook (must be before useEffects that use it)
    const { trackView, getViewCount, getDownloadCount } = useAssetMetrics()
    // Phase 3: SelectionContext for Add to download button
    const selection = useSelectionOptional()
    
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
    }, [asset?.id])

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
    }, [asset?.id])

    // C5: Fetch collections this asset is in (for "In X collections")
    // C9.1: Always fetch collections if asset exists (not dependent on collectionContext)
    useEffect(() => {
        if (!asset?.id) {
            setAssetCollections([])
            return
        }
        setAssetCollectionsLoading(true)
        window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
            .then(res => {
                // C9.1: DEBUG - Log collections received
                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
            })
            .catch((err) => {
                // C9.1: DEBUG - Log error
                console.error('[AssetDrawer] Error fetching collections', {
                    asset_id: asset.id,
                    error: err.message,
                    response: err.response?.data,
                })
                setAssetCollections([])
            })
            .finally(() => setAssetCollectionsLoading(false))
    }, [asset?.id])

    // C5: Fetch collections list for "Add to Collection" dropdown
    // C9.1: Always fetch collections list (not dependent on collectionContext) for inline modal
    useEffect(() => {
        if (!asset?.id) {
            setDropdownCollections([])
            return
        }
        setDropdownCollectionsLoading(true)
        window.axios.get('/app/collections/list', { headers: { Accept: 'application/json' } })
            .then(res => {
                setDropdownCollections((res.data?.collections ?? []).filter(Boolean))
            })
            .catch(() => setDropdownCollections([]))
            .finally(() => setDropdownCollectionsLoading(false))
    }, [asset?.id])

    // C9.2: Category ID for edit schema (drawer respects Metadata Management Quick View)
    const assetCategoryId = getAssetCategoryId(asset)

    // C9.2: Collection field visibility from edit schema (Quick View checkbox in Metadata Management)
    useEffect(() => {
        if (!assetCategoryId) {
            setCollectionFieldVisible(false)
            return
        }

        const mime = asset?.mime_type?.toLowerCase() || ''
        let assetType = 'image'
        if (mime.startsWith('video/')) assetType = 'video'
        else if (mime.includes('pdf') || mime.includes('document') || mime.includes('text')) assetType = 'document'

        const params = new URLSearchParams({
            category_id: String(assetCategoryId),
            asset_type: assetType,
            context: 'edit',
        })
        const schemaUrl = `/app/uploads/metadata-schema?${params.toString()}`

        fetch(schemaUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) throw new Error(`Failed to fetch metadata schema: ${response.status}`)
                return response.json()
            })
            .then((data) => {
                if (data.error) throw new Error(data.message || 'Failed to load metadata schema')
                const hasCollectionField = data.groups?.some(group =>
                    (group.fields || []).some(field => (field.key || field.field_key) === 'collection')
                ) ?? false
                setCollectionFieldVisible(hasCollectionField)
            })
            .catch(() => setCollectionFieldVisible(false))
    }, [asset?.id, assetCategoryId, asset?.mime_type, asset?.category, asset?.metadata])

    // Phase J.3: Fetch approval comments for rejected assets (to get rejecting user role)
    useEffect(() => {
        if (!asset || !asset.id || !auth?.activeBrand || asset.approval_status !== 'rejected') {
            setApprovalComments([])
            setCommentsLoading(false)
            return
        }

        setCommentsLoading(true)
        fetch(`/app/brands/${auth.activeBrand.id}/assets/${asset.id}/approval-history`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Failed to load approval history')
                }
                return res.json()
            })
            .then(data => {
                setApprovalComments(data.comments || [])
                setCommentsLoading(false)
            })
            .catch(err => {
                console.error('Failed to load approval comments:', err)
                setApprovalComments([])
                setCommentsLoading(false)
            })
    }, [asset?.id, asset?.approval_status, auth?.activeBrand?.id])

    // Dominant colors are now displayed as a metadata field, no longer needed in File Information

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
    // CRITICAL: Grid owns asset state - drawer is a consumer
    // Polling updates drawerAsset for display, but grid state (asset prop) is source of truth
    // When grid updates asset (via handleThumbnailUpdate/handleLifecycleUpdate), prop changes and drawerAsset syncs
    const { drawerAsset } = useDrawerThumbnailPoll({
        asset,
        onAssetUpdate: (updatedAsset) => {
            // Polling callback - drawerAsset is updated internally by hook
            // Grid state updates come via asset prop changes, not through this callback
            // This callback is for future use if we need to notify parent of polling updates
        },
    })

    // Use drawerAsset (with live updates) for thumbnail display
    // Fallback to prop asset if drawerAsset not yet initialized
    // CRITICAL: Drawer must tolerate undefined asset during async updates
    // Asset may be temporarily undefined while localAssets array is being updated
    const displayAsset = drawerAsset || asset || null

    // Fetch unresolved incidents when display asset changes (Unified Operations)
    useEffect(() => {
        if (!displayAsset?.id) {
            setAssetIncidents([])
            return
        }
        setIncidentsLoading(true)
        window.axios.get(`/app/assets/${displayAsset.id}/incidents`)
            .then(res => {
                setAssetIncidents((res.data?.incidents ?? []).filter(Boolean))
            })
            .catch(() => setAssetIncidents([]))
            .finally(() => setIncidentsLoading(false))
    }, [displayAsset?.id])

    // Fetch Reliability Timeline when section expanded (lazy load)
    useEffect(() => {
        if (!reliabilityTimelineExpanded || !displayAsset?.id) return
        setReliabilityTimelineLoading(true)
        window.axios.get(`/app/assets/${displayAsset.id}/incidents`, { params: { timeline: 1 } })
            .then(res => {
                setReliabilityTimeline((res.data?.incidents ?? []).filter(Boolean))
            })
            .catch(() => setReliabilityTimeline([]))
            .finally(() => setReliabilityTimelineLoading(false))
    }, [reliabilityTimelineExpanded, displayAsset?.id])

    // Phase V-1: Detect if asset is a video
    const isVideo = useMemo(() => {
        if (!displayAsset) return false
        const mimeType = displayAsset.mime_type || ''
        const filename = displayAsset.original_filename || ''
        const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
        const ext = filename.split('.').pop()?.toLowerCase() || ''
        return mimeType.startsWith('video/') || videoExtensions.includes(ext)
    }, [displayAsset])

    const isPdf = useMemo(() => {
        if (!displayAsset) return false
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const ext = (displayAsset.original_filename || '').split('.').pop()?.toLowerCase() || ''

        return mimeType.includes('pdf') || ext === 'pdf'
    }, [displayAsset])
    const tenantRoleForPdfActions = String(auth?.tenant_role || auth?.user?.tenant_role || '').toLowerCase()
    const canRequestFullPdfExtraction = ['owner', 'admin'].includes(tenantRoleForPdfActions)

    // Phase V-1: Hover video preview state (for drawer)
    const [isHoveringVideo, setIsHoveringVideo] = useState(false)
    const [videoPreviewLoaded, setVideoPreviewLoaded] = useState(false)
    const videoPreviewRef = useRef(null)
    const isMobile = typeof window !== 'undefined' ? window.innerWidth < 768 : false
    const [pdfCurrentPage, setPdfCurrentPage] = useState(1)
    const [pdfPageCache, setPdfPageCache] = useState({})
    const [pdfKnownPageCount, setPdfKnownPageCount] = useState(null)
    const [pdfPageLoading, setPdfPageLoading] = useState(false)
    const [pdfPageError, setPdfPageError] = useState(null)
    const [pdfFullExtractionLoading, setPdfFullExtractionLoading] = useState(false)
    const [pdfFullExtractionRequested, setPdfFullExtractionRequested] = useState(false)
    const pdfPollTimeoutRef = useRef(null)
    
    // Phase V-1: Video view URL state (for gallery view)
    const [videoViewUrl, setVideoViewUrl] = useState(null)
    const [videoViewUrlLoading, setVideoViewUrlLoading] = useState(false)

    // Use displayAsset for carousel (with live updates)
    const currentCarouselAsset = imageAssets[carouselIndex] || displayAsset
    const canNavigateLeft = carouselIndex > 0
    const canNavigateRight = carouselIndex < imageAssets.length - 1

    // Phase V-1: Fetch view URL for video when gallery opens
    // NOTE: Must be after currentCarouselAsset is defined
    useEffect(() => {
        if (showZoomModal && currentCarouselAsset?.id) {
            const currentMimeType = currentCarouselAsset.mime_type || ''
            const currentFilename = currentCarouselAsset.original_filename || ''
            const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
            const ext = currentFilename.split('.').pop()?.toLowerCase() || ''
            const isCurrentVideo = currentMimeType.startsWith('video/') || videoExtensions.includes(ext)
            
            if (isCurrentVideo) {
                // Fetch view URL (not download URL) for video - source file always available when processing complete
                setVideoViewUrlLoading(true)
                fetch(`/app/assets/${currentCarouselAsset.id}/view`, {
                    headers: { 'Accept': 'application/json' },
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.url) {
                            setVideoViewUrl(data.url)
                        } else {
                            console.warn('[AssetDrawer] Failed to get view URL for video:', data)
                            setVideoViewUrl(null)
                        }
                    })
                    .catch(err => {
                        console.error('[AssetDrawer] Error fetching view URL:', err)
                        setVideoViewUrl(null)
                    })
                    .finally(() => {
                        setVideoViewUrlLoading(false)
                    })
            } else {
                setVideoViewUrl(null)
                setVideoViewUrlLoading(false)
            }
        } else {
            setVideoViewUrl(null)
            setVideoViewUrlLoading(false)
        }
    }, [showZoomModal, currentCarouselAsset?.id, currentCarouselAsset?.mime_type, currentCarouselAsset?.original_filename])

    const effectivePdfPageCount = Math.max(
        1,
        Number(pdfKnownPageCount || displayAsset?.pdf_page_count || 1)
    )

    const fetchPdfPage = useCallback(async (pageToFetch, attempt = 0) => {
        if (!isPdf || !displayAsset?.id) return

        setPdfPageLoading(true)
        setPdfPageError(null)

        try {
            const response = await window.axios.get(`/app/assets/${displayAsset.id}/pdf-page/${pageToFetch}`, {
                headers: { Accept: 'application/json' },
            })
            const payload = response?.data || {}

            if (payload.page_count != null) {
                setPdfKnownPageCount(Number(payload.page_count))
            }

            if (payload.status === 'ready' && payload.url) {
                setPdfPageCache(prev => ({ ...prev, [pageToFetch]: payload.url }))
                setPdfPageLoading(false)
                return
            }

            if (payload.status === 'processing') {
                if (attempt >= 20) {
                    setPdfPageLoading(false)
                    setPdfPageError('Still rendering this page. Please try again in a few seconds.')
                    return
                }

                const pollDelay = Number(payload.poll_after_ms || 1200)
                if (pdfPollTimeoutRef.current) {
                    clearTimeout(pdfPollTimeoutRef.current)
                }
                pdfPollTimeoutRef.current = setTimeout(() => {
                    fetchPdfPage(pageToFetch, attempt + 1)
                }, pollDelay)
                return
            }

            // Terminal failure from API (e.g. page render failed) — stop polling and show error
            if (payload.status === 'failed') {
                setPdfPageLoading(false)
                setPdfPageError(payload.message || 'PDF page could not be rendered.')
                return
            }

            setPdfPageLoading(false)
            setPdfPageError(payload.message || 'Unable to load PDF page.')
        } catch (error) {
            const status = error?.response?.status
            const message = error?.response?.data?.message
            setPdfPageLoading(false)
            if (status === 422 && message) {
                setPdfPageError(message)
                return
            }
            setPdfPageError('Unable to load PDF page right now.')
        }
    }, [displayAsset?.id, isPdf])

    useEffect(() => {
        if (pdfPollTimeoutRef.current) {
            clearTimeout(pdfPollTimeoutRef.current)
            pdfPollTimeoutRef.current = null
        }

        setPdfCurrentPage(1)
        setPdfPageCache({})
        setPdfKnownPageCount(null)
        setPdfPageError(null)
        setPdfPageLoading(false)
        setPdfFullExtractionLoading(false)
        setPdfFullExtractionRequested(Boolean(displayAsset?.metadata?.pdf_full_extraction_requested))

        if (!isPdf || !displayAsset?.id) {
            return undefined
        }

        fetchPdfPage(1)

        return () => {
            if (pdfPollTimeoutRef.current) {
                clearTimeout(pdfPollTimeoutRef.current)
                pdfPollTimeoutRef.current = null
            }
        }
        // Re-fetch PDF page when asset updates (e.g. after Retry Processing completes and thumbnail_status becomes completed)
    }, [displayAsset?.id, displayAsset?.thumbnail_status?.value ?? displayAsset?.thumbnail_status ?? '', fetchPdfPage, isPdf])

    const handlePdfPageNavigate = useCallback((nextPage) => {
        if (!isPdf) return
        if (nextPage < 1 || nextPage > effectivePdfPageCount) return

        setPdfCurrentPage(nextPage)
        if (!pdfPageCache[nextPage]) {
            fetchPdfPage(nextPage)
        }
    }, [effectivePdfPageCount, fetchPdfPage, isPdf, pdfPageCache])

    const handleRequestFullPdfExtraction = useCallback(async () => {
        if (!isPdf || !displayAsset?.id || pdfFullExtractionLoading || !canRequestFullPdfExtraction) {
            return
        }

        setPdfFullExtractionLoading(true)
        try {
            const response = await window.axios.post(
                `/app/assets/${displayAsset.id}/pdf-pages/full-extraction`,
                {},
                { headers: { Accept: 'application/json' } }
            )

            setPdfFullExtractionRequested(true)
            setToastMessage(response?.data?.message || 'Full PDF extraction queued.')
            setToastType('success')
            setTimeout(() => setToastMessage(null), 4000)

            if (onAssetUpdate) {
                onAssetUpdate({
                    ...displayAsset,
                    pdf_pages_rendered: false,
                    metadata: {
                        ...(displayAsset.metadata || {}),
                        pdf_full_extraction_requested: true,
                    },
                })
            }
        } catch (error) {
            setToastMessage(error?.response?.data?.message || 'Failed to queue full PDF extraction.')
            setToastType('error')
            setTimeout(() => setToastMessage(null), 5000)
        } finally {
            setPdfFullExtractionLoading(false)
        }
    }, [
        canRequestFullPdfExtraction,
        displayAsset,
        isPdf,
        onAssetUpdate,
        pdfFullExtractionLoading,
    ])

    const fetchPdfTextExtraction = useCallback(async () => {
        if (!isPdf || !displayAsset?.id) return
        setPdfTextExtractionLoading(true)
        try {
            const response = await window.axios.get(
                `/app/assets/${displayAsset.id}/pdf-text-extraction`,
                { headers: { Accept: 'application/json' } }
            )
            setPdfTextExtraction(response?.data?.extraction ?? null)
        } catch {
            setPdfTextExtraction(null)
        } finally {
            setPdfTextExtractionLoading(false)
        }
    }, [displayAsset?.id, isPdf])

    useEffect(() => {
        if (!isPdf || !displayAsset?.id) {
            setPdfTextExtraction(null)
            return
        }
        fetchPdfTextExtraction()
    }, [displayAsset?.id, isPdf, fetchPdfTextExtraction])

    useEffect(() => {
        if (!pdfTextExtraction || !['pending', 'processing'].includes(pdfTextExtraction.status)) {
            if (pdfOcrPollRef.current) {
                clearInterval(pdfOcrPollRef.current)
                pdfOcrPollRef.current = null
            }
            return
        }
        pdfOcrPollRef.current = setInterval(fetchPdfTextExtraction, 2500)
        return () => {
            if (pdfOcrPollRef.current) {
                clearInterval(pdfOcrPollRef.current)
                pdfOcrPollRef.current = null
            }
        }
    }, [pdfTextExtraction?.id, pdfTextExtraction?.status, fetchPdfTextExtraction])

    const handleTriggerPdfOcr = useCallback(async () => {
        if (!isPdf || !displayAsset?.id || pdfOcrTriggerLoading || !canRequestFullPdfExtraction) return
        setPdfOcrTriggerLoading(true)
        try {
            await window.axios.post(
                `/app/assets/${displayAsset.id}/pdf-text-extraction`,
                {},
                { headers: { Accept: 'application/json' } }
            )
            setToastMessage('Text extraction started.')
            setToastType('success')
            setTimeout(() => setToastMessage(null), 3000)
            await fetchPdfTextExtraction()
        } catch (err) {
            setToastMessage(err?.response?.data?.message || 'Failed to start text extraction.')
            setToastType('error')
            setTimeout(() => setToastMessage(null), 5000)
        } finally {
            setPdfOcrTriggerLoading(false)
        }
    }, [canRequestFullPdfExtraction, displayAsset?.id, fetchPdfTextExtraction, isPdf, pdfOcrTriggerLoading])

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
    
    // Assets that support thumbnail preview (images, PDFs, PSDs, EPS, AI)
    const hasThumbnailSupport = isImage || 
                                displayAsset.mime_type === 'application/pdf' || 
                                displayAsset.mime_type === 'image/vnd.adobe.photoshop' ||
                                displayAsset.mime_type === 'application/postscript' ||
                                displayAsset.mime_type === 'application/vnd.adobe.illustrator' ||
                                displayAsset.mime_type === 'application/illustrator' ||
                                fileExtension.toUpperCase() === 'PDF' ||
                                fileExtension.toUpperCase() === 'PSD' ||
                                fileExtension.toUpperCase() === 'PSB' ||
                                fileExtension.toUpperCase() === 'EPS' ||
                                fileExtension.toUpperCase() === 'AI'
    const isPdfAsset = Boolean(displayAsset?.is_pdf)
        || displayAsset.mime_type === 'application/pdf'
        || fileExtension.toUpperCase() === 'PDF'

    // Phase 3.1: Derive stable thumbnail version signal
    // This ensures ThumbnailPreview re-evaluates after live polling updates
    // CRITICAL: Include final_thumbnail_url and preview_thumbnail_url so version changes when poll updates them
    const thumbnailVersion = useMemo(() => getThumbnailVersion(displayAsset), [
        displayAsset?.id,
        displayAsset?.thumbnail_url,
        displayAsset?.final_thumbnail_url, // Include final URL so version changes when poll updates it
        displayAsset?.preview_thumbnail_url, // Include preview URL so version changes when poll updates it
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

    // Format video duration (seconds to MM:SS or HH:MM:SS)
    const formatVideoDuration = (seconds) => {
        if (!seconds || seconds <= 0) return null
        const hours = Math.floor(seconds / 3600)
        const minutes = Math.floor((seconds % 3600) / 60)
        const secs = Math.floor(seconds % 60)
        
        if (hours > 0) {
            return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
        }
        return `${minutes}:${secs.toString().padStart(2, '0')}`
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
    const { can } = usePermission()
    const canRetryThumbnails = can('assets.retry_thumbnails')
    const canPublish = can('asset.publish')
    const canApproveMetadata = can('metadata.bypass_approval')
    // Admins/brand_managers: assets.delete (any file). Managers: assets.delete_own (own files only)
    const canDeleteAny = can('assets.delete')
    const canDeleteOwn = can('assets.delete_own')
    const assetOwnerId = displayAsset?.user_id ?? displayAsset?.uploaded_by?.id
    const isOwnAsset = assetOwnerId != null && String(assetOwnerId) === String(auth?.user?.id)
    const canDelete = canDeleteAny || (canDeleteOwn && isOwnAsset)

    // Check if asset can have thumbnail generated (for previously skipped or pending assets)
    // - SKIPPED: was unsupported, now supported (e.g. PDF/SVG/TIFF/AVIF support added)
    // - PENDING: user removed preview and wants to regenerate
    // PERMISSION CHECK: User must have assets.retry_thumbnails permission
    // BUTTON HIDING: Button is hidden during generation (processing) or while loading
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
        
        // Hide button if thumbnail is actively being processed
        if (thumbnailStatus === 'processing') {
            return false
        }
        
        // Show for PENDING (e.g. after Remove Preview) or SKIPPED (was unsupported)
        if (thumbnailStatus !== 'skipped' && thumbnailStatus !== 'pending') {
            return false
        }
        
        // Supported file types (must align with backend /thumbnails/generate)
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        
        if (mimeType === 'application/pdf' || extension === 'pdf') return true
        if (mimeType === 'image/svg+xml' || extension === 'svg') return true
        if (['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'].includes(mimeType)) return true
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) return true
        if (mimeType === 'image/tiff' || mimeType === 'image/tif' || ['tiff', 'tif'].includes(extension)) return true
        if (mimeType === 'image/avif' || extension === 'avif') return true
        
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

    // Reprocess Asset — full pipeline (same as upload). Use when Regenerate Preview doesn't work.
    const handleReprocessAsset = async () => {
        if (!displayAsset?.id || !canRetryThumbnails) return
        setReprocessLoading(true)
        try {
            await window.axios.post(`/app/assets/${displayAsset.id}/reprocess`)
            setToastMessage('Asset reprocessing started. Ensure queue worker is running.')
            setToastType('success')
            setTimeout(() => setToastMessage(null), 5000)
            if (onAssetUpdate) onAssetUpdate()
            router.reload({ only: ['assets'] })
        } catch (e) {
            setToastMessage(e.response?.data?.message || 'Failed to reprocess asset')
            setToastType('error')
            setTimeout(() => setToastMessage(null), 5000)
        } finally {
            setReprocessLoading(false)
        }
    }

    const handleExtractAllPdfPages = async () => {
        if (!displayAsset?.id) return

        setExtractAllLoading(true)
        setExtractAllError(null)
        setExtractAllBatchId(null)

        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/pdf/extract-all`)
            const batchId = response?.data?.batch_id || null

            setExtractAllBatchId(batchId)
            setToastMessage(batchId
                ? `PDF extraction started (batch ${batchId.slice(0, 8)}...).`
                : 'PDF extraction started.')
            setToastType('success')
            setTimeout(() => setToastMessage(null), 5000)

            if (onAssetUpdate) onAssetUpdate()
        } catch (e) {
            const message = e?.response?.data?.message || 'Failed to start PDF extraction.'
            setExtractAllError(message)
        } finally {
            setExtractAllLoading(false)
        }
    }
    
    
    // Handle asset delete (soft delete — permanent after grace period)
    const handleDeleteConfirm = async () => {
        if (!displayAsset?.id || !canDelete || deleteLoading) return
        setDeleteLoading(true)
        try {
            const response = await window.axios.delete(`/app/assets/${displayAsset.id}`)
            if (response.data?.message === 'Asset deleted successfully') {
                setShowDeleteConfirm(false)
                onClose()
                router.reload({ only: ['assets'] })
            } else {
                setToastMessage(response.data?.message || 'Failed to delete asset')
                setToastType('error')
            }
        } catch (err) {
            setToastMessage(err.response?.data?.message || err.message || 'Failed to delete asset')
            setToastType('error')
        } finally {
            setDeleteLoading(false)
        }
    }

    // Cleanup timeout on unmount or when asset changes
    useEffect(() => {
        return () => {
            if (generateTimeoutId) {
                clearTimeout(generateTimeoutId)
            }
        }
    }, [generateTimeoutId])

    useEffect(() => {
        setExtractAllLoading(false)
        setExtractAllError(null)
        setExtractAllBatchId(null)
    }, [displayAsset?.id])
    
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
        
        // Must be in failed, pending, or skipped state
        // Allow skipped assets to be retried (they may now be supported, e.g., TIFF/AVIF)
        if (thumbnailStatus !== 'failed' && thumbnailStatus !== 'pending' && thumbnailStatus !== 'skipped') {
            return false
        }
        
        // Check retry limit (default: 3)
        const maxRetries = 3
        const retryCount = displayAsset.thumbnail_retry_count || 0
        if (retryCount >= maxRetries) {
            return false
        }
        
        // Check if file type is supported (same logic as backend)
        // Includes both images (GD), PDFs (ImageMagick/Ghostscript), and TIFF/AVIF (Imagick)
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        const supportedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'image/tiff', 'image/tif', 'image/avif', 'image/vnd.adobe.photoshop', 'application/postscript', 'application/vnd.adobe.illustrator', 'application/illustrator']
        const supportedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'tiff', 'tif', 'avif', 'psd', 'psb', 'ai']
        
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
        const supportedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'image/tiff', 'image/tif', 'image/avif', 'image/vnd.adobe.photoshop', 'application/postscript', 'application/vnd.adobe.illustrator', 'application/illustrator']
        const supportedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'tiff', 'tif', 'avif', 'psd', 'psb', 'ai']
        
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

    // Video preview retry handler
    const [videoPreviewRetryLoading, setVideoPreviewRetryLoading] = useState(false)
    const handleRetryVideoPreview = async () => {
        if (!displayAsset?.id || !isVideo) return
        
        setVideoPreviewRetryLoading(true)
        
        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/thumbnails/regenerate-video-preview`)
            
            if (response.data.success) {
                // Refresh activity events to show new "started" event
                fetchActivityEvents()
                // Show success message
                setToastMessage('Video preview regeneration started')
                setToastType('success')
            } else {
                setToastMessage(response.data.error || 'Failed to retry video preview generation')
                setToastType('error')
            }
        } catch (error) {
            console.error('Video preview retry error:', error)
            
            if (error.response) {
                const status = error.response.status
                const errorMessage = error.response.data?.error || 'Failed to retry video preview generation'
                
                if (status === 403) {
                    setToastMessage('You do not have permission to regenerate video previews')
                } else if (status === 404) {
                    setToastMessage('Asset not found')
                } else if (status === 422) {
                    setToastMessage(errorMessage)
                } else {
                    setToastMessage(errorMessage)
                }
            } else {
                setToastMessage('Network error. Please try again.')
            }
            setToastType('error')
        } finally {
            setVideoPreviewRetryLoading(false)
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
                <div className="px-6 py-4">
                    <div className="flex items-center justify-between">
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
                    
                    {/* Phase L.4: Lifecycle Badges (read-only indicators) */}
                    {/* Lifecycle badges moved to below preview image */}
                </div>
            </div>

            {/* Content */}
            <div className="px-4 py-4 space-y-4">
                {/* Phase 6: Promotion failed — dedicated banner with clear messaging */}
                {(displayAsset?.analysis_status ?? '') === 'promotion_failed' && (
                    <div className="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-md">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <ExclamationTriangleIcon className="h-5 w-5 text-amber-400" />
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-medium text-amber-800">
                                    Asset promotion failed
                                </p>
                                <p className="mt-1 text-sm text-amber-700">
                                    Thumbnails may not be publicly available.
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        disabled={retryProcessingLoading}
                                        onClick={async () => {
                                            setRetryProcessingLoading(true)
                                            try {
                                                await window.axios.post(`/app/assets/${displayAsset.id}/retry-processing`)
                                                setAssetIncidents([])
                                                if (onAssetUpdate) onAssetUpdate()
                                                router.reload({ only: ['assets'] })
                                            } catch (e) {
                                                setToastMessage('Failed to retry promotion.')
                                                setToastType('error')
                                                setTimeout(() => setToastMessage(null), 5000)
                                            } finally {
                                                setRetryProcessingLoading(false)
                                            }
                                        }}
                                        className="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                                    >
                                        <ArrowPathIcon className="h-3.5 w-3.5 mr-1" />
                                        {retryProcessingLoading ? 'Retrying…' : 'Retry Promotion'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={submitTicketLoading}
                                        onClick={async () => {
                                            setSubmitTicketLoading(true)
                                            try {
                                                const res = await window.axios.post(
                                                    `/app/assets/${displayAsset.id}/submit-ticket`,
                                                    {},
                                                    { headers: { Accept: 'application/json' } }
                                                )
                                                const ticket = res.data?.ticket ?? null
                                                const tenantTicket = res.data?.tenant_ticket ?? null
                                                if (ticket?.id) {
                                                    setAssetIncidents([])
                                                    if (onAssetUpdate) onAssetUpdate()
                                                    router.reload({ only: ['assets'] })
                                                }
                                                setToastMessage(tenantTicket?.url
                                                    ? 'Support ticket created.'
                                                    : 'Support ticket submitted. Our team will review the processing issue.')
                                                setToastType('success')
                                                setToastTicketUrl(tenantTicket?.url ?? null)
                                                setTimeout(() => { setToastMessage(null); setToastTicketUrl(null) }, 6000)
                                            } catch (e) {
                                                setToastMessage('Failed to submit support ticket.')
                                                setToastType('error')
                                                setToastTicketUrl(null)
                                                setTimeout(() => setToastMessage(null), 5000)
                                            } finally {
                                                setSubmitTicketLoading(false)
                                            }
                                        }}
                                        className="inline-flex items-center rounded-md border border-amber-600 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                                    >
                                        <TicketIcon className="h-3.5 w-3.5 mr-1" />
                                        {submitTicketLoading ? 'Submitting…' : 'Submit Support Ticket'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
                {/* Unified Operations: Processing issue banner when unresolved incident exists (exclude promotion_failed — has dedicated banner above) */}
                {assetIncidents?.length > 0 && (displayAsset?.analysis_status ?? '') !== 'promotion_failed' && (
                    <div className="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-md">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <ExclamationTriangleIcon className="h-5 w-5 text-amber-400" />
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-medium text-amber-800">
                                    Processing Issue Detected
                                </p>
                                <p className="mt-1 text-sm text-amber-700">
                                    {assetIncidents[0]?.title || 'Processing issue'}
                                </p>
                                <p className="mt-1 text-xs text-amber-600">
                                    System retry attempted. Support recommended.
                                </p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {(assetIncidents || []).filter(Boolean).some(i => i?.retryable) && (
                                        <button
                                            type="button"
                                            disabled={retryProcessingLoading}
                                            onClick={async () => {
                                                setRetryProcessingLoading(true)
                                                try {
                                                    await window.axios.post(`/app/assets/${displayAsset.id}/retry-processing`)
                                                    setAssetIncidents([])
                                                    if (onAssetUpdate) onAssetUpdate()
                                                    router.reload({ only: ['assets'] })
                                                } catch (e) {
                                                    // Ignore
                                                } finally {
                                                    setRetryProcessingLoading(false)
                                                }
                                            }}
                                            className="inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                                        >
                                            <ArrowPathIcon className="h-3.5 w-3.5 mr-1" />
                                            {retryProcessingLoading ? 'Retrying…' : 'Retry Processing'}
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        disabled={submitTicketLoading}
                                        onClick={async () => {
                                            setSubmitTicketLoading(true)
                                            try {
                                                const res = await window.axios.post(
                                                    `/app/assets/${displayAsset.id}/submit-ticket`,
                                                    {},
                                                    { headers: { Accept: 'application/json' } }
                                                )
                                                const ticket = res.data?.ticket ?? null
                                                const tenantTicket = res.data?.tenant_ticket ?? null
                                                if (ticket?.id) {
                                                    setAssetIncidents([])
                                                    if (onAssetUpdate) onAssetUpdate()
                                                    router.reload({ only: ['assets'] })
                                                }
                                                setToastMessage(tenantTicket?.url
                                                    ? 'Support ticket created.'
                                                    : 'Support ticket submitted. Our team will review the processing issue.')
                                                setToastType('success')
                                                setToastTicketUrl(tenantTicket?.url ?? null)
                                                setTimeout(() => { setToastMessage(null); setToastTicketUrl(null) }, 6000)
                                            } catch (e) {
                                                setToastMessage('Failed to submit support ticket.')
                                                setToastType('error')
                                                setToastTicketUrl(null)
                                                setTimeout(() => setToastMessage(null), 5000)
                                            } finally {
                                                setSubmitTicketLoading(false)
                                            }
                                        }}
                                        className="inline-flex items-center rounded-md border border-amber-600 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                                    >
                                        <TicketIcon className="h-3.5 w-3.5 mr-1" />
                                        {submitTicketLoading ? 'Submitting…' : 'Submit Support Ticket'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Phase J.3: Status Banners for Contributors */}
                {auth?.approval_features?.approvals_enabled && displayAsset?.approval_status && (
                    <>
                        {/* Pending Status Banner */}
                        {displayAsset.approval_status === 'pending' && (
                            <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                                <div className="flex">
                                    <div className="flex-shrink-0">
                                        <ClockIcon className="h-5 w-5 text-yellow-400" />
                                    </div>
                                    <div className="ml-3">
                                        <p className="text-sm font-medium text-yellow-800">
                                            This asset is awaiting review
                                        </p>
                                        <p className="mt-1 text-sm text-yellow-700">
                                            Your asset has been submitted and is waiting for approval from an admin or brand manager.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                        
                        {/* Rejected Status Banner */}
                        {displayAsset.approval_status === 'rejected' && (
                            <div className="bg-red-50 border-l-4 border-red-400 p-4 rounded-md">
                                <div className="flex">
                                    <div className="flex-shrink-0">
                                        <XCircleIcon className="h-5 w-5 text-red-400" />
                                    </div>
                                    <div className="ml-3 flex-1">
                                        <p className="text-sm font-medium text-red-800">
                                            This asset was rejected
                                        </p>
                                        {displayAsset.rejection_reason && (
                                            <p className="mt-1 text-sm text-red-700">
                                                {displayAsset.rejection_reason}
                                            </p>
                                        )}
                                        {displayAsset.rejected_at && (
                                            <p className="mt-1 text-xs text-red-600">
                                                Rejected {new Date(displayAsset.rejected_at).toLocaleDateString('en-US', {
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
                    </>
                )}
                
                {/* Large Preview */}
                <div className="space-y-3">                    
                    
                    {/* Phase 3.0C: Thumbnail preview with state machine and fade-in */}
                    <div className="bg-gray-50 rounded-lg overflow-hidden border border-gray-200 relative" style={{ aspectRatio: '16/9', minHeight: '240px' }}>
                        <div 
                            className={`relative w-full h-full transition-opacity duration-200 ${isLayoutSettling ? 'opacity-0' : 'opacity-100'}`}
                        >
                            {isVideo && displayAsset.id ? (
                                // Phase V-1: Video thumbnail with hover preview (same as other assets)
                                // Show thumbnail (icon > medium thumbnail) with hover video auto-play
                                <div
                                    className="w-full h-full cursor-pointer group relative"
                                    onClick={() => {
                                        // Open gallery view (zoom modal) for videos
                                        setShowZoomModal(true)
                                    }}
                                    onMouseEnter={() => !isMobile && setIsHoveringVideo(true)}
                                    onMouseLeave={() => {
                                        setIsHoveringVideo(false)
                                        // Pause and reset video on mouse leave
                                        if (videoPreviewRef.current) {
                                            videoPreviewRef.current.pause()
                                            videoPreviewRef.current.currentTime = 0
                                        }
                                        setVideoPreviewLoaded(false)
                                    }}
                                >
                                    {/* Hover video preview (auto-play loop, no controls, no audio) */}
                                    {isHoveringVideo && displayAsset.video_preview_url && !isMobile && (
<video
                                            ref={videoPreviewRef}
                                            src={displayAsset.video_preview_url}
                                            className="absolute inset-0 w-full h-full object-contain z-10 bg-gray-50"
                                            autoPlay
                                            muted
                                            loop
                                            playsInline
                                            onLoadedData={() => setVideoPreviewLoaded(true)}
                                            style={{ opacity: videoPreviewLoaded ? 1 : 0, transition: 'opacity 0.2s' }}
                                        />
                                    )}
                                    
                                    {/* Thumbnail preview (same as other assets) */}
                                    <ThumbnailPreview
                                        asset={displayAsset}
                                        alt={displayAsset.title || displayAsset.original_filename || 'Video preview'}
                                        className={`w-full h-full ${isHoveringVideo && displayAsset.video_preview_url && !isMobile && videoPreviewLoaded ? 'opacity-0' : 'opacity-100'} transition-opacity duration-200`}
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
                                    
                                    {/* Zoom overlay (only shown when hovering) */}
                                    <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100 pointer-events-none z-20">
                                        <span className="text-white text-sm font-medium">Click to play</span>
                                    </div>
                                </div>
                            ) : isPdf && displayAsset.id ? (
                                <div
                                    className={`relative w-full h-full bg-white ${pdfPageCache[pdfCurrentPage] || pdfPageCache[1] ? 'cursor-pointer group' : ''}`}
                                    onClick={() => {
                                        if (pdfPageCache[pdfCurrentPage] || pdfPageCache[1]) {
                                            setShowZoomModal(true)
                                        }
                                    }}
                                    role={pdfPageCache[pdfCurrentPage] || pdfPageCache[1] ? 'button' : undefined}
                                    tabIndex={pdfPageCache[pdfCurrentPage] || pdfPageCache[1] ? 0 : undefined}
                                    onKeyDown={(e) => {
                                        if ((pdfPageCache[pdfCurrentPage] || pdfPageCache[1]) && (e.key === 'Enter' || e.key === ' ')) {
                                            e.preventDefault()
                                            setShowZoomModal(true)
                                        }
                                    }}
                                >
                                    {pdfPageCache[pdfCurrentPage] ? (
                                        <img
                                            src={pdfPageCache[pdfCurrentPage]}
                                            alt={`PDF page ${pdfCurrentPage}`}
                                            className="w-full h-full object-contain"
                                            onError={() => {
                                                setPdfPageCache(prev => {
                                                    const next = { ...prev }
                                                    delete next[pdfCurrentPage]
                                                    return next
                                                })
                                                setPdfPageLoading(true)
                                                setPdfPageError(null)
                                                fetchPdfPage(pdfCurrentPage)
                                            }}
                                        />
                                    ) : (
                                        <div className="w-full h-full flex items-center justify-center">
                                            <div className="text-center px-4">
                                                {pdfPageLoading ? (
                                                    <>
                                                        <ArrowPathIcon className="h-6 w-6 mx-auto text-gray-400 animate-spin" />
                                                        <p className="mt-2 text-sm text-gray-500">Rendering page {pdfCurrentPage}...</p>
                                                    </>
                                                ) : (
                                                    <p className="text-sm text-gray-500">Preparing PDF preview...</p>
                                                )}
                                                {pdfPageError && (
                                                    <p className="mt-2 text-xs text-amber-600">{pdfPageError}</p>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                    {(pdfPageCache[pdfCurrentPage] || pdfPageCache[1]) && (
                                        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center opacity-0 group-hover:opacity-100 pointer-events-none z-20">
                                            <span className="text-white text-sm font-medium">Click to zoom</span>
                                        </div>
                                    )}
                                </div>
                            ) : hasThumbnailSupport && displayAsset.id ? (
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
                                        preferLargeForVector
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
                                    preferLargeForVector
                                />
                            )}
                        </div>
                    </div>

                    {isPdf && (
                        <div className="space-y-2 rounded-md border border-gray-200 bg-white px-3 py-2">
                            <div className="flex items-center justify-between">
                                <button
                                    type="button"
                                    onClick={() => handlePdfPageNavigate(pdfCurrentPage - 1)}
                                    disabled={pdfCurrentPage <= 1 || pdfPageLoading}
                                    className="inline-flex items-center rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 hover:bg-gray-50"
                                >
                                    Previous
                                </button>
                                <div className="text-xs text-gray-600">
                                    Page {pdfCurrentPage} of {effectivePdfPageCount}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => handlePdfPageNavigate(pdfCurrentPage + 1)}
                                    disabled={pdfCurrentPage >= effectivePdfPageCount || pdfPageLoading}
                                    className="inline-flex items-center rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 disabled:cursor-not-allowed disabled:opacity-50 hover:bg-gray-50"
                                >
                                    Next
                                </button>
                            </div>
                            {canRequestFullPdfExtraction && effectivePdfPageCount > 1 && (
                                <div className="flex items-center justify-between gap-2 border-t border-gray-100 pt-2">
                                    <p className="text-xs text-gray-500">
                                        Render all pages for AI ingestion and faster navigation.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={handleRequestFullPdfExtraction}
                                        disabled={pdfFullExtractionLoading || pdfFullExtractionRequested}
                                        className="inline-flex shrink-0 items-center rounded border border-indigo-300 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {pdfFullExtractionLoading
                                            ? 'Queueing...'
                                            : pdfFullExtractionRequested
                                                ? 'Queued'
                                                : 'Render all pages'}
                                    </button>
                                </div>
                            )}
                            {canRequestFullPdfExtraction && (
                                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-2">
                                    <p className="text-xs text-gray-500">
                                        Extract text from PDF for search and AI (pdftotext).
                                    </p>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {pdfTextExtraction?.status === 'complete' && (
                                            <button
                                                type="button"
                                                onClick={() => setShowPdfTextModal(true)}
                                                className="inline-flex items-center rounded border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                            >
                                                View
                                            </button>
                                        )}
                                        {pdfTextExtraction?.status && (
                                            <span className={[
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                                pdfTextExtraction.status === 'complete' && 'bg-green-100 text-green-700',
                                                pdfTextExtraction.status === 'failed' && 'bg-red-100 text-red-700',
                                                pdfTextExtraction.status === 'processing' && 'bg-amber-100 text-amber-700',
                                                pdfTextExtraction.status === 'pending' && 'bg-gray-100 text-gray-600',
                                            ].filter(Boolean).join(' ') || 'bg-gray-100 text-gray-600'}>
                                                {pdfTextExtraction.status === 'complete' && 'Complete'}
                                                {pdfTextExtraction.status === 'failed' && 'Failed'}
                                                {pdfTextExtraction.status === 'processing' && 'Processing'}
                                                {pdfTextExtraction.status === 'pending' && 'Pending'}
                                            </span>
                                        )}
                                        <button
                                            type="button"
                                            onClick={handleTriggerPdfOcr}
                                            disabled={pdfOcrTriggerLoading || pdfTextExtractionLoading}
                                            className="inline-flex shrink-0 items-center rounded border border-emerald-300 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {pdfOcrTriggerLoading ? 'Starting...' : pdfTextExtraction ? 'Re-Extract Text' : 'Extract Text (OCR)'}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                    
                    {/* Lifecycle badges - Unpublished, Archived, and Expired */}
                    <div className="flex flex-wrap gap-2">
                        {/* Unpublished badge */}
                        {/* CANONICAL RULE: Published vs Unpublished is determined ONLY by is_published */}
                        {/* Use is_published boolean from API - do not infer from approval, lifecycle enums, or fallbacks */}
                        {!displayAsset.archived_at && displayAsset.is_published === false && (
                            <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-300">
                                Unpublished
                            </span>
                        )}
                        {/* Archived badge */}
                        {displayAsset.archived_at && (
                            <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">
                                Archived
                            </span>
                        )}
                        {/* Phase M: Expired badge - show only when expired */}
                        {displayAsset.expires_at && new Date(displayAsset.expires_at) < new Date() && (
                            <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-red-100 text-red-700 border border-red-300">
                                Expired
                            </span>
                        )}
                        {/* Phase AF-1: Approval badges */}
                        {/* CRITICAL: Approval badges are SEPARATE from publication badges */}
                        {/* Publication = visibility (published_at) */}
                        {/* Approval = governance (approval_status, approved_at) */}
                        {/* These are independent states - do NOT conflate them */}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'pending' && (
                            <>
                                <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 border border-yellow-300">
                                    Pending Approval
                                </span>
                                {/* Phase AF-4: Aging label */}
                                {displayAsset.aging_label && (
                                    <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">
                                        {displayAsset.aging_label}
                                    </span>
                                )}
                            </>
                        )}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {/* Phase J.3: Show rejected badge for contributors too */}
                        {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'rejected' && (
                            <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-red-100 text-red-700 border border-red-300">
                                Rejected
                            </span>
                        )}
                    </div>
                </div>

                {/* Analytics/Metrics & Action Buttons */}
                <div className="border-t border-gray-200 pt-6 space-y-4">
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
                    
                    {/* Action Buttons */}
                    {displayAsset?.id && (
                        <div className="space-y-2">
                            {/* Quick Review/Approve/Reject button - show FIRST for approvers viewing pending assets */}
                            {(() => {
                                // Brand-based permission check (primary check)
                                // IMPORTANT: Roles are at auth.brand_role and auth.tenant_role, NOT auth.user.brand_role
                                // Match backend logic: RoleRegistry::isBrandApproverRole() checks for 'admin' or 'brand_manager' (case-insensitive)
                                const brandRole = auth?.brand_role?.toLowerCase()
                                const tenantRole = auth?.tenant_role?.toLowerCase()
                                
                                // Check if user can approve based on brand role (brand_manager or admin)
                                // Brand roles that can approve: 'brand_manager', 'admin' (brand admin)
                                // This matches RoleRegistry::brandApproverRoles() which returns ['admin', 'brand_manager']
                                const isBrandApprover = brandRole === 'brand_manager' || brandRole === 'admin'
                                
                                // Tenant owners/admins can also approve as they have access to all brands
                                // This matches backend logic in AssetApprovalController
                                const isTenantOwnerOrAdmin = tenantRole === 'owner' || tenantRole === 'admin'
                                
                                const canApprove = isBrandApprover || isTenantOwnerOrAdmin
                                
                                // Check approval status (case-insensitive, also check for 'PENDING' uppercase)
                                const approvalStatus = displayAsset.approval_status?.toLowerCase()
                                const isPending = approvalStatus === 'pending' || displayAsset.approval_status === 'PENDING'
                                
                                // Check if approvals are enabled
                                const approvalsEnabled = auth?.approval_features?.approvals_enabled
                                
                                return approvalsEnabled && isPending && canApprove
                            })() && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setShowReviewModal(true)
                                    }}
                                    className="w-full inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <CheckCircleIcon className="h-5 w-5 mr-2" />
                                    Review & Approve
                                </button>
                            )}
                            
                            {/* Publish button - show if unpublished and not archived */}
                            {/* Phase J.3.1: Contributors cannot publish assets when approval is enabled */}
                            {(() => {
                                // Check if approvals are enabled
                                const approvalsEnabled = auth?.approval_features?.approvals_enabled;
                                
                                // Check if asset is pending approval
                                const isPendingApproval = approvalsEnabled && 
                                                         displayAsset.approval_status === 'pending';
                                
                                // Check if asset is rejected
                                const isRejected = approvalsEnabled && 
                                                   displayAsset.approval_status === 'rejected';
                                
                                // Check if user is an approver (owner, admin, or brand_manager)
                                const isApprover = 
                                    auth?.user?.tenant_role === 'owner' || 
                                    auth?.user?.tenant_role === 'admin' || 
                                    auth?.user?.brand_role === 'admin' || 
                                    auth?.user?.brand_role === 'brand_manager';
                                
                                // Check if user is a contributor
                                const isContributor = auth?.user?.brand_role === 'contributor' && 
                                                      !['owner', 'admin'].includes(auth?.user?.tenant_role?.toLowerCase() || '');
                                
                                // Contributors cannot publish when approval is enabled (regardless of status)
                                // This matches the same permission check used for upload approval
                                const contributorBlocked = isContributor && approvalsEnabled;
                                
                                // Show publish button if:
                                // 1. User has publish permission AND
                                // 2. Asset is not published AND
                                // 3. Asset is not archived AND
                                // 4. Contributors are blocked when approval is enabled
                                // 5. If asset is pending approval or rejected, only approvers can publish
                                const canShowPublishButton = canPublish && 
                                                             displayAsset.is_published === false && 
                                                             !displayAsset.archived_at &&
                                                             !contributorBlocked &&
                                                             (!isPendingApproval || isApprover) &&
                                                             (!isRejected || isApprover);
                                
                                return canShowPublishButton;
                            })() && (
                                <button
                                    type="button"
                                    onClick={async () => {
                                        try {
                                            // Use axios directly since the endpoint returns JSON, not Inertia response
                                            const response = await window.axios.post(`/app/assets/${displayAsset.id}/publish`)
                                            
                                            if (response.data && response.data.message) {
                                                // Format success message with timestamp and user
                                                const publishedAt = response.data.published_at 
                                                    ? new Date(response.data.published_at).toLocaleString('en-US', {
                                                        month: 'short',
                                                        day: 'numeric',
                                                        year: 'numeric',
                                                        hour: 'numeric',
                                                        minute: '2-digit',
                                                        hour12: true
                                                    })
                                                    : 'now'
                                                
                                                const userName = auth?.user?.name || auth?.user?.email || 'You'
                                                
                                                setToastMessage(`Approved at: ${publishedAt} by: ${userName}`)
                                                setToastType('success')
                                                
                                                // Auto-hide toast after 8 seconds (longer to account for reload)
                                                setTimeout(() => {
                                                    setToastMessage(null)
                                                }, 8000)
                                                
                                                // Update local asset state instead of full reload
                                                // This preserves drawer state and grid scroll position
                                                if (onAssetUpdate && response.data.asset) {
                                                    onAssetUpdate(response.data.asset)
                                                } else {
                                                    // Fallback: reload only assets if callback not provided
                                                    router.reload({ 
                                                        only: ['assets'], 
                                                        preserveState: true, 
                                                        preserveScroll: true 
                                                    })
                                                }
                                            }
                                        } catch (err) {
                                            console.error('Failed to approve asset:', err)
                                            
                                            // Extract error message from response
                                            let errorMessage = 'You do not have permission to publish this asset.'
                                            
                                            if (err.response) {
                                                if (err.response.status === 403) {
                                                    errorMessage = err.response.data?.message || 
                                                                  'You do not have permission to publish this asset. Please check that you have the "asset.publish" permission and are assigned to this brand.'
                                                } else if (err.response.status === 404) {
                                                    errorMessage = 'Asset not found.'
                                                } else {
                                                    errorMessage = err.response.data?.message || 
                                                                  err.response.data?.error || 
                                                                  `Failed to publish asset (${err.response.status}).`
                                                }
                                            } else if (err.message) {
                                                errorMessage = err.message
                                            }
                                            
                                            setToastMessage(errorMessage)
                                            setToastType('error')
                                            
                                            // Auto-hide error toast after 8 seconds
                                            setTimeout(() => {
                                                setToastMessage(null)
                                            }, 8000)
                                        }
                                    }}
                                    className="w-full inline-flex items-center justify-center rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
                                >
                                    <CheckCircleIcon className="h-4 w-4 mr-2" />
                                    Publish
                                </button>
                            )}
                            
                            {/* Phase J.3: Resubmit button - show if asset is rejected and user is uploader or admin */}
                            {/* Phase AF-5: Only show if approvals are enabled */}
                            {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'rejected' && (
                                (displayAsset.uploaded_by?.id === auth?.user?.id) || 
                                (auth?.user?.brand_role === 'admin') || 
                                (auth?.user?.tenant_role === 'admin' || auth?.user?.tenant_role === 'owner')
                            ) && (
                                <button
                                    type="button"
                                    onClick={() => setShowResubmitModal(true)}
                                    className="w-full inline-flex items-center justify-center rounded-md bg-yellow-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2"
                                >
                                    <ArrowUturnLeftIcon className="h-4 w-4 mr-2" />
                                    Resubmit Asset
                                </button>
                            )}
                            
                            {/* Phase J.3.1 / Phase 6.5: Replace File only when Starter. Pro/Enterprise use Upload New Version in Versions section. */}
                            {auth?.approval_features?.approvals_enabled && 
                             displayAsset.approval_status === 'rejected' &&
                             displayAsset.uploaded_by?.id === auth?.user?.id &&
                             auth?.user?.brand_role === 'contributor' &&
                             !['admin', 'owner'].includes(auth?.user?.tenant_role?.toLowerCase() || '') &&
                             !(auth?.plan_allows_versions ?? false) && (
                                <button
                                    type="button"
                                    onClick={() => setShowReplaceFileModal(true)}
                                    className="w-full inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <CloudArrowUpIcon className="h-4 w-4 mr-2" />
                                    Replace File
                                </button>
                            )}
                            
                            {/* UX-R2: View + Add to download on one line; Download full width below; policy message under Download. */}
                            {(() => {
                                const isEligibleForDownload = displayAsset && displayAsset.is_published !== false && !displayAsset.archived_at
                                const singleAssetDisabledByPolicy = !!policyDisableSingleAsset
                                const canSingleAssetDownload = isEligibleForDownload && !singleAssetDisabledByPolicy
                                const isInBucket = selection ? selection.isSelected(displayAsset?.id) : (bucketAssetIds && bucketAssetIds.includes(displayAsset?.id))
                                const showAddToDownload = selection != null
                                return (
                                    <div className="space-y-2">
                                        {/* Row 1: View (Details) + Add to download */}
                                        <div className={`grid gap-2 ${showAddToDownload ? 'grid-cols-2' : 'grid-cols-1'}`}>
                                            <button
                                                type="button"
                                                onClick={() => setShowDetailsModal(true)}
                                                className="inline-flex items-center justify-center rounded-md bg-gray-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                                            >
                                                <EyeIcon className="h-4 w-4 mr-2" />
                                                Details
                                            </button>
                                            {showAddToDownload && (
                                                <button
                                                    type="button"
                                                    disabled={!isEligibleForDownload}
                                                    onClick={() => {
                                                        if (selection) {
                                                            selection.toggleItem({
                                                                id: displayAsset.id,
                                                                type: selectionAssetType,
                                                                name: displayAsset.title ?? displayAsset.original_filename ?? '',
                                                                thumbnail_url: displayAsset.final_thumbnail_url ?? displayAsset.thumbnail_url ?? displayAsset.preview_thumbnail_url ?? null,
                                                                category_id: displayAsset.metadata?.category_id ?? displayAsset.category_id ?? null,
                                                            })
                                                        }
                                                    }}
                                                    className={`inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium border focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                                        isInBucket
                                                            ? 'text-white'
                                                            : isEligibleForDownload
                                                                ? 'bg-white shadow-sm hover:opacity-90'
                                                                : 'bg-gray-100 text-gray-400 cursor-not-allowed border-gray-300'
                                                    }`}
                                                    style={isEligibleForDownload ? {
                                                        borderColor: isInBucket ? brandPrimary : brandPrimary,
                                                        color: isInBucket ? '#fff' : brandPrimary,
                                                        backgroundColor: isInBucket ? brandPrimary : undefined,
                                                        ['--tw-ring-color']: brandPrimary,
                                                    } : {}}
                                                    title={!isEligibleForDownload ? 'Publish this asset to add to download' : isInBucket ? 'Remove from download' : 'Add to download'}
                                                >
                                                    {isInBucket ? (
                                                        <>
                                                            <CheckIcon className="h-4 w-4 mr-2" />
                                                            In download
                                                        </>
                                                    ) : (
                                                        <>
                                                            <RectangleStackIcon className="h-4 w-4 mr-2" />
                                                            Add to download
                                                        </>
                                                    )}
                                                </button>
                                            )}
                                        </div>
                                        {/* Row 2: Download full width */}
                                        <button
                                            type="button"
                                            disabled={!canSingleAssetDownload}
                                            onClick={async () => {
                                                if (!canSingleAssetDownload || !displayAsset?.id) return
                                                const url = typeof route !== 'undefined' ? route('assets.download.single', { asset: displayAsset.id }) : `/app/assets/${displayAsset.id}/download`
                                                const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                                                setToastMessage('Preparing download…')
                                                setToastType('success')
                                                try {
                                                    const res = await fetch(url, {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type': 'application/json',
                                                            'Accept': 'application/json',
                                                            'X-Requested-With': 'XMLHttpRequest',
                                                            'X-CSRF-TOKEN': csrf || '',
                                                        },
                                                        credentials: 'same-origin',
                                                    })
                                                    const data = await res.json().catch(() => ({}))
                                                    if (!res.ok) {
                                                        setToastMessage(data?.message || 'Download failed')
                                                        setToastType('error')
                                                        setTimeout(() => setToastMessage(null), 4000)
                                                        return
                                                    }
                                                    const fileUrl = data?.file_url || data?.public_url || data?.download_url
                                                    if (fileUrl) {
                                                        window.location.href = fileUrl
                                                    } else {
                                                        setToastMessage('Download started')
                                                    }
                                                } catch (e) {
                                                    setToastMessage('Download failed')
                                                    setToastType('error')
                                                }
                                                setTimeout(() => setToastMessage(null), 3000)
                                            }}
                                            className={`w-full inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                                canSingleAssetDownload
                                                    ? 'hover:opacity-90'
                                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            }`}
                                            style={canSingleAssetDownload ? { backgroundColor: brandPrimary, ['--tw-ring-color']: brandPrimary } : {}}
                                            title={!isEligibleForDownload ? 'Publish this asset to download' : singleAssetDisabledByPolicy ? 'Your organization requires downloads to be packaged.' : 'Download this asset (tracked)'}
                                        >
                                            <ArrowDownTrayIcon className="h-4 w-4 mr-2" />
                                            Download
                                        </button>
                                        {singleAssetDisabledByPolicy && (
                                            <p className="text-xs text-slate-500">Your organization&apos;s policy does not permit downloading individual assets. Use &quot;Add to download&quot; to create a packaged download.</p>
                                        )}
                                    </div>
                                )
                            })()}
                        </div>
                    )}
                </div>

                {/* Phase B9: Metadata Candidate Review (moved up from bottom) */}
                {displayAsset?.id && (
                    <MetadataCandidateReview assetId={displayAsset.id} primaryColor={brandPrimary} />
                )}

                {/* Remove standalone Tag Management - will be moved into metadata section */}

                {/* AI Tag Suggestions */}
                {displayAsset?.id && (
                    <AiTagSuggestionsInline key={`ai-tags-${displayAsset.id}`} assetId={displayAsset.id} primaryColor={brandPrimary} />
                )}

                {/* Tags and Metadata */}
                {displayAsset?.id && (
                    <div className="border-t border-gray-200">
                        <CollapsibleSection 
                            title="Metadata"
                            defaultExpanded={true}
                        >
                            {/* Step 2: Pending Metadata Section - Moved above standard metadata list */}
                            {/* Phase M-2: Only show pending metadata if metadata approval is enabled for company + brand */}
                            {auth?.metadata_approval_features?.metadata_approval_enabled && 
                             displayAsset?.id && 
                             pendingMetadataCount > 0 && 
                             canApproveMetadata && (
                                <div className="mb-4 pb-4 border-b border-gray-200">
                                    <PendingMetadataList assetId={displayAsset.id} />
                                </div>
                            )}
                            
                            {/* Step 3: Contributor Pending Feedback (Read-only) */}
                            {/* Show notice for contributors (users without approval permission) */}
                            {auth?.metadata_approval_features?.metadata_approval_enabled && 
                             pendingMetadataCount > 0 && 
                             !canApproveMetadata && (
                                <div className="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-md">
                                    <p className="text-sm text-amber-800">
                                        Metadata submitted for approval
                                    </p>
                                </div>
                            )}
                            
                            {/* Category as first line */}
                            {categoryName && categoryName !== 'Uncategorized' && (
                                <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-1 md:gap-4 md:flex-nowrap mb-2 md:mb-3">
                                    <dt className="text-sm text-gray-500 mb-1 md:mb-0 md:w-32 md:flex-shrink-0 flex items-center md:items-start">
                                        <span className="flex items-center flex-wrap gap-1 md:gap-1.5">
                                            Category
                                        </span>
                                    </dt>
                                    <dd className="text-sm font-semibold text-gray-900 md:flex-1 md:min-w-0 break-words">
                                        {categoryName}
                                    </dd>
                                </div>
                            )}
                            
                            <AssetMetadataDisplay 
                                assetId={displayAsset.id} 
                                onPendingCountChange={setPendingMetadataCount}
                                primaryColor={brandPrimary}
                                suppressAnalysisRunningBanner={assetIncidents?.length > 0 || (displayAsset?.analysis_status ?? '') === 'promotion_failed'}
                                collectionDisplay={{
                                    collections: assetCollections,
                                    loading: assetCollectionsLoading,
                                    onEdit: () => setShowCollectionsModal(true),
                                    showEditButton: collectionFieldVisible,
                                }}
                            />
                            
                            {/* C9.1: Old inline Collections section removed - now integrated into AssetMetadataDisplay after Scene Classification */}
                            
                            {/* Tags at bottom of metadata */}
                            <div className="mt-4 pt-4 border-t border-gray-100">
                                <AssetTagManager 
                                    key={`tag-manager-${asset.id}`} 
                                    asset={displayAsset}
                                    showTitle={true}
                                    showInput={true}
                                    compact={true}
                                    inline={true}
                                    primaryColor={brandPrimary}
                                />
                            </div>
                        </CollapsibleSection>
                    </div>
                )}

                {/* C9.1: Old Collections section removed - now inline in Metadata section above */}

                {/* C9.2: Collections Edit Modal (inline in Metadata section, only if field is visible) */}
                {showCollectionsModal && collectionFieldVisible && (
                    <div className="fixed inset-0 z-[60] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                        <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-[60]" onClick={() => setShowCollectionsModal(false)}></div>
                            <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                            <div className="relative inline-block align-bottom bg-white rounded-lg text-left shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full z-[61]">
                                <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                                    <div className="sm:flex sm:items-start">
                                        <div className="mt-3 text-center sm:mt-0 sm:text-left w-full">
                                            <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4" id="modal-title">
                                                Edit Collections
                                            </h3>
                                            {dropdownCollectionsLoading ? (
                                                <p className="text-sm text-gray-500">Loading collections…</p>
                                            ) : (
                                                <div className="relative">
                                                    <CollectionSelector
                                                        collections={dropdownCollections}
                                                        selectedIds={(assetCollections || []).filter(Boolean).map((c) => c?.id).filter(Boolean)}
                                                        maxHeight="320px"
                                                        onChange={async (newCollectionIds) => {
                                                            if (!asset?.id || addToCollectionLoading) return
                                                            setAddToCollectionLoading(true)
                                                            try {
                                                                // C9.1: Use sync endpoint for full state update
                                                                await window.axios.put(
                                                                    `/app/assets/${asset.id}/collections`,
                                                                    { collection_ids: newCollectionIds },
                                                                    { headers: { Accept: 'application/json' } }
                                                                )
                                                                // Refresh collections from backend to reflect truth
                                                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                                                                // Notify parent if callback provided
                                                                const added = newCollectionIds.filter((id) => !(assetCollections || []).filter(Boolean).some((c) => c?.id === id))
                                                                const removed = (assetCollections || []).filter(Boolean).filter((c) => !newCollectionIds.includes(c?.id)).map((c) => c?.id).filter(Boolean)
                                                                if (collectionContext) {
                                                                    added.forEach((id) => collectionContext.onAssetAddedToCollection?.(asset.id, id))
                                                                    removed.forEach((id) => collectionContext.onAssetRemovedFromCollection?.(asset.id, id))
                                                                }
                                                                setToastMessage('Collections updated')
                                                                setToastType('success')
                                                                setTimeout(() => setToastMessage(null), 3000)
                                                            } catch (err) {
                                                                const errorMsg = err.response?.data?.message || err.response?.data?.errors?.collection_ids?.[0] || 'Failed to update collections'
                                                                setToastMessage(errorMsg)
                                                                setToastType('error')
                                                                // Refresh to restore backend truth on error
                                                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                                                            } finally {
                                                                setAddToCollectionLoading(false)
                                                            }
                                                        }}
                                                        disabled={addToCollectionLoading || dropdownCollectionsLoading}
                                                        placeholder="Select collections…"
                                                        showCreateButton={true} // C9.1: Always show create button in modal
                                                        onCreateClick={() => {
                                                            setShowCreateCollectionModal(true)
                                                        }}
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                    <button
                                        type="button"
                                        onClick={() => setShowCollectionsModal(false)}
                                        className="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm"
                                    >
                                        Done
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* C9.1: Create Collection Modal (always available for asset drawer, higher z-index than Edit Collections modal) */}
                <CreateCollectionModal
                    open={showCreateCollectionModal}
                    onClose={() => setShowCreateCollectionModal(false)}
                    onCreated={async (newCollection) => {
                        // C9.1: Add new collection to dropdown list
                        setDropdownCollections((prev) => {
                            // Avoid duplicates
                            if ((prev || []).filter(Boolean).some((c) => c?.id === newCollection?.id)) {
                                return prev
                            }
                            return [...prev, { id: newCollection.id, name: newCollection.name }]
                        })
                        
                        // C9.1: Auto-select the new collection and sync to asset
                        if (asset?.id) {
                            const newCollectionIds = [...(assetCollections || []).filter(Boolean).map((c) => c?.id).filter(Boolean), newCollection.id]
                            setAddToCollectionLoading(true)
                            try {
                                await window.axios.put(
                                    `/app/assets/${asset.id}/collections`,
                                    { collection_ids: newCollectionIds },
                                    { headers: { Accept: 'application/json' } }
                                )
                                // Refresh collections from backend
                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                                setToastMessage('Collection created and added to asset')
                                setToastType('success')
                                setTimeout(() => setToastMessage(null), 3000)
                            } catch (err) {
                                const errorMsg = err.response?.data?.message || err.response?.data?.errors?.collection_ids?.[0] || 'Failed to add to collection'
                                setToastMessage(errorMsg)
                                setToastType('error')
                                // Refresh to restore backend truth
                                const res = await window.axios.get(`/app/assets/${asset.id}/collections`, { headers: { Accept: 'application/json' } })
                                setAssetCollections((res.data?.collections ?? []).filter(Boolean))
                            } finally {
                                setAddToCollectionLoading(false)
                            }
                        }
                        
                        setShowCreateCollectionModal(false)
                    }}
                />

                {/* Phase AF-2: Approval History */}
                {/* Phase AF-5: Only show approval history if approvals are enabled */}
                {auth?.approval_features?.approvals_enabled && displayAsset?.id && (displayAsset.approval_status === 'pending' || displayAsset.approval_status === 'rejected' || displayAsset.approval_status === 'approved') && (
                    <div className="border-t border-gray-200">
                        <CollapsibleSection title="Approval History" defaultExpanded={false}>
                            {/* Phase AF-6: Approval Summary (AI-generated) */}
                            {auth?.approval_features?.approval_summaries_enabled && displayAsset?.approval_summary && (
                                <div className="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <div className="flex items-start">
                                        <div className="flex-shrink-0">
                                            <svg className="h-5 w-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <div className="ml-3 flex-1">
                                            <h4 className="text-sm font-medium text-blue-900 mb-1">Summary</h4>
                                            <p className="text-sm text-blue-800 whitespace-pre-wrap">{displayAsset.approval_summary}</p>
                                            {displayAsset.approval_summary_generated_at && (
                                                <p className="mt-2 text-xs text-blue-600">
                                                    Generated {new Date(displayAsset.approval_summary_generated_at).toLocaleDateString('en-US', {
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
                            <ApprovalHistory asset={displayAsset} brand={auth?.activeBrand} />
                        </CollapsibleSection>
                    </div>
                )}

                {/* Buttons moved up to analytics section */}

                {/* File Information */}
                <div className="border-t border-gray-200">
                    <CollapsibleSection title="File Information" defaultExpanded={false}>
                    
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
                                        {(displayAsset.uploaded_by.first_name?.[0] || displayAsset.uploaded_by.name?.[0] || displayAsset.uploaded_by.email?.[0] || '?').toUpperCase()}
                                    </span>
                                </div>
                            )}
                            <p className="text-sm text-gray-600">
                                Created by{' '}
                                <span className="font-medium text-gray-900">
                                    {(displayAsset.uploaded_by.name && displayAsset.uploaded_by.name.trim()) || 
                                     (displayAsset.uploaded_by.first_name && displayAsset.uploaded_by.last_name && `${displayAsset.uploaded_by.first_name} ${displayAsset.uploaded_by.last_name}`.trim()) ||
                                     displayAsset.uploaded_by.email || 
                                     'Unknown User'}
                                </span>
                            </p>
                        </div>
                    )}
                    
                    <dl className="space-y-3">
                        <div className="flex items-start gap-4">
                            <dt className="text-sm text-gray-500 w-32 flex-shrink-0">File Type</dt>
                            <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left uppercase">
                                {fileExtension || 'Unknown'}
                            </dd>
                        </div>
                        <div className="flex items-start gap-4">
                            <dt className="text-sm text-gray-500 w-32 flex-shrink-0">File Size</dt>
                            <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                {formatFileSize(displayAsset.size_bytes)}
                            </dd>
                        </div>
                        {/* Video-specific metadata */}
                        {isVideo && (
                            <>
                                {displayAsset.video_duration && (
                                    <div className="flex items-start gap-4">
                                        <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Duration</dt>
                                        <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                            {formatVideoDuration(displayAsset.video_duration)}
                                        </dd>
                                    </div>
                                )}
                                {displayAsset.video_width && displayAsset.video_height && (
                                    <div className="flex items-start gap-4">
                                        <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Resolution</dt>
                                        <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                            {displayAsset.video_width.toLocaleString()} × {displayAsset.video_height.toLocaleString()} px
                                        </dd>
                                    </div>
                                )}
                            </>
                        )}
                        <div className="flex items-start gap-4">
                            <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Status</dt>
                            <dd className="text-sm font-medium flex-1 min-w-0 text-left">
                                {(() => {
                                    const analysisStatus = displayAsset.analysis_status ?? 'uploading'
                                    const isComplete = analysisStatus === 'complete'
                                    const currentStep = getPipelineStageIndex(analysisStatus)
                                    const totalSteps = PIPELINE_STAGES.length
                                    return (
                                        <div className="flex flex-col gap-1">
                                            <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium w-fit ${
                                                isComplete ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'
                                            }`}>
                                                {getPipelineStageLabel(analysisStatus)}
                                            </span>
                                            {!isComplete && (
                                                <span className="text-[11px] text-gray-500">
                                                    Step {currentStep + 1} of {totalSteps}
                                                </span>
                                            )}
                                        </div>
                                    )
                                })()}
                            </dd>
                        </div>
                        {displayAsset.created_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Uploaded</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.created_at)}
                                </dd>
                            </div>
                        )}
                        <div className="flex items-start gap-4">
                            <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Category</dt>
                            <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                {categoryName}
                            </dd>
                        </div>
                        
                        {/* Phase L.4: Lifecycle Information (read-only) */}
                        {displayAsset.published_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Published</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.published_at)}
                                    {displayAsset.published_by && (
                                        <span className="ml-2 text-xs font-normal text-gray-500">
                                            by {displayAsset.published_by.name || `${displayAsset.published_by.first_name || ''} ${displayAsset.published_by.last_name || ''}`.trim() || 'Unknown'}
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                        {displayAsset.archived_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Archived</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.archived_at)}
                                    {displayAsset.archived_by && (
                                        <span className="ml-2 text-xs font-normal text-gray-500">
                                            by {displayAsset.archived_by.name || `${displayAsset.archived_by.first_name || ''} ${displayAsset.archived_by.last_name || ''}`.trim() || 'Unknown'}
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                        {/* Phase M: Expiration date display (read-only) */}
                        {displayAsset.expires_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">
                                    {new Date(displayAsset.expires_at) < new Date() ? 'Expired on' : 'Expires on'}
                                </dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.expires_at)}
                                </dd>
                            </div>
                        )}
                        {/* Phase AF-1: Approval information (read-only) */}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'approved' && displayAsset.approved_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Approved on</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.approved_at)}
                                    {displayAsset.approved_by && (
                                        <span className="ml-2 text-xs font-normal text-gray-500">
                                            by {displayAsset.approved_by.name || 'Unknown'}
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {/* Phase J.3: Show rejected info for contributors too */}
                        {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'rejected' && displayAsset.rejected_at && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Rejected on</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {formatDate(displayAsset.rejected_at)}
                                    {displayAsset.rejection_reason && (
                                        <span className="ml-2 text-xs font-normal text-gray-500">
                                            ({displayAsset.rejection_reason})
                                        </span>
                                    )}
                                </dd>
                            </div>
                        )}
                        {/* Phase AF-4: Pending aging information */}
                        {/* Phase AF-5: Only show if approvals are enabled */}
                        {auth?.approval_features?.approvals_enabled && displayAsset.approval_status === 'pending' && displayAsset.pending_since && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Awaiting approval for</dt>
                                <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {displayAsset.pending_days !== null && displayAsset.pending_days >= 0 ? (
                                        <>
                                            {displayAsset.pending_days} {displayAsset.pending_days === 1 ? 'day' : 'days'}
                                            {displayAsset.pending_days >= 7 && (
                                                <span className="ml-2 text-xs font-normal text-amber-600">
                                                    (7+ days)
                                                </span>
                                            )}
                                        </>
                                    ) : (
                                        'Less than 1 day'
                                    )}
                                </dd>
                            </div>
                        )}
                        {/* File Dimensions - if available from source */}
                        {(() => {
                            // Priority 1: Check source_dimensions (from original image file)
                            if (displayAsset.source_dimensions && displayAsset.source_dimensions.width && displayAsset.source_dimensions.height) {
                                return (
                                    <div className="flex items-start gap-4">
                                        <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Dimensions</dt>
                                        <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                            {displayAsset.source_dimensions.width.toLocaleString()} × {displayAsset.source_dimensions.height.toLocaleString()} px
                                        </dd>
                                    </div>
                                )
                            }
                            
                            // Priority 2: Try to get dimensions from metadata (from thumbnail generation)
                            if (displayAsset.metadata?.image_width && displayAsset.metadata?.image_height) {
                                return (
                                    <div className="flex items-start gap-4">
                                        <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Dimensions</dt>
                                        <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                            {parseInt(displayAsset.metadata.image_width).toLocaleString()} × {parseInt(displayAsset.metadata.image_height).toLocaleString()} px
                                        </dd>
                                    </div>
                                )
                            }
                            
                            // Priority 3: Try to get dimensions from metadata.fields (as merged by AssetController)
                            let dimensionsValue = null
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
                                        <div className="flex items-start gap-4">
                                            <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Dimensions</dt>
                                            <dd className="text-sm font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                                {parseInt(width).toLocaleString()} × {parseInt(height).toLocaleString()} px
                                            </dd>
                                        </div>
                                    )
                                }
                            }
                            return null
                        })()}
                        
                        {/* Filename — label column + wider left-aligned value column (matches Metadata) */}
                        {displayAsset.original_filename && (
                            <div className="flex items-start gap-4">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Filename</dt>
                                <dd className="text-sm font-mono font-semibold text-gray-900 flex-1 min-w-0 break-words text-left">
                                    {displayAsset.original_filename}
                                </dd>
                            </div>
                        )}
                        {/* Asset ID (UUID) — at bottom for copy/reference; admin link for site roles */}
                        {displayAsset.id && (
                            <div className="flex items-start gap-4 pt-2 mt-2 border-t border-gray-100">
                                <dt className="text-sm text-gray-500 w-32 flex-shrink-0">Asset ID</dt>
                                <dd className="text-sm font-mono text-gray-900 flex-1 min-w-0 break-all text-left" title={displayAsset.id}>
                                    {(() => {
                                        const siteRoles = Array.isArray(auth?.user?.site_roles) ? auth.user.site_roles : []
                                        const canViewAdminAssets = ['site_owner', 'site_admin', 'site_engineering', 'site_support'].some((r) => siteRoles.includes(r))
                                        if (canViewAdminAssets) {
                                            return (
                                                <a
                                                    href={`/app/admin/assets?asset_id=${encodeURIComponent(displayAsset.id)}`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-indigo-600 hover:text-indigo-800 hover:underline"
                                                >
                                                    {displayAsset.id}
                                                </a>
                                            )
                                        }
                                        return displayAsset.id
                                    })()}
                                </dd>
                            </div>
                        )}
                    </dl>

                {/* Processing State - Skipped or Pending (e.g. after Remove Preview) */}
                {/* Show Regenerate/Generate button when supported; otherwise informational message */}
                {(thumbnailsSkipped || (thumbnailStatus === 'pending' && canGenerateThumbnail)) && (
                    <div className="border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900 mb-2">Preview Status</h3>
                        
                        {/* Generate Preview Button - For PDFs that can now be processed */}
                        {canGenerateThumbnail ? (
                            <div className="bg-indigo-50 border border-indigo-200 rounded-md p-4">
                                <p className="text-sm font-medium text-indigo-900 mb-2">
                                    {(thumbnailStatus === 'pending' && (displayAsset?.analysis_status ?? '') === 'complete')
                                        ? 'Preview removed — ready to regenerate'
                                        : 'Preview not generated yet'}
                                </p>
                                <p className="text-xs text-indigo-700 mb-3">
                                    {(thumbnailStatus === 'pending' && (displayAsset?.analysis_status ?? '') === 'complete')
                                        ? 'Reprocess Asset runs the full pipeline (thumbnails, metadata, color analysis). Use when Regenerate Preview doesn&apos;t work.'
                                        : displayAsset.mime_type === 'application/pdf' || displayAsset.original_filename?.toLowerCase().endsWith('.pdf')
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
                                
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={handleReprocessAsset}
                                        disabled={reprocessLoading || generateLoading}
                                        className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {reprocessLoading ? (
                                            <>
                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                Reprocessing...
                                            </>
                                        ) : (
                                            <>Reprocess Asset</>
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleGenerateThumbnail}
                                        disabled={generateLoading || reprocessLoading}
                                        className="inline-flex items-center rounded-md border border-indigo-600 bg-white px-4 py-2 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {generateLoading ? (
                                            <>
                                                <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                                Generating...
                                            </>
                                        ) : (
                                            <>{(thumbnailStatus === 'pending' && (displayAsset?.analysis_status ?? '') === 'complete') ? 'Regenerate Preview' : 'Generate Preview'}</>
                                        )}
                                    </button>
                                </div>
                            </div>
                        ) : (
                            /* Truly unsupported file types - show static message */
                            <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
                                <p className="text-sm font-medium text-blue-800 mb-1">
                                    Preview not available for this file type.
                                </p>
                                {/* Show skip reason if available in metadata */}
                                {/* Use displayAsset (with live updates) instead of prop asset */}
                                {(displayAsset.metadata?.thumbnail_skip_reason || displayAsset.metadata?.thumbnail_skip_message) && (
                                    <>
                                        <p className="text-xs text-blue-600 mt-1">
                                            {displayAsset.metadata?.thumbnail_skip_message
                                                || (displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:tiff' 
                                                    ? 'Unsupported file type (TIFF)' 
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:avif'
                                                    ? 'Unsupported file type (AVIF)'
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:bmp'
                                                    ? 'Unsupported file type (BMP)'
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg'
                                                    ? 'Unsupported file type (SVG)'
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:ico'
                                                    ? 'Thumbnail generation is not supported for this file type.'
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:zip'
                                                    ? 'Thumbnail generation is not supported for this file type.'
                                                    : 'Unsupported file type')}
                                        </p>
                                        {/* Show regeneration option for TIFF/AVIF/SVG if retry is allowed */}
                                        {(displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:tiff' || 
                                          displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:avif' ||
                                          displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg') && 
                                         canRetryThumbnail && (
                                            <div className="mt-2">
                                                <p className="text-xs text-green-700 font-medium">
                                                    💡 {displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg' 
                                                        ? 'SVG support is now available (rasterized via Imagick).'
                                                        : 'TIFF/AVIF support is now available via Imagick.'}
                                                </p>
                                                <p className="text-xs text-green-600 mt-1">
                                                    Use &quot;Retry Pipeline&quot; or &quot;Attempt Repair&quot; to regenerate thumbnails.
                                                </p>
                                            </div>
                                        )}
                                    </>
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


                {isPdfAsset && (
                    <div className="border-t border-gray-200 pt-6">
                        <h3 className="text-sm font-medium text-gray-900 mb-2">PDF Pages</h3>
                        <div className="bg-gray-50 border border-gray-200 rounded-md p-4">
                            <p className="text-sm text-gray-700">
                                Render all pages for deep review and ingestion workflows.
                            </p>
                            {displayAsset.pdf_page_count ? (
                                <p className="mt-1 text-xs text-gray-500">
                                    Detected pages: {displayAsset.pdf_page_count}
                                </p>
                            ) : null}

                            {extractAllBatchId ? (
                                <p className="mt-2 text-xs text-gray-600 font-mono break-all">
                                    Batch: {extractAllBatchId}
                                </p>
                            ) : null}

                            {extractAllError ? (
                                <p className="mt-2 text-xs text-red-700">{extractAllError}</p>
                            ) : null}

                            <div className="mt-3">
                                <button
                                    type="button"
                                    onClick={handleExtractAllPdfPages}
                                    disabled={extractAllLoading}
                                    className="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black disabled:opacity-50"
                                >
                                    {extractAllLoading ? (
                                        <>
                                            <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                            Starting...
                                        </>
                                    ) : (
                                        <>Extract All Pages</>
                                    )}
                                </button>
                            </div>
                        </div>
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
                            
                            <div className="flex items-center gap-2 flex-wrap">
                                <button
                                    type="button"
                                    onClick={handleReprocessAsset}
                                    disabled={reprocessLoading}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                                >
                                    {reprocessLoading ? <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" /> : null}
                                    Reprocess Asset
                                </button>
                                {canRetryThumbnail && (
                                    <button
                                        type="button"
                                        onClick={() => setShowRetryModal(true)}
                                        className="inline-flex items-center rounded-md border border-red-600 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                    >
                                        <ArrowPathIcon className="h-4 w-4 mr-2" />
                                        Retry Thumbnails Only
                                    </button>
                                )}
                            </div>
                            
                            {/* Retry limit or unsupported type message */}
                            {!canRetryThumbnail && getRetryErrorMessage() && (
                                <p className="text-xs text-red-600 mt-2">
                                    {getRetryErrorMessage()}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                    </CollapsibleSection>
                </div>

                {/* Reliability Timeline (Unified Operations) */}
                <div className="border-t border-gray-200">
                    <CollapsibleSection
                        title="Reliability Timeline"
                        defaultExpanded={false}
                        onToggle={(expanded) => setReliabilityTimelineExpanded(expanded)}
                    >
                        {reliabilityTimelineLoading ? (
                            <div className="py-4 text-center text-sm text-gray-500">Loading…</div>
                        ) : reliabilityTimeline.length === 0 ? (
                            <div className="py-4 text-center text-sm text-gray-500">No reliability events for this asset.</div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {reliabilityTimeline.map((ev) => (
                                    <li key={ev.id} className="py-3 first:pt-0">
                                        <div className="flex items-start gap-2">
                                            <span className={`inline-flex shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${
                                                ev.resolved_at
                                                    ? 'bg-green-100 text-green-800'
                                                    : ev.severity === 'critical'
                                                        ? 'bg-red-100 text-red-800'
                                                        : ev.severity === 'error'
                                                            ? 'bg-amber-100 text-amber-800'
                                                            : 'bg-gray-100 text-gray-700'
                                            }`}>
                                                {ev.resolved_at ? 'Resolved' : ev.severity}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-medium text-gray-900">{ev.title}</p>
                                                {ev.message && <p className="mt-0.5 text-xs text-gray-500">{ev.message}</p>}
                                                <p className="mt-1 text-xs text-gray-400">
                                                    {ev.detected_at ? new Date(ev.detected_at).toLocaleString() : ''}
                                                    {ev.resolved_at && (
                                                        <span> → Resolved {ev.auto_resolved ? '(auto)' : ''} {new Date(ev.resolved_at).toLocaleString()}</span>
                                                    )}
                                                </p>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CollapsibleSection>
                </div>

                {/* Asset Timeline */}
                <div className="border-t border-gray-200">
                    <CollapsibleSection title="Timeline" defaultExpanded={false}>
                        <AssetTimeline 
                            events={activityEvents} 
                            loading={activityLoading}
                            onThumbnailRetry={() => {
                                // Phase 3.0C: Call backend to retry thumbnail generation (max 2 retries)
                                if (thumbnailRetryCount < 2 && canRetryThumbnail) {
                                    handleRetryThumbnail()
                                }
                            }}
                            thumbnailRetryCount={thumbnailRetryCount}
                            onVideoPreviewRetry={handleRetryVideoPreview}
                        />
                    </CollapsibleSection>
                </div>
                
            </div>

            {/* Phase 3.1: Zoom Modal with Carousel for Assets with Thumbnails (Images and PDFs) or Videos */}
            {showZoomModal && (hasThumbnailSupport || isVideo) && currentCarouselAsset?.id && (
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

                    {/* Image or Video with smooth slide transition */}
                    <div 
                        className="relative w-full h-full flex items-center justify-center overflow-hidden"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Phase V-1: Check if current asset is a video */}
                        {(() => {
                            const currentMimeType = currentCarouselAsset.mime_type || ''
                            const currentFilename = currentCarouselAsset.original_filename || ''
                            const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
                            const ext = currentFilename.split('.').pop()?.toLowerCase() || ''
                            const isCurrentVideo = currentMimeType.startsWith('video/') || videoExtensions.includes(ext)
                            const isCurrentPdf = Boolean(currentCarouselAsset?.is_pdf)
                                || currentMimeType === 'application/pdf'
                                || ext === 'pdf'
                            
                            if (isCurrentVideo && currentCarouselAsset.id) {
                                // Video playback in fullscreen modal
                                // Use view URL (not download URL) to avoid tracking download
                                if (videoViewUrlLoading) {
                                    return (
                                        <div className="flex items-center justify-center text-white">
                                            <ArrowPathIcon className="h-8 w-8 animate-spin" />
                                        </div>
                                    )
                                }
                                
                                if (!videoViewUrl) {
                                    return (
                                        <div className="flex items-center justify-center text-white">
                                            <p>Video not available</p>
                                        </div>
                                    )
                                }
                                
                                return (
                                    <video
                                        key={currentCarouselAsset.id} // Key forces remount for clean transition
                                        className="max-w-full max-h-full object-contain transition-all duration-300 ease-in-out bg-black"
                                        controls
                                        autoPlay
                                        poster={currentCarouselAsset.video_poster_url || currentCarouselAsset.thumbnail_url || currentCarouselAsset.final_thumbnail_url || undefined}
                                        preload="auto"
                                        playsInline
                                        style={{
                                            transform: transitionDirection === 'left' 
                                                ? 'translateX(30px)' 
                                                : transitionDirection === 'right' 
                                                ? 'translateX(-30px)' 
                                                : 'translateX(0)',
                                            opacity: transitionDirection ? 0 : 1,
                                        }}
                                    >
                                        <source src={videoViewUrl} type={currentCarouselAsset.mime_type || 'video/mp4'} />
                                        Your browser does not support the video tag.
                                    </video>
                                )
                            } else if (isCurrentPdf && currentCarouselAsset.id) {
                                return (
                                    <PDFViewer asset={currentCarouselAsset} />
                                )
                            } else {
                                // Image/PDF thumbnail
                                const carouselImgUrl = currentCarouselAsset.thumbnail_url_large ?? currentCarouselAsset.final_thumbnail_url ?? currentCarouselAsset.thumbnail_url ?? currentCarouselAsset.preview_thumbnail_url ?? ''
                                console.log('IMAGE LARGE URL FROM API:', carouselImgUrl)
                                return (
                                    <img
                                        key={currentCarouselAsset.id}
                                        src={carouselImgUrl}
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
                                )
                            }
                        })()}
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

            {/* Publish Confirmation Modal */}
            {showPublishModal && (
                <div className="fixed inset-0 z-[70] bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center mb-4">
                            <CheckCircleIcon className="h-6 w-6 text-green-600 mr-3" />
                            <h3 className="text-lg font-semibold text-gray-900">Publish Asset</h3>
                        </div>
                        
                        <p className="text-sm text-gray-600 mb-4">
                            Are you sure you want to publish this asset? Once published, it will be visible to all users with access to this brand.
                        </p>
                        
                        <div className="flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowPublishModal(false)
                                }}
                                disabled={publishLoading}
                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={async () => {
                                    setPublishLoading(true)
                                    try {
                                        const response = await window.axios.post(`/app/assets/${displayAsset.id}/publish`)
                                        
                                        if (response.data && response.data.message) {
                                            // Format success message with timestamp and user
                                            const publishedAt = response.data.published_at 
                                                ? new Date(response.data.published_at).toLocaleString('en-US', {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    year: 'numeric',
                                                    hour: 'numeric',
                                                    minute: '2-digit',
                                                    hour12: true
                                                })
                                                : 'now'
                                            
                                            const userName = auth?.user?.name || auth?.user?.email || 'You'
                                            
                                            setToastMessage(`Published at: ${publishedAt} by: ${userName}`)
                                            setToastType('success')
                                            
                                            // Auto-hide toast after 8 seconds (longer to account for reload)
                                            setTimeout(() => {
                                                setToastMessage(null)
                                            }, 8000)
                                            
                                            // Close modal
                                            setShowPublishModal(false)
                                            
                                            // Update local asset state instead of full reload
                                            // This preserves drawer state and grid scroll position
                                            if (onAssetUpdate && response.data.asset) {
                                                // Merge updated fields into existing asset
                                                const updatedAsset = {
                                                    ...displayAsset,
                                                    ...response.data.asset,
                                                }
                                                onAssetUpdate(updatedAsset)
                                            } else {
                                                // Fallback: reload only assets if callback not provided
                                                router.reload({ 
                                                    only: ['assets'], 
                                                    preserveState: true, 
                                                    preserveScroll: true 
                                                })
                                            }
                                        }
                                    } catch (err) {
                                        console.error('Failed to publish asset:', err)
                                        
                                        // Extract error message from response
                                        let errorMessage = 'You do not have permission to publish this asset.'
                                        
                                        if (err.response) {
                                            // Server returned an error response
                                            if (err.response.status === 403) {
                                                errorMessage = err.response.data?.message || 
                                                              'You do not have permission to publish this asset. Please check that you have the "asset.publish" permission and are assigned to this brand.'
                                            } else if (err.response.status === 404) {
                                                errorMessage = 'Asset not found.'
                                            } else {
                                                errorMessage = err.response.data?.message || 
                                                              err.response.data?.error || 
                                                              `Failed to publish asset (${err.response.status}).`
                                            }
                                        } else if (err.message) {
                                            errorMessage = err.message
                                        }
                                        
                                        setToastMessage(errorMessage)
                                        setToastType('error')
                                        
                                        // Auto-hide error toast after 8 seconds
                                        setTimeout(() => {
                                            setToastMessage(null)
                                        }, 8000)
                                    } finally {
                                        setPublishLoading(false)
                                    }
                                }}
                                disabled={publishLoading}
                                className="inline-flex items-center rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-600 disabled:opacity-50"
                            >
                                {publishLoading ? (
                                    <>
                                        <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                        Publishing...
                                    </>
                                ) : (
                                    <>
                                        <CheckIcon className="h-4 w-4 mr-2" />
                                        Publish
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Asset Detail Panel (full-height slide panel, 80% width) */}
            {displayAsset && (
                <AssetDetailPanel
                    asset={displayAsset}
                    isOpen={showDetailsModal}
                    onClose={() => setShowDetailsModal(false)}
                    activityEvents={activityEvents}
                    activityLoading={activityLoading}
                    onReplaceFile={() => setShowReplaceFileModal(true)}
                    onDelete={canDelete ? () => setShowDeleteConfirm(true) : undefined}
                    onReprocessAsset={canRetryThumbnails ? handleReprocessAsset : undefined}
                    reprocessLoading={reprocessLoading}
                    onToast={(msg, type) => {
                        setToastMessage(msg ?? null)
                        setToastType(type || 'success')
                        if (msg) setTimeout(() => setToastMessage(null), 3000)
                    }}
                    primaryColor={brandPrimary}
                />
            )}
            
            {/* Phase AF-2: Resubmit Modal */}
            {/* Phase AF-5: Only show if approvals are enabled */}
            {/* Phase J.3.1: Updated to include file uploader */}
            {auth?.approval_features?.approvals_enabled && showResubmitModal && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => {
                            if (!resubmitLoading) {
                                setShowResubmitModal(false)
                                setResubmitComment('')
                                setResubmitFile(null)
                                setResubmitUploadProgress(0)
                                setResubmitError(null)
                                if (resubmitFileInputRef.current) {
                                    resubmitFileInputRef.current.value = ''
                                }
                            }
                        }} />
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="absolute right-0 top-0 hidden pr-4 pt-4 sm:block">
                                <button
                                    type="button"
                                    className="rounded-md bg-white text-gray-400 hover:text-gray-500"
                                    onClick={() => {
                                        if (!resubmitLoading) {
                                            setShowResubmitModal(false)
                                            setResubmitComment('')
                                            setResubmitFile(null)
                                            setResubmitUploadProgress(0)
                                            if (resubmitFileInputRef.current) {
                                                resubmitFileInputRef.current.value = ''
                                            }
                                        }
                                    }}
                                    disabled={resubmitLoading}
                                >
                                    <XMarkIcon className="h-6 w-6" />
                                </button>
                            </div>
                            <div className="sm:flex sm:items-start">
                                <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                        Resubmit Asset for Approval
                                    </h3>
                                    <p className="text-sm text-gray-500 mb-4">
                                        Replace the file for this asset. Metadata will remain unchanged and the asset will be reviewed again before publishing.
                                    </p>

                                    {/* File Input */}
                                    <div className="mt-4">
                                        <label htmlFor="resubmit-file-input" className="block text-sm font-medium text-gray-700 mb-2">
                                            Select File
                                        </label>
                                        <input
                                            ref={resubmitFileInputRef}
                                            id="resubmit-file-input"
                                            type="file"
                                            onChange={(e) => {
                                                const file = e.target.files?.[0]
                                                if (file) {
                                                    setResubmitFile(file)
                                                }
                                            }}
                                            disabled={resubmitLoading}
                                            className="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-yellow-50 file:text-yellow-700 hover:file:bg-yellow-100"
                                        />
                                        {resubmitFile && (
                                            <p className="mt-2 text-sm text-gray-600">
                                                Selected: {resubmitFile.name} ({(resubmitFile.size / 1024 / 1024).toFixed(2)} MB)
                                            </p>
                                        )}
                                    </div>

                                    {/* Optional Comment */}
                                    <div className="mt-4">
                                        <label htmlFor="resubmit-comment" className="block text-sm font-medium text-gray-700 mb-2">
                                            Comment (optional)
                                        </label>
                                        <textarea
                                            id="resubmit-comment"
                                            rows={3}
                                            value={resubmitComment}
                                            onChange={(e) => setResubmitComment(e.target.value)}
                                            disabled={resubmitLoading}
                                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm"
                                            placeholder="Add a comment explaining changes or addressing feedback..."
                                        />
                                    </div>

                                    {/* Upload Progress */}
                                    {resubmitLoading && (
                                        <div className="mt-4">
                                            <div className="flex items-center justify-between text-sm text-gray-600 mb-1">
                                                <span>Uploading...</span>
                                                <span>{resubmitUploadProgress}%</span>
                                            </div>
                                            <div className="w-full bg-gray-200 rounded-full h-2">
                                                <div
                                                    className="bg-yellow-600 h-2 rounded-full transition-all duration-300"
                                                    style={{ width: `${resubmitUploadProgress}%` }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-2">
                                <button
                                    type="button"
                                    disabled={!resubmitFile || resubmitLoading}
                                    onClick={async () => {
                                        if (!resubmitFile || resubmitLoading) return

                                        setResubmitLoading(true)
                                        setResubmitUploadProgress(0)
                                        setResubmitError(null)

                                        try {
                                            // Step 1: Initiate replace upload session
                                            const initiateResponse = await window.axios.post(
                                                `/app/assets/${displayAsset.id}/replace-file`,
                                                {
                                                    file_name: resubmitFile.name,
                                                    file_size: resubmitFile.size,
                                                    mime_type: resubmitFile.type,
                                                }
                                            )

                                            const { upload_session_id, upload_type, upload_url } = initiateResponse.data

                                            // Step 2: Upload file to S3 (direct or multipart)
                                            if (upload_type === 'direct' && upload_url) {
                                                const uploadResponse = await fetch(upload_url, {
                                                    method: 'PUT',
                                                    body: resubmitFile,
                                                    headers: {
                                                        'Content-Type': resubmitFile.type || 'application/octet-stream',
                                                    },
                                                })
                                                if (!uploadResponse.ok) {
                                                    throw new Error(`Upload failed: ${uploadResponse.status} ${uploadResponse.statusText}`)
                                                }
                                                setResubmitUploadProgress(100)
                                            } else if (upload_type === 'chunked') {
                                                // Multipart: init → upload parts → complete
                                                const initRes = await window.axios.post(`/app/uploads/${upload_session_id}/multipart/init`)
                                                const { part_size: partSize, total_parts: totalParts } = initRes.data
                                                const parts = {}
                                                for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
                                                    const start = (partNumber - 1) * partSize
                                                    const end = Math.min(start + partSize, resubmitFile.size)
                                                    const chunk = resubmitFile.slice(start, end)
                                                    const signRes = await window.axios.post(
                                                        `/app/uploads/${upload_session_id}/multipart/sign-part`,
                                                        { part_number: partNumber }
                                                    )
                                                    const putRes = await fetch(signRes.data.upload_url, { method: 'PUT', body: chunk })
                                                    if (!putRes.ok) throw new Error(`Part ${partNumber} upload failed: ${putRes.status}`)
                                                    const etag = putRes.headers.get('ETag')?.replace(/"/g, '')
                                                    if (!etag) throw new Error(`No ETag for part ${partNumber}`)
                                                    parts[String(partNumber)] = etag
                                                    setResubmitUploadProgress(Math.round((partNumber / totalParts) * 100))
                                                }
                                                await window.axios.post(`/app/uploads/${upload_session_id}/multipart/complete`, { parts })
                                                setResubmitUploadProgress(100)
                                            } else {
                                                throw new Error(`Unsupported upload type: ${upload_type}`)
                                            }

                                            // Step 3: Finalize upload (replace file)
                                            const finalizeResponse = await window.axios.post('/app/uploads/finalize', {
                                                manifest: [
                                                    {
                                                        upload_key: `temp/uploads/${upload_session_id}/original`,
                                                        expected_size: resubmitFile.size,
                                                        comment: resubmitComment.trim() || null,
                                                    },
                                                ],
                                            })

                                            if (finalizeResponse.data?.results?.[0]?.status === 'success') {
                                                setToastMessage('Asset resubmitted successfully.')
                                                setToastType('success')
                                                setTimeout(() => {
                                                    setToastMessage(null)
                                                }, 5000)
                                                
                                                setShowResubmitModal(false)
                                                setResubmitComment('')
                                                setResubmitFile(null)
                                                setResubmitUploadProgress(0)
                                                if (resubmitFileInputRef.current) {
                                                    resubmitFileInputRef.current.value = ''
                                                }
                                                
                                                // Update local asset state if callback provided, otherwise reload
                                                if (onAssetUpdate && finalizeResponse.data?.results?.[0]?.asset) {
                                                    onAssetUpdate(finalizeResponse.data.results[0].asset)
                                                } else {
                                                    router.reload({ 
                                                        only: ['assets'], 
                                                        preserveState: true, 
                                                        preserveScroll: true 
                                                    })
                                                }
                                            } else {
                                                // Extract error message from error object (may be string or object with message property)
                                                const errorData = finalizeResponse.data?.results?.[0]?.error
                                                const errorMessage = typeof errorData === 'string' 
                                                    ? errorData 
                                                    : errorData?.message || 'Finalization failed'
                                                throw new Error(errorMessage)
                                            }
                                        } catch (err) {
                                            console.error('Failed to resubmit asset:', err)
                                            // Extract error message safely (handle objects, arrays, etc.)
                                            let errorMessage = 'Failed to resubmit asset.'
                                            if (err.response?.data?.error) {
                                                errorMessage = typeof err.response.data.error === 'string' 
                                                    ? err.response.data.error 
                                                    : err.response.data.error?.message || JSON.stringify(err.response.data.error)
                                            } else if (err.response?.data?.message) {
                                                errorMessage = typeof err.response.data.message === 'string'
                                                    ? err.response.data.message
                                                    : JSON.stringify(err.response.data.message)
                                            } else if (err.message) {
                                                errorMessage = err.message
                                            }
                                            setToastMessage(errorMessage)
                                            setToastType('error')
                                            setTimeout(() => {
                                                setToastMessage(null)
                                            }, 5000)
                                        } finally {
                                            setResubmitLoading(false)
                                            setResubmitUploadProgress(0)
                                        }
                                    }}
                                    className="inline-flex w-full justify-center rounded-md bg-yellow-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 sm:ml-3 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {resubmitLoading ? 'Resubmitting...' : 'Resubmit'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (!resubmitLoading) {
                                            setShowResubmitModal(false)
                                            setResubmitComment('')
                                            setResubmitFile(null)
                                            setResubmitUploadProgress(0)
                                            if (resubmitFileInputRef.current) {
                                                resubmitFileInputRef.current.value = ''
                                            }
                                        }
                                    }}
                                    disabled={resubmitLoading}
                                    className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* PDF extracted text preview modal */}
            {showPdfTextModal && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowPdfTextModal(false)} />
                        <div className="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                            <div className="bg-white px-4 pb-4 pt-5 sm:p-6">
                                <div className="flex items-center justify-between border-b border-gray-200 pb-3">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900">Extracted text</h3>
                                    <button
                                        type="button"
                                        className="rounded-md text-gray-400 hover:text-gray-500"
                                        onClick={() => setShowPdfTextModal(false)}
                                    >
                                        <XMarkIcon className="h-6 w-6" />
                                    </button>
                                </div>
                                <div className="mt-3 max-h-[60vh] overflow-y-auto rounded border border-gray-200 bg-gray-50 p-3">
                                    {pdfTextExtraction?.extracted_text ? (
                                        <pre className="whitespace-pre-wrap break-words font-sans text-sm text-gray-800">
                                            {pdfTextExtraction.extracted_text}
                                        </pre>
                                    ) : (
                                        <p className="text-sm text-gray-500">No text to display.</p>
                                    )}
                                </div>
                                {pdfTextExtraction?.extraction_source && (
                                    <p className="mt-2 text-xs text-gray-400">Source: {pdfTextExtraction.extraction_source}</p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Toast Notification */}
            {toastMessage && (
                <div className="fixed top-4 right-4 z-50 max-w-md w-full">
                    <div className={`rounded-lg border p-4 shadow-lg ${
                        toastType === 'error' ? 'bg-red-50 border-red-200 text-red-800' :
                        toastType === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-800' :
                        toastType === 'info' ? 'bg-blue-50 border-blue-200 text-blue-800' :
                        'bg-green-50 border-green-200 text-green-800'
                    }`}>
                        <div className="flex items-start">
                            <div className="flex-shrink-0">
                                {toastType === 'error' ? (
                                    <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clipRule="evenodd" />
                                    </svg>
                                ) : (
                                    <svg className="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clipRule="evenodd" />
                                    </svg>
                                )}
                            </div>
                            <div className="ml-3 flex-1">
                                <p className="text-sm font-medium">{toastMessage}</p>
                                {toastTicketUrl && (
                                    <a
                                        href={toastTicketUrl}
                                        className="mt-2 inline-flex text-sm font-medium text-green-700 hover:text-green-800 underline"
                                    >
                                        View ticket →
                                    </a>
                                )}
                            </div>
                            <div className="ml-4 flex-shrink-0">
                                <button
                                    type="button"
                                    onClick={() => { setToastMessage(null); setToastTicketUrl(null) }}
                                    className={`inline-flex rounded-md p-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                                        toastType === 'error' ? 'text-red-500 hover:bg-red-100 focus:ring-red-600' :
                                        toastType === 'warning' ? 'text-yellow-500 hover:bg-yellow-100 focus:ring-yellow-600' :
                                        toastType === 'info' ? 'text-blue-500 hover:bg-blue-100 focus:ring-blue-600' :
                                        'text-green-500 hover:bg-green-100 focus:ring-green-600'
                                    }`}
                                >
                                    <span className="sr-only">Dismiss</span>
                                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            
            {/* Phase J.3.1: Replace File Modal */}
            {showReplaceFileModal && displayAsset && (
                <ReplaceFileModal
                    asset={displayAsset}
                    isOpen={showReplaceFileModal}
                    onClose={() => setShowReplaceFileModal(false)}
                    onSuccess={() => {
                        setShowReplaceFileModal(false)
                        setToastMessage('File replaced successfully. Asset has been resubmitted for review.')
                        setToastType('success')
                        setTimeout(() => {
                            setToastMessage(null)
                        }, 5000)
                        // Reload asset drawer to show updated status
                        setTimeout(() => {
                            router.reload({ preserveState: true, preserveScroll: true })
                        }, 500)
                    }}
                />
            )}
            
            {/* Delete asset confirmation modal (soft delete — permanent after grace period) */}
            {showDeleteConfirm && displayAsset && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div
                            className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            onClick={() => !deleteLoading && setShowDeleteConfirm(false)}
                        />
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="sm:flex sm:items-start">
                                <div className="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <ExclamationTriangleIcon className="h-6 w-6 text-red-600" />
                                </div>
                                <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900">
                                        Delete asset?
                                    </h3>
                                    <div className="mt-2">
                                        <p className="text-sm text-gray-500">
                                            This will move &quot;{displayAsset.original_filename || displayAsset.title || 'this asset'}&quot; to trash. It can be restored within {auth?.deletion_grace_period_days ?? 30} days. After that, it will be permanently deleted.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-2">
                                <button
                                    type="button"
                                    onClick={handleDeleteConfirm}
                                    disabled={deleteLoading}
                                    className="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto disabled:opacity-50"
                                >
                                    {deleteLoading ? 'Deleting…' : 'Delete'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => !deleteLoading && setShowDeleteConfirm(false)}
                                    disabled={deleteLoading}
                                    className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            
            {/* Quick Review Modal - opened from drawer */}
            {showReviewModal && displayAsset && (
                <PendingAssetReviewModal
                    isOpen={showReviewModal}
                    onClose={() => {
                        setShowReviewModal(false)
                        // Reload to refresh asset status
                        router.reload({ preserveState: true, preserveScroll: true })
                    }}
                    initialAssetId={displayAsset.id}
                    initialAsset={{
                        ...displayAsset,
                        // Ensure approval_status is set correctly
                        approval_status: displayAsset.approval_status || 'pending',
                    }}
                />
            )}
        </div>
    )
}

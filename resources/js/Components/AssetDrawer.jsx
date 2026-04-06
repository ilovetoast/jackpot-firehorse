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
import { useCallback, useEffect, useMemo, useRef, useState, lazy, Suspense } from 'react'
import { createPortal } from 'react-dom'
import { XMarkIcon, ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon, ExclamationTriangleIcon, EyeIcon, ArrowDownTrayIcon, CheckCircleIcon, CheckIcon, ArrowUturnLeftIcon, ClockIcon, XCircleIcon, CloudArrowUpIcon, RectangleStackIcon, TicketIcon, InformationCircleIcon, PhotoIcon } from '@heroicons/react/24/outline'
import { usePage, router, Link } from '@inertiajs/react'
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
import AssetBrandIntelligenceBlock from './AssetBrandIntelligenceBlock'
import ApprovalHistory from './ApprovalHistory'
import PendingAssetReviewModal from './PendingAssetReviewModal'
import PDFViewer from './PDFViewer'
import { getUploadAcceptAttribute } from '../utils/damFileTypes'
import { getThumbnailState, getThumbnailVersion, supportsThumbnail } from '../utils/thumbnailUtils'
import { getPipelineStageLabel, getPipelineStageIndex, PIPELINE_STAGES } from '../utils/pipelineStatusUtils'
import { getAssetCategoryId, parseAssetQualityRating } from '../utils/assetUtils'

const BrandDebugOverlay = lazy(() => import('./BrandDebugOverlay'))
import { filterActiveCategories } from '../utils/categoryUtils'
import { usePermission } from '../hooks/usePermission'
import PromoteBrandReferenceModal from './PromoteBrandReferenceModal'
import { useDrawerThumbnailPoll } from '../hooks/useDrawerThumbnailPoll'
import { useAssetMetrics } from '../hooks/useAssetMetrics'
import { CheckCircleIcon as CheckCircleIconSolid } from '@heroicons/react/24/solid'
import CollectionSelector from './Collections/CollectionSelector' // C9.1
import CreateCollectionModal from './Collections/CreateCollectionModal' // C9.1
import { useSelectionOptional } from '../contexts/SelectionContext'

/** Brand reference CTA thresholds: quality rating must be > this (i.e. 4–5 on 1–5 scale), or starred, or engagement. */
const BRAND_REFERENCE_PROMPT_MIN_QUALITY_EXCLUSIVE = 3
const BRAND_REFERENCE_PROMPT_MIN_DOWNLOADS = 8
const BRAND_REFERENCE_PROMPT_MIN_VIEWS = 35

/** Reliability timeline: keep UI readable; full text stays in DB and API (`GET /assets/{id}/incidents?timeline=1`). */
const RELIABILITY_TIMELINE_MESSAGE_PREVIEW_CHARS = 400

function ReliabilityTimelineIncidentMessage({ message }) {
    const [expanded, setExpanded] = useState(false)
    if (!message) {
        return null
    }
    const needsToggle = message.length > RELIABILITY_TIMELINE_MESSAGE_PREVIEW_CHARS
    const display =
        !expanded && needsToggle
            ? `${message.slice(0, RELIABILITY_TIMELINE_MESSAGE_PREVIEW_CHARS).trimEnd()}…`
            : message

    return (
        <div className="mt-0.5 min-w-0">
            <p
                className={`text-xs text-gray-500 break-words ${
                    expanded ? 'max-h-48 overflow-y-auto whitespace-pre-wrap' : ''
                }`}
                title={!expanded && needsToggle ? message : undefined}
            >
                {display}
            </p>
            {needsToggle && (
                <button
                    type="button"
                    onClick={() => setExpanded((v) => !v)}
                    className="mt-1 text-xs font-medium text-indigo-600 hover:text-indigo-500"
                >
                    {expanded ? 'Show less' : 'Show full message'}
                </button>
            )}
        </div>
    )
}

/** Full-stage lightbox fallback when raster preview URL is missing or fails to load (e.g. PSD, octet-stream). */
function LightboxPreviewPlaceholder({ asset }) {
    const title = asset?.title || asset?.original_filename || 'Asset'
    const extRaw = asset?.file_extension || asset?.original_filename?.split('.').pop() || ''
    const ext = String(extRaw).toUpperCase() || 'FILE'
    return (
        <div className="relative flex h-full min-h-0 w-full flex-col items-center justify-center overflow-hidden px-6 py-10">
            <div
                className="pointer-events-none absolute inset-0 bg-gradient-to-b from-indigo-950/35 via-black to-neutral-950"
                aria-hidden
            />
            <div
                className="pointer-events-none absolute inset-0 opacity-90 [background:radial-gradient(ellipse_85%_55%_at_50%_-5%,rgba(255,255,255,0.07),transparent_52%)]"
                aria-hidden
            />
            <div
                className="pointer-events-none absolute inset-0 opacity-[0.12]"
                style={{
                    backgroundImage:
                        'url("data:image/svg+xml,%3Csvg viewBox=\'0 0 256 256\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'n\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'0.85\' numOctaves=\'4\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23n)\'/%3E%3C/svg%3E")',
                }}
                aria-hidden
            />
            <div
                className="relative z-[1] flex max-w-md flex-col items-center text-center"
                role="img"
                aria-label={`No preview available for ${title}`}
            >
                <div className="relative mb-8 flex h-32 w-32 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.06] shadow-[0_0_0_1px_rgba(255,255,255,0.04)_inset,0_24px_48px_-12px_rgba(0,0,0,0.6)]">
                    <PhotoIcon className="h-16 w-16 text-white/30" aria-hidden />
                    <span className="absolute -bottom-2.5 rounded-md bg-white/90 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-neutral-900 shadow-md backdrop-blur-sm">
                        {ext}
                    </span>
                </div>
                <p className="text-lg font-semibold tracking-tight text-white/95">{title}</p>
                <p className="mt-3 text-sm leading-relaxed text-white/50">
                    Preview isn&apos;t available for this file in the browser. Use <span className="text-white/70">Details</span> for metadata and actions, or download the original from the asset panel.
                </p>
            </div>
        </div>
    )
}

export default function AssetDrawer({
    asset,
    onClose,
    assets = [],
    currentAssetIndex = null,
    onAssetUpdate = null,
    collectionContext = null,
    bucketAssetIds = [],
    onBucketToggle = null,
    primaryColor,
    selectionAssetType = 'asset',
    /** When true (e.g. grid double-click), open the fullscreen zoom modal once the drawer mounts */
    initialZoomOpen = false,
    onInitialZoomConsumed = null,
    /** Collection invite / external guest: skip internal-only fetches (activity, incidents, metrics, collections admin APIs). */
    externalCollectionGuest = false,
}) {
    const pageProps = usePage().props
    const { auth, download_policy_disable_single_asset: policyDisableSingleAsset = false } = pageProps
    const damUploadAccept = pageProps.dam_file_types?.upload_accept || getUploadAcceptAttribute()
    const categories = pageProps.categories ?? []
    const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const { can } = usePermission()
    const drawerRef = useRef(null)
    const closeButtonRef = useRef(null)
    /** One-shot: grid double-click initial zoom per drawer mount */
    const initialZoomAppliedRef = useRef(false)
    const [showZoomModal, setShowZoomModal] = useState(false)
    /** Google Fonts virtual row: stylesheet loaded for specimen preview in drawer/lightbox */
    const [virtualGoogleFontReady, setVirtualGoogleFontReady] = useState(false)
    /** When true, lightbox shows AssetDetailPanel in the right column (or below on small screens) */
    const [showLightboxDetails, setShowLightboxDetails] = useState(false)
    /** Lightbox raster preview failed or URL empty — show themed placeholder instead of broken <img> */
    const [lightboxImageError, setLightboxImageError] = useState(false)
    const [activityEvents, setActivityEvents] = useState([])
    const [activityLoading, setActivityLoading] = useState(false)
    // Track layout settling to prevent preview jump during grid reflow (grid reserves drawer width in one frame)
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
    // Phase B2: Force delete from trash (type DELETE to confirm)
    const [showForceDeleteConfirm, setShowForceDeleteConfirm] = useState(false)
    const [forceDeleteConfirmText, setForceDeleteConfirmText] = useState('')
    const [forceDeleteLoading, setForceDeleteLoading] = useState(false)
    
    // Phase J.3: Approval comments for rejection role display
    const [approvalComments, setApprovalComments] = useState([])
    const [commentsLoading, setCommentsLoading] = useState(false)
    // Reference materials: Publish & categorize modal (builder-staged assets)
    const [showFinalizeFromBuilderModal, setShowFinalizeFromBuilderModal] = useState(false)
    const [finalizeCategoryId, setFinalizeCategoryId] = useState(null)
    const [finalizeLoading, setFinalizeLoading] = useState(false)
    const [promoteModalOpen, setPromoteModalOpen] = useState(false)

    // Unified Operations: Unresolved incidents for asset (processing issues)
    const [assetIncidents, setAssetIncidents] = useState([])
    const [incidentsLoading, setIncidentsLoading] = useState(false)
    // Reliability Timeline: all incidents (resolved + unresolved) for collapsible section
    const [reliabilityTimeline, setReliabilityTimeline] = useState([])
    const [reliabilityTimelineLoading, setReliabilityTimelineLoading] = useState(false)
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
    const [brandIntelActivityBanner, setBrandIntelActivityBanner] = useState(null)
    /** Dev-only: pipeline debug snapshot lifted from AssetMetadataDisplay (single fetch). */
    const [pipelineDevSnap, setPipelineDevSnap] = useState(null)
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
        if (externalCollectionGuest) {
            trackedDrawerViewsRef.current.clear()
            return
        }
        if (asset?.id && !asset?.is_virtual_google_font) {
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
    }, [asset?.id, trackView, externalCollectionGuest])

    // Track large view when zoom modal opens
    // Use ref to track if we've already tracked this asset's large view
    const trackedLargeViewsRef = useRef(new Set())
    
    useEffect(() => {
        if (externalCollectionGuest) {
            trackedLargeViewsRef.current.clear()
            return
        }
        if (showZoomModal && asset?.id && !asset?.is_virtual_google_font) {
            // Check if we've already tracked this asset's large view
            const trackingKey = `${asset.id}_large_view`
            if (trackedLargeViewsRef.current.has(trackingKey)) {
                return
            }

            trackView(asset.id, 'large_view')
            trackedLargeViewsRef.current.add(trackingKey)
        } else if (!showZoomModal && asset?.id && !asset?.is_virtual_google_font) {
            // Reset tracking for this asset when modal closes
            const trackingKey = `${asset.id}_large_view`
            trackedLargeViewsRef.current.delete(trackingKey)
        }
    }, [showZoomModal, asset?.id, trackView, externalCollectionGuest])

    // Fetch analytics/metrics when asset changes
    useEffect(() => {
        if (externalCollectionGuest || !asset?.id || asset?.is_virtual_google_font) {
            setViewCount(null)
            setDownloadCount(null)
            setMetricsLoading(false)
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
    }, [asset?.id, getViewCount, getDownloadCount, externalCollectionGuest])

    // Briefly hide preview until after layout (grid reserves drawer width in one frame; no animated padding)
    useEffect(() => {
        if (!asset) {
            setIsLayoutSettling(true)
            return
        }

        setIsLayoutSettling(true)
        const id = requestAnimationFrame(() => {
            setIsLayoutSettling(false)
        })

        return () => cancelAnimationFrame(id)
    }, [asset?.id])

    // Fetch activity events when asset is set
    useEffect(() => {
        if (externalCollectionGuest || !asset || !asset.id || asset.is_virtual_google_font) {
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
    }, [asset?.id, externalCollectionGuest])

    // C5: Fetch collections this asset is in (for "In X collections")
    // C9.1: Always fetch collections if asset exists (not dependent on collectionContext)
    useEffect(() => {
        if (externalCollectionGuest || !asset?.id || asset?.is_virtual_google_font) {
            setAssetCollections([])
            setAssetCollectionsLoading(false)
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
    }, [asset?.id, externalCollectionGuest])

    // C5: Fetch collections list for "Add to Collection" dropdown
    // C9.1: Always fetch collections list (not dependent on collectionContext) for inline modal
    useEffect(() => {
        if (externalCollectionGuest || !asset?.id || asset?.is_virtual_google_font) {
            setDropdownCollections([])
            setDropdownCollectionsLoading(false)
            return
        }
        setDropdownCollectionsLoading(true)
        window.axios.get('/app/collections/list', { headers: { Accept: 'application/json' } })
            .then(res => {
                setDropdownCollections((res.data?.collections ?? []).filter(Boolean))
            })
            .catch(() => setDropdownCollections([]))
            .finally(() => setDropdownCollectionsLoading(false))
    }, [asset?.id, externalCollectionGuest])

    // C9.2: Category ID for edit schema (drawer respects Metadata Management Quick View)
    const assetCategoryId = getAssetCategoryId(asset)

    // C9.2: Collection field visibility from edit schema (Quick View checkbox in Metadata Management)
    useEffect(() => {
        if (externalCollectionGuest || !assetCategoryId || asset?.is_virtual_google_font) {
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
    }, [asset?.id, assetCategoryId, asset?.mime_type, asset?.category, asset?.metadata, externalCollectionGuest])

    // Phase J.3: Fetch approval comments for rejected assets (to get rejecting user role)
    useEffect(() => {
        if (!asset || !asset.id || asset.is_virtual_google_font || !auth?.activeBrand || asset.approval_status !== 'rejected') {
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
        pollEnabled: !externalCollectionGuest,
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

    const isOwnUpload = useMemo(() => {
        const uid = auth?.user?.id
        const aid = displayAsset?.user_id
        return uid != null && aid != null && String(uid) === String(aid)
    }, [auth?.user?.id, displayAsset?.user_id])

    const isVirtualGoogleFont = Boolean(displayAsset?.is_virtual_google_font)
    const googleFontSpecimenUrl = useMemo(() => {
        if (!displayAsset?.is_virtual_google_font) return null
        return (
            displayAsset.google_font_specimen_url
            || (displayAsset.google_font_family
                ? `https://fonts.google.com/specimen/${encodeURIComponent(displayAsset.google_font_family)}`
                : null)
        )
    }, [displayAsset?.is_virtual_google_font, displayAsset?.google_font_specimen_url, displayAsset?.google_font_family])

    useEffect(() => {
        if (!isVirtualGoogleFont || !displayAsset?.google_font_stylesheet_url) {
            setVirtualGoogleFontReady(false)
            return undefined
        }
        const url = displayAsset.google_font_stylesheet_url
        const elId = `asset-drawer-google-font-${String(displayAsset.id).replace(/[^a-z0-9_-]/gi, '-')}`
        if (document.getElementById(elId)) {
            setVirtualGoogleFontReady(true)
            return undefined
        }
        const link = document.createElement('link')
        link.id = elId
        link.rel = 'stylesheet'
        link.href = url
        link.crossOrigin = 'anonymous'
        const done = () => setVirtualGoogleFontReady(true)
        link.onload = done
        link.onerror = done
        document.head.appendChild(link)
        return undefined
    }, [isVirtualGoogleFont, displayAsset?.id, displayAsset?.google_font_stylesheet_url])

    const drawerCategory = useMemo(() => {
        const cid = getAssetCategoryId(displayAsset)
        if (cid == null) {
            return null
        }
        return categories.find((c) => String(c.id) === String(cid)) ?? null
    }, [categories, displayAsset])

    const ebiEnabledForAsset = drawerCategory?.ebi_enabled === true

    /** Show “Use as a brand reference” only for strong signals (curation or usage), or when already promoted */
    const showBrandReferenceCard = useMemo(() => {
        if (!displayAsset?.id || displayAsset.is_virtual_google_font) {
            return false
        }
        if (displayAsset.reference_promotion) {
            return true
        }
        const rating = parseAssetQualityRating(displayAsset)
        const curated =
            displayAsset.starred === true ||
            (rating != null && rating > BRAND_REFERENCE_PROMPT_MIN_QUALITY_EXCLUSIVE)
        if (curated) {
            return true
        }
        if (metricsLoading) {
            return false
        }
        const downloads = downloadCount ?? 0
        const views = viewCount ?? 0
        return (
            downloads >= BRAND_REFERENCE_PROMPT_MIN_DOWNLOADS ||
            views >= BRAND_REFERENCE_PROMPT_MIN_VIEWS
        )
    }, [
        displayAsset?.id,
        displayAsset?.is_virtual_google_font,
        displayAsset?.reference_promotion,
        displayAsset?.starred,
        displayAsset?.metadata,
        metricsLoading,
        downloadCount,
        viewCount,
    ])

    const brandDebugPreviewUrl = useMemo(
        () =>
            displayAsset?.final_thumbnail_url ||
            displayAsset?.thumbnail_url ||
            displayAsset?.preview_thumbnail_url ||
            null,
        [
            displayAsset?.final_thumbnail_url,
            displayAsset?.thumbnail_url,
            displayAsset?.preview_thumbnail_url,
        ],
    )

    // Fetch unresolved incidents when display asset changes (Unified Operations)
    useEffect(() => {
        if (externalCollectionGuest || !displayAsset?.id || displayAsset.is_virtual_google_font) {
            setAssetIncidents([])
            setIncidentsLoading(false)
            return
        }
        setIncidentsLoading(true)
        window.axios.get(`/app/assets/${displayAsset.id}/incidents`)
            .then(res => {
                setAssetIncidents((res.data?.incidents ?? []).filter(Boolean))
            })
            .catch(() => setAssetIncidents([]))
            .finally(() => setIncidentsLoading(false))
    }, [displayAsset?.id, externalCollectionGuest])

    useEffect(() => {
        setBrandIntelActivityBanner(null)
    }, [displayAsset?.id])

    useEffect(() => {
        setPipelineDevSnap(null)
    }, [displayAsset?.id])

    // Reliability Timeline: fetch full incident history (resolved + unresolved) to decide if section is shown
    useEffect(() => {
        if (externalCollectionGuest || !displayAsset?.id || displayAsset.is_virtual_google_font) {
            setReliabilityTimeline([])
            setReliabilityTimelineLoading(false)
            return
        }
        setReliabilityTimelineLoading(true)
        window.axios.get(`/app/assets/${displayAsset.id}/incidents`, { params: { timeline: 1 } })
            .then(res => {
                setReliabilityTimeline((res.data?.incidents ?? []).filter(Boolean))
            })
            .catch(() => setReliabilityTimeline([]))
            .finally(() => setReliabilityTimelineLoading(false))
    }, [displayAsset?.id, externalCollectionGuest])

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
    /** Debounce rapid prev/next so we only hit /pdf-page for the page the user lands on */
    const pdfPageNavDebounceRef = useRef(null)
    const pdfPendingFetchPageRef = useRef(null)
    const pdfPageCacheRef = useRef({})
    useEffect(() => {
        pdfPageCacheRef.current = pdfPageCache
    }, [pdfPageCache])

    const [debugMode, setDebugMode] = useState(false)
    const [debugOverlayHold, setDebugOverlayHold] = useState(false)
    useEffect(() => {
        if (debugMode) {
            setDebugOverlayHold(true)
            return
        }
        const t = setTimeout(() => setDebugOverlayHold(false), 320)
        return () => clearTimeout(t)
    }, [debugMode])

    useEffect(() => {
        setDebugMode(false)
    }, [displayAsset?.id])

    // Phase V-1: Video view URL state (for gallery view)
    const [videoViewUrl, setVideoViewUrl] = useState(null)
    const [videoViewUrlLoading, setVideoViewUrlLoading] = useState(false)

    // Use displayAsset for carousel (with live updates)
    const currentCarouselAsset = imageAssets[carouselIndex] || displayAsset
    const canNavigateLeft = carouselIndex > 0
    const canNavigateRight = carouselIndex < imageAssets.length - 1

    useEffect(() => {
        setLightboxImageError(false)
    }, [currentCarouselAsset?.id, showZoomModal])

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
        if (pdfPageNavDebounceRef.current) {
            clearTimeout(pdfPageNavDebounceRef.current)
            pdfPageNavDebounceRef.current = null
        }
        pdfPendingFetchPageRef.current = null

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
            if (pdfPageNavDebounceRef.current) {
                clearTimeout(pdfPageNavDebounceRef.current)
                pdfPageNavDebounceRef.current = null
            }
        }
        // Re-fetch PDF page when asset updates (e.g. after Retry Processing completes and thumbnail_status becomes completed)
    }, [displayAsset?.id, displayAsset?.thumbnail_status?.value ?? displayAsset?.thumbnail_status ?? '', fetchPdfPage, isPdf])

    const scheduleDebouncedPdfFetch = useCallback((pageToFetch) => {
        pdfPendingFetchPageRef.current = pageToFetch
        if (pdfPageNavDebounceRef.current) {
            clearTimeout(pdfPageNavDebounceRef.current)
        }
        pdfPageNavDebounceRef.current = setTimeout(() => {
            pdfPageNavDebounceRef.current = null
            const page = pdfPendingFetchPageRef.current
            if (!page || !displayAsset?.id) return
            if (pdfPageCacheRef.current[page]) return
            fetchPdfPage(page)
        }, 220)
    }, [displayAsset?.id, fetchPdfPage])

    const handlePdfPageNavigate = useCallback((nextPage) => {
        if (!isPdf) return
        if (nextPage < 1 || nextPage > effectivePdfPageCount) return

        setPdfCurrentPage(nextPage)
        if (pdfPageCache[nextPage]) return
        scheduleDebouncedPdfFetch(nextPage)
    }, [effectivePdfPageCount, isPdf, pdfPageCache, scheduleDebouncedPdfFetch])

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

    useEffect(() => {
        if (!showZoomModal) {
            setShowLightboxDetails(false)
        }
    }, [showZoomModal])

    // Lightbox: lock page scroll; portal renders to document.body (Safari fixes fixed/overflow inside drawer)
    useEffect(() => {
        if (!showZoomModal) return
        const prev = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        return () => {
            document.body.style.overflow = prev
        }
    }, [showZoomModal])

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

    const isFontFile = useMemo(() => {
        if (!displayAsset || displayAsset.is_virtual_google_font) return false
        const mime = (displayAsset.mime_type || '').toLowerCase()
        const ext = (displayAsset.file_extension || displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        return mime.startsWith('font/') || ['woff2', 'woff', 'ttf', 'otf', 'eot'].includes(ext)
    }, [displayAsset])

    // Grid double-click: jump straight to fullscreen zoom (same modal as "Click to zoom" in drawer)
    useEffect(() => {
        if (!initialZoomOpen || !displayAsset?.id) return
        if (initialZoomAppliedRef.current) return
        if (!(hasThumbnailSupport || isVideo || isVirtualGoogleFont)) {
            initialZoomAppliedRef.current = true
            onInitialZoomConsumed?.()
            return
        }
        initialZoomAppliedRef.current = true
        const idx = imageAssets.findIndex((a) => a?.id === displayAsset.id)
        if (idx >= 0) {
            setCarouselIndex(idx)
        }
        setShowZoomModal(true)
        setShowLightboxDetails(false)
        onInitialZoomConsumed?.()
    }, [initialZoomOpen, displayAsset?.id, hasThumbnailSupport, isVideo, isVirtualGoogleFont, imageAssets, onInitialZoomConsumed])

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

    /** While the upload pipeline is still running, thumbnail_status often stays "pending" — hide manual Generate Preview to avoid duplicate jobs */
    const isAssetAnalysisPipelineRunning = useMemo(() => {
        const s = String(displayAsset?.analysis_status ?? '').toLowerCase()
        return s === 'uploading' || s === 'generating_thumbnails'
    }, [displayAsset?.analysis_status])

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

    // Check if user has permission to generate/retry thumbnails (can from usePermission at top of drawer)
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
        
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        return supportsThumbnail(mimeType, extension)
    }, [displayAsset, thumbnailStatus, canRetryThumbnails, generateLoading])

    const extForThumbnailUtils = useMemo(() => {
        const raw = (displayAsset?.file_extension || displayAsset?.original_filename?.split?.('.')?.pop() || '')
            .toLowerCase()
            .replace(/^\./, '')
        return raw
    }, [displayAsset?.file_extension, displayAsset?.original_filename, displayAsset?.id])

    const previewMissingDetailText = useMemo(() => {
        const meta = displayAsset?.metadata ?? {}
        return (
            displayAsset?.preview_unavailable_user_message ||
            meta.preview_unavailable_user_message ||
            meta.thumbnail_skip_message ||
            displayAsset?.thumbnail_error ||
            ''
        )
    }, [displayAsset?.id, displayAsset?.preview_unavailable_user_message, displayAsset?.metadata, displayAsset?.thumbnail_error])

    const showPreviewMissingInfo = useMemo(() => {
        if (!displayAsset?.id || isVirtualGoogleFont) return false
        if (isFontFile) return false
        const mime = (displayAsset?.mime_type || '').toLowerCase()
        if (!supportsThumbnail(mime, extForThumbnailUtils)) return false
        if ((displayAsset?.analysis_status ?? '') !== 'complete') return false
        const hasPath = Boolean(displayAsset?.final_thumbnail_url || displayAsset?.thumbnail_url)
        if (hasPath) return false
        if (thumbnailsFailed && displayAsset?.thumbnail_error) return false
        return true
    }, [
        displayAsset?.id,
        displayAsset?.analysis_status,
        displayAsset?.final_thumbnail_url,
        displayAsset?.thumbnail_url,
        displayAsset?.mime_type,
        displayAsset?.thumbnail_error,
        extForThumbnailUtils,
        isVirtualGoogleFont,
        isFontFile,
        thumbnailsFailed,
    ])

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
    
    
    // Phase B2: Restore from trash
    const handleRestoreFromTrash = async () => {
        if (!displayAsset?.id) return
        try {
            const response = await window.axios.post(`/app/assets/${displayAsset.id}/restore-from-trash`)
            if (response.data?.message === 'Asset restored successfully') {
                if (onAssetUpdate && response.data.asset) {
                    onAssetUpdate(response.data.asset)
                } else {
                    router.reload({ only: ['assets'] })
                }
                onClose()
            } else {
                setToastMessage(response.data?.message || 'Failed to restore')
                setToastType('error')
            }
        } catch (err) {
            setToastMessage(err.response?.data?.message || err.message || 'Failed to restore')
            setToastType('error')
        }
    }

    // Phase B2: Force delete (permanent) from trash
    const handleForceDeleteConfirm = async () => {
        if (!displayAsset?.id || forceDeleteConfirmText !== 'DELETE' || forceDeleteLoading) return
        setForceDeleteLoading(true)
        try {
            const response = await window.axios.delete(`/app/assets/${displayAsset.id}/force-delete`)
            if (response.data?.message === 'Asset permanently deleted') {
                setShowForceDeleteConfirm(false)
                setForceDeleteConfirmText('')
                onClose()
                router.reload({ only: ['assets'] })
            } else {
                setToastMessage(response.data?.message || 'Failed to permanently delete')
                setToastType('error')
            }
        } catch (err) {
            setToastMessage(err.response?.data?.message || err.message || 'Failed to permanently delete')
            setToastType('error')
        } finally {
            setForceDeleteLoading(false)
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
        
        const mimeType = (displayAsset.mime_type || '').toLowerCase()
        const extension = (displayAsset.original_filename?.split('.').pop() || '').toLowerCase()
        if (!supportsThumbnail(mimeType, extension)) {
            return false
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
        if (!supportsThumbnail(mimeType, extension)) {
            return 'Thumbnail generation is not supported for this file type'
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
            className="fixed inset-y-0 right-0 z-[130] w-full overflow-y-auto bg-white shadow-xl md:w-auto pb-[calc(env(safe-area-inset-bottom)+5rem)] md:pb-6" /* above AppNav dropdowns z-[100] / agency z-[60] / nav z-50 */
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
                    
                    {/* Phase 3.0C: Thumbnail preview with state machine and fade-in — responsive width */}
                    <div className="w-full max-w-full min-w-0 bg-gray-50 rounded-lg overflow-hidden border border-gray-200 relative aspect-video">
                        {ebiEnabledForAsset && displayAsset?.brand_intelligence && (
                            <button
                                type="button"
                                onClick={() => setDebugMode((v) => !v)}
                                className={`absolute right-2 top-2 z-40 rounded border px-2 py-1 text-xs font-medium shadow-sm backdrop-blur-sm pointer-events-auto transition-colors ${
                                    debugMode
                                        ? 'border-amber-400 bg-amber-50 text-amber-900'
                                        : 'border-slate-200 bg-white/90 text-slate-700 hover:bg-white'
                                }`}
                                aria-pressed={debugMode}
                            >
                                Debug
                            </button>
                        )}
                        {(debugMode || debugOverlayHold) && ebiEnabledForAsset && displayAsset?.brand_intelligence && (
                            <div
                                className={`pointer-events-none absolute inset-0 z-30 transition-opacity duration-300 ease-out ${
                                    debugMode ? 'opacity-100' : 'opacity-0'
                                }`}
                            >
                                <Suspense fallback={null}>
                                    <BrandDebugOverlay
                                        image={brandDebugPreviewUrl}
                                        debug={displayAsset.brand_intelligence.debug}
                                        enabled={debugMode}
                                    />
                                </Suspense>
                            </div>
                        )}
                        <div 
                            className={`relative w-full h-full transition-opacity duration-200 ${isLayoutSettling ? 'opacity-0' : 'opacity-100'}`}
                        >
                            {isVirtualGoogleFont ? (
                                <div className="flex h-full w-full flex-col items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 p-6 text-center">
                                    <span className="rounded-full bg-sky-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800">
                                        Google Fonts · hosted online
                                    </span>
                                    <p className="mt-3 max-w-sm text-xs text-slate-600">
                                        This family is loaded from Google&apos;s CDN for previews. There is no font file stored in your library—download and install from Google if you need the files locally.
                                    </p>
                                    <span
                                        className="mt-6 text-5xl font-semibold leading-none tracking-tight text-zinc-800"
                                        style={{
                                            fontFamily:
                                                virtualGoogleFontReady && displayAsset.google_font_family
                                                    ? `"${String(displayAsset.google_font_family).replace(/["\\\\]/g, '')}", ui-sans-serif, system-ui, sans-serif`
                                                    : 'ui-sans-serif, system-ui, sans-serif',
                                        }}
                                    >
                                        Aa
                                    </span>
                                    <p
                                        className="mt-4 max-w-[95%] text-lg font-medium text-slate-700"
                                        style={{
                                            fontFamily:
                                                virtualGoogleFontReady && displayAsset.google_font_family
                                                    ? `"${String(displayAsset.google_font_family).replace(/["\\\\]/g, '')}", ui-sans-serif, system-ui, sans-serif`
                                                    : 'ui-sans-serif, system-ui, sans-serif',
                                        }}
                                    >
                                        {displayAsset.title || displayAsset.google_font_family || 'Font'}
                                    </p>
                                    {displayAsset.google_font_role_label && (
                                        <p className="mt-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                                            {displayAsset.google_font_role_label}
                                        </p>
                                    )}
                                    {googleFontSpecimenUrl && (
                                        <a
                                            href={googleFontSpecimenUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-5 inline-flex items-center rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-700"
                                        >
                                            Open on Google Fonts
                                        </a>
                                    )}
                                </div>
                            ) : isVideo && displayAsset.id ? (
                                // Phase V-1: Video thumbnail with hover preview (same as other assets)
                                // Show thumbnail (icon > medium thumbnail) with hover video auto-play
                                <div
                                    className="w-full h-full cursor-pointer group relative"
                                    onClick={() => {
                                        // Open gallery view (zoom modal) for videos
                                        setShowZoomModal(true)
                                        setShowLightboxDetails(false)
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
                                            setShowLightboxDetails(false)
                                        }
                                    }}
                                    role={pdfPageCache[pdfCurrentPage] || pdfPageCache[1] ? 'button' : undefined}
                                    tabIndex={pdfPageCache[pdfCurrentPage] || pdfPageCache[1] ? 0 : undefined}
                                    onKeyDown={(e) => {
                                        if ((pdfPageCache[pdfCurrentPage] || pdfPageCache[1]) && (e.key === 'Enter' || e.key === ' ')) {
                                            e.preventDefault()
                                            setShowZoomModal(true)
                                            setShowLightboxDetails(false)
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
                                            setShowLightboxDetails(false)
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

                    {showPreviewMissingInfo && (
                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5">
                            <div className="flex gap-2">
                                <InformationCircleIcon className="h-5 w-5 shrink-0 text-slate-500" aria-hidden />
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold text-slate-800">No preview image</p>
                                    <p className="mt-1 text-xs text-slate-600">
                                        {previewMissingDetailText ||
                                            "We couldn't generate a preview image for this file (for example the file may be too large, unreadable on the server, or processing timed out). You can still download the original."}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

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
                    
                    {/* Phase B2: Deleted (trash) banner */}
                    {displayAsset.deleted_at && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-4 space-y-2">
                            <p className="text-sm font-medium text-red-800">
                                Deleted {Math.max(0, Math.floor((Date.now() - new Date(displayAsset.deleted_at).getTime()) / (1000 * 60 * 60 * 24)))} days ago
                            </p>
                            {(auth?.deletion_grace_period_days ?? 30) > 0 && (
                                <p className="text-xs text-red-700">
                                    Permanently deleted in {Math.max(0, (auth?.deletion_grace_period_days ?? 30) - Math.floor((Date.now() - new Date(displayAsset.deleted_at).getTime()) / (1000 * 60 * 60 * 24)))} days
                                </p>
                            )}
                        </div>
                    )}
                    {/* Lifecycle badges - Unpublished, Archived, and Expired (hidden when deleted) */}
                    {!displayAsset.deleted_at && (
                    <div className="flex flex-wrap gap-2">
                        {/* Reference material badge - builder-staged assets (Brand Guidelines uploads) */}
                        {displayAsset.builder_staged && (
                            <span className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-700 border border-slate-300">
                                Reference material
                            </span>
                        )}
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
                    )}
                </div>

                {/* Analytics/Metrics & Action Buttons */}
                <div className="border-t border-gray-200 pt-6 space-y-4">
                    {isVirtualGoogleFont ? (
                        <p className="text-sm text-gray-500">
                            View and download counts apply to files stored in your library. This Google Font is referenced from Brand Guidelines and is not stored as an asset file.
                        </p>
                    ) : (
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
                    )}
                    
                    {/* Action Buttons */}
                    {displayAsset?.id && (
                        <div className="space-y-2">
                            {/* Phase B2: When asset is in trash, show Restore and Permanently Delete */}
                            {displayAsset.deleted_at && (
                                <>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            onClick={handleRestoreFromTrash}
                                            className="flex-1 inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                                        >
                                            <ArrowUturnLeftIcon className="h-5 w-5 mr-2" />
                                            Restore
                                        </button>
                                        {(auth?.user?.tenant_role === 'owner' || auth?.user?.tenant_role === 'admin' || auth?.tenant_role === 'owner' || auth?.tenant_role === 'admin') && (
                                            <button
                                                type="button"
                                                onClick={() => { setShowForceDeleteConfirm(true); setForceDeleteConfirmText(''); }}
                                                className="inline-flex items-center justify-center rounded-md bg-white px-4 py-2.5 text-sm font-semibold text-red-700 shadow-sm ring-1 ring-inset ring-red-300 hover:bg-red-50"
                                            >
                                                Permanently Delete
                                            </button>
                                        )}
                                    </div>
                                </>
                            )}
                            {/* All lifecycle actions below hidden when asset is in trash */}
                            {!displayAsset.deleted_at && (
                            <>
                            {isVirtualGoogleFont ? (
                                <div className="space-y-3 rounded-lg border border-sky-100 bg-sky-50/80 p-4">
                                    <p className="text-sm text-slate-700">
                                        This font is listed from your Brand Guidelines (Google Fonts). Typography roles and families are edited in the guidelines builder.
                                    </p>
                                    <div className="flex flex-col gap-2">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShowZoomModal(true)
                                                setShowLightboxDetails(true)
                                            }}
                                            className="inline-flex w-full items-center justify-center rounded-md bg-gray-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-gray-700"
                                        >
                                            <EyeIcon className="h-4 w-4 mr-2" />
                                            Details
                                        </button>
                                        {googleFontSpecimenUrl && (
                                            <a
                                                href={googleFontSpecimenUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex w-full items-center justify-center rounded-md border border-sky-300 bg-white px-3 py-2.5 text-sm font-semibold text-sky-800 shadow-sm hover:bg-sky-50"
                                            >
                                                Open on Google Fonts
                                            </a>
                                        )}
                                        {auth?.activeBrand?.id && (
                                            <Link
                                                href={typeof route === 'function'
                                                    ? route('brands.brand-guidelines.builder', { brand: auth.activeBrand.id, step: 'standards' })
                                                    : `/app/brands/${auth.activeBrand.id}/brand-guidelines/builder?step=standards`}
                                                className="inline-flex w-full items-center justify-center rounded-md px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-95"
                                                style={{ backgroundColor: brandPrimary }}
                                            >
                                                Edit typography in Brand Guidelines
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            ) : (
                            <>
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

                            {/* Publish & categorize - for builder-staged reference materials (no category yet) */}
                            {displayAsset.builder_staged && canPublish && !displayAsset.archived_at && (
                                <button
                                    type="button"
                                    onClick={() => setShowFinalizeFromBuilderModal(true)}
                                    className="w-full inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    <CloudArrowUpIcon className="h-5 w-5 mr-2" />
                                    Publish & categorize
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
                                // 4. Asset is NOT builder-staged (those use Publish & categorize)
                                // 5. Contributors are blocked when approval is enabled
                                // 6. If asset is pending approval or rejected, only approvers can publish
                                const canShowPublishButton = canPublish && 
                                                             displayAsset.is_published === false && 
                                                             !displayAsset.archived_at &&
                                                             !displayAsset.builder_staged &&
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
                                                onClick={() => {
                                                    setShowZoomModal(true)
                                                    setShowLightboxDetails(true)
                                                }}
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
                                        {isFontFile && canSingleAssetDownload && (
                                            <p className="text-xs text-slate-600">
                                                By downloading, you confirm you have the right to use this font. {auth?.activeBrand?.name || 'This brand'} does not provide font licensing or redistribution; you are responsible for complying with the font license.
                                            </p>
                                        )}
                                    </div>
                                )
                            })()}
                            </>
                            )}
                            </>
                            )}
                        </div>
                    )}
                </div>

                {/* Phase B9: Metadata Candidate Review (moved up from bottom) */}
                {displayAsset?.id && !isVirtualGoogleFont && (
                    <MetadataCandidateReview
                        assetId={displayAsset.id}
                        primaryColor={brandPrimary}
                        uploadedByUserId={displayAsset.user_id}
                    />
                )}

                {/* Brand insight stack: AI suggested tags + reference promotion + Brand Intelligence (aligned, shared panel) */}
                {displayAsset?.id && !isVirtualGoogleFont &&
                    (can('brand_settings.manage') ||
                        displayAsset.category?.ebi_enabled === true ||
                        can('metadata.suggestions.view') ||
                        (isOwnUpload && can('metadata.edit_post_upload'))) && (
                    <div className="border-t border-gray-200 bg-gradient-to-b from-slate-50/80 to-white">
                        <div className="px-4 md:px-6 py-3 space-y-3">
                            {brandIntelActivityBanner && (
                                <div
                                    className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 shadow-sm"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <ArrowPathIcon
                                        className="h-3.5 w-3.5 flex-shrink-0 animate-spin text-slate-600"
                                        aria-hidden
                                    />
                                    <span>{brandIntelActivityBanner}</span>
                                </div>
                            )}
                            <AiTagSuggestionsInline
                                key={`ai-tags-${displayAsset.id}`}
                                assetId={displayAsset.id}
                                uploadedByUserId={displayAsset.user_id}
                                analysisStatus={displayAsset.analysis_status}
                                primaryColor={brandPrimary}
                                drawerInsightGroup
                            />
                            {can('brand_settings.manage') && showBrandReferenceCard && (
                                <div className="rounded-md border border-gray-200 bg-white p-3 shadow-sm">
                                    <div className="flex gap-2.5">
                                        <RectangleStackIcon
                                            className="h-4 w-4 text-slate-500 flex-shrink-0 mt-0.5"
                                            aria-hidden
                                        />
                                        <div className="min-w-0 flex-1">
                                            <h3 className="text-xs font-semibold text-gray-900">Use as a brand reference</h3>
                                            <p className="text-xs text-gray-600 mt-1 leading-snug">
                                                We surface this for assets that look like strong references: starred, rated above 3★,
                                                or with meaningful views or downloads. Promoting one helps Brand Intelligence learn
                                                what on-brand looks like—especially next to others in this category.
                                            </p>
                                            {displayAsset.reference_promotion ? (
                                                <div className="flex flex-wrap items-center gap-2 mt-2.5">
                                                    <span
                                                        className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ${
                                                            displayAsset.reference_promotion.kind === 'guideline'
                                                                ? 'bg-violet-100 text-violet-800'
                                                                : 'bg-sky-100 text-sky-800'
                                                        }`}
                                                    >
                                                        {displayAsset.reference_promotion.kind === 'guideline'
                                                            ? 'Guideline'
                                                            : 'Reference'}
                                                    </span>
                                                    {displayAsset.reference_promotion.category && (
                                                        <span className="text-xs text-gray-500">
                                                            {displayAsset.reference_promotion.category}
                                                        </span>
                                                    )}
                                                </div>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => setPromoteModalOpen(true)}
                                                    className="mt-2.5 inline-flex justify-center rounded-md px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                                    style={{
                                                        backgroundColor: brandPrimary,
                                                        ['--tw-ring-color']: brandPrimary,
                                                    }}
                                                >
                                                    Add reference
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                            <AssetBrandIntelligenceBlock
                                asset={displayAsset}
                                onAssetUpdate={onAssetUpdate}
                                primaryColor={brandPrimary}
                                drawerInsightGroup
                                onActivityBannerChange={setBrandIntelActivityBanner}
                            />
                        </div>
                    </div>
                )}

                {/* Tags and Metadata — virtual Google Fonts: read-only summary (no asset API id) */}
                {displayAsset?.id && isVirtualGoogleFont && (
                    <div className="border-t border-gray-200">
                        <CollapsibleSection title="Metadata" defaultExpanded>
                            <dl className="space-y-3 text-sm text-gray-700">
                                <div>
                                    <dt className="font-medium text-gray-900">Source</dt>
                                    <dd className="mt-0.5 text-gray-600">Google Fonts (referenced from Brand Guidelines; not stored as a file)</dd>
                                </div>
                                {(displayAsset.metadata?.fields?.font_role || displayAsset.google_font_role_label) && (
                                    <div>
                                        <dt className="font-medium text-gray-900">Typographic role</dt>
                                        <dd className="mt-0.5 text-gray-600">
                                            {displayAsset.google_font_role_label
                                                || (displayAsset.metadata?.fields?.font_role === 'body_copy'
                                                    ? 'Body'
                                                    : displayAsset.metadata?.fields?.font_role === 'headline'
                                                        ? 'Headline'
                                                        : displayAsset.metadata?.fields?.font_role)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <p className="mt-4 text-xs text-gray-500">
                                To edit font families and roles, use Brand Guidelines → Standards (typography).
                            </p>
                        </CollapsibleSection>
                    </div>
                )}

                {/* Tags and Metadata */}
                {displayAsset?.id && !isVirtualGoogleFont && (
                    <div className="border-t border-gray-200">
                        {!import.meta.env.PROD && (
                            <CollapsibleSection
                                title="Pipeline state (dev)"
                                defaultExpanded={false}
                                className="border-b border-amber-100/90 bg-amber-50/25"
                            >
                                {pipelineDevSnap ? (
                                    <div className="rounded border border-amber-300 bg-amber-50/90 p-3 font-mono text-xs text-amber-950">
                                        <pre className="whitespace-pre-wrap break-all">
                                            analysis_status: {pipelineDevSnap.analysisStatus}
                                            {'\n'}
                                            thumbnail_status: {pipelineDevSnap.thumbnailStatus}
                                            {'\n'}
                                            metadata_health:{' '}
                                            {pipelineDevSnap.metadataHealth
                                                ? JSON.stringify(pipelineDevSnap.metadataHealth)
                                                : 'null'}
                                        </pre>
                                    </div>
                                ) : (
                                    <p className="text-xs text-amber-900/80">Loading pipeline state…</p>
                                )}
                            </CollapsibleSection>
                        )}
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
                                onPipelineDebugStateChange={import.meta.env.PROD ? undefined : setPipelineDevSnap}
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
                                    key={`tag-manager-${displayAsset.id}`} 
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
                    <div className="fixed inset-0 z-[10055] overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                        <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                            <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity z-[10055]" onClick={() => setShowCollectionsModal(false)}></div>
                            <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                            <div className="relative inline-block align-bottom bg-white rounded-lg text-left shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full z-[10056]">
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
                {/* Show Regenerate/Generate only after pipeline has finished a first pass (not while analysis is still uploading/generating_thumbnails) */}
                {(thumbnailsSkipped ||
                    (thumbnailStatus === 'pending' && canGenerateThumbnail && !isAssetAnalysisPipelineRunning)) && (
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
                                                    : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:cr2'
                                                    ? 'Unsupported file type (Canon RAW / CR2)'
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
                                          displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:cr2' ||
                                          displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg') && 
                                         canRetryThumbnail && (
                                            <div className="mt-2">
                                                <p className="text-xs text-green-700 font-medium">
                                                    💡 {displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:svg' 
                                                        ? 'SVG support is now available (rasterized via Imagick).'
                                                        : displayAsset.metadata.thumbnail_skip_reason === 'unsupported_format:cr2'
                                                            ? 'CR2 support is now available (Imagick + ImageMagick RAW/LibRaw).'
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

                {/* Reliability Timeline (Unified Operations) — only when there are incidents */}
                {!reliabilityTimelineLoading && reliabilityTimeline.length > 0 && (
                <div className="border-t border-gray-200">
                    <CollapsibleSection
                        title="Reliability Timeline"
                        defaultExpanded={false}
                    >
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
                                            <p className="text-sm font-medium text-gray-900 break-words">{ev.title}</p>
                                            <ReliabilityTimelineIncidentMessage message={ev.message} />
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
                    </CollapsibleSection>
                </div>
                )}

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

            {/* Phase 3.1: Lightbox + optional details column (same content as former slide-out AssetDetailPanel) — portaled for Safari fixed/z-index inside overflow ancestors */}
            {showZoomModal && (hasThumbnailSupport || isVideo || displayAsset?.is_virtual_google_font) && (currentCarouselAsset?.id || displayAsset?.id) && typeof document !== 'undefined' && createPortal(
                <div
                    className="fixed inset-0 z-[10050] isolate flex min-h-0 w-full max-h-[100dvh] flex-col overflow-hidden md:flex-row md:items-stretch"
                    style={{ backgroundColor: 'rgb(0 0 0 / 0.92)' }}
                    onClick={() => setShowZoomModal(false)}
                >
                    <div
                        className={`relative order-1 flex min-h-0 min-w-0 flex-col overflow-hidden bg-black/90 md:self-stretch ${
                            showLightboxDetails ? 'flex-1 md:flex-[1_1_55%]' : 'flex-1'
                        }`}
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="absolute right-4 top-4 z-20 flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                            <button
                                type="button"
                                onClick={() => setShowLightboxDetails((v) => !v)}
                                className={`rounded-md px-3 py-2 text-sm font-semibold shadow-sm transition-colors ${
                                    showLightboxDetails
                                        ? 'bg-white text-gray-900 ring-2 ring-white'
                                        : 'bg-white/15 text-white hover:bg-white/25'
                                }`}
                                aria-pressed={showLightboxDetails}
                            >
                                Details
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowZoomModal(false)}
                                className="rounded-md p-2 text-white transition-colors hover:bg-white/10"
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-8 w-8" />
                            </button>
                        </div>

                        {canNavigateLeft && (
                            <button
                                type="button"
                                onClick={handlePrevious}
                                className="absolute left-4 top-1/2 z-10 -translate-y-1/2 rounded-full p-2 text-white transition-colors hover:bg-white/10"
                                aria-label="Previous asset"
                            >
                                <ChevronLeftIcon className="h-10 w-10" />
                            </button>
                        )}

                        {canNavigateRight && (
                            <button
                                type="button"
                                onClick={handleNext}
                                className="absolute right-4 top-1/2 z-10 -translate-y-1/2 rounded-full p-2 text-white transition-colors hover:bg-white/10"
                                aria-label="Next asset"
                            >
                                <ChevronRightIcon className="h-10 w-10" />
                            </button>
                        )}

                    <div 
                        className="relative flex h-full min-h-0 w-full min-w-0 flex-1 items-center justify-center overflow-hidden p-4"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* Phase V-1: Check if current asset is a video */}
                        {(() => {
                            if (currentCarouselAsset?.is_virtual_google_font) {
                                const fam = currentCarouselAsset.google_font_family || currentCarouselAsset.title
                                const specimen = currentCarouselAsset.google_font_specimen_url
                                    || (fam ? `https://fonts.google.com/specimen/${encodeURIComponent(fam)}` : null)
                                return (
                                    <div className="flex max-h-full w-full max-w-lg flex-col items-center justify-center rounded-xl bg-gradient-to-br from-slate-800 to-slate-900 p-8 text-center">
                                        <span className="rounded-full bg-sky-500/20 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-200">
                                            Google Fonts · hosted online
                                        </span>
                                        <span
                                            className="mt-6 text-6xl font-semibold text-white"
                                            style={{
                                                fontFamily:
                                                    virtualGoogleFontReady && fam
                                                        ? `"${String(fam).replace(/["\\\\]/g, '')}", ui-sans-serif, system-ui, sans-serif`
                                                        : 'ui-sans-serif, system-ui, sans-serif',
                                            }}
                                        >
                                            Aa
                                        </span>
                                        <p
                                            className="mt-4 text-xl font-medium text-white/90"
                                            style={{
                                                fontFamily:
                                                    virtualGoogleFontReady && fam
                                                        ? `"${String(fam).replace(/["\\\\]/g, '')}", ui-sans-serif, system-ui, sans-serif`
                                                        : 'ui-sans-serif, system-ui, sans-serif',
                                            }}
                                        >
                                            {currentCarouselAsset.title || fam}
                                        </p>
                                        {currentCarouselAsset.google_font_role_label && (
                                            <p className="mt-2 text-xs font-medium uppercase tracking-wider text-white/50">
                                                {currentCarouselAsset.google_font_role_label}
                                            </p>
                                        )}
                                        {specimen && (
                                            <a
                                                href={specimen}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="mt-8 inline-flex items-center rounded-md bg-sky-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-400"
                                            >
                                                Open on Google Fonts
                                            </a>
                                        )}
                                    </div>
                                )
                            }
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
                                        className="h-auto w-auto max-h-full max-w-full object-contain transition-all duration-300 ease-in-out bg-black"
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
                                // Raster preview URL — placeholder if missing or not displayable in-browser
                                const carouselImgUrl =
                                    currentCarouselAsset.thumbnail_url_large ??
                                    currentCarouselAsset.final_thumbnail_url ??
                                    currentCarouselAsset.thumbnail_url ??
                                    currentCarouselAsset.preview_thumbnail_url ??
                                    ''
                                const trimmedUrl = String(carouselImgUrl).trim()
                                if (!trimmedUrl || lightboxImageError) {
                                    return <LightboxPreviewPlaceholder asset={currentCarouselAsset} />
                                }
                                return (
                                    <img
                                        key={currentCarouselAsset.id}
                                        src={trimmedUrl}
                                        alt={currentCarouselAsset.title || currentCarouselAsset.original_filename || 'Asset preview'}
                                        className="h-auto w-auto max-h-full max-w-full object-contain transition-all duration-300 ease-in-out"
                                        style={{
                                            transform: transitionDirection === 'left'
                                                ? 'translateX(30px)'
                                                : transitionDirection === 'right'
                                                  ? 'translateX(-30px)'
                                                  : 'translateX(0)',
                                            opacity: transitionDirection ? 0 : 1,
                                        }}
                                        onError={() => setLightboxImageError(true)}
                                    />
                                )
                            }
                        })()}
                    </div>

                    <div className="pointer-events-none absolute bottom-8 left-1/2 z-10 -translate-x-1/2 transform">
                        {/* Safari: backdrop-blur + translucent bg can make caption text invisible; use solid bar + explicit white */}
                        <p
                            className="rounded-lg bg-black/80 px-4 py-2 text-center text-sm font-medium text-white shadow-lg"
                            style={{ WebkitFontSmoothing: 'antialiased' }}
                        >
                            {currentCarouselAsset.title || currentCarouselAsset.original_filename || 'Untitled Asset'}
                        </p>
                    </div>
                    </div>

                    {showLightboxDetails && currentCarouselAsset?.id && (
                        <div
                            className="order-2 flex max-h-[min(50vh,520px)] w-full min-h-0 flex-col overflow-hidden bg-neutral-950 md:max-h-none md:h-full md:min-h-0 md:w-[min(500px,50vw)] md:flex-shrink-0 md:self-stretch"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {currentCarouselAsset.is_virtual_google_font ? (
                                <div className="flex h-full flex-col gap-4 overflow-y-auto p-6 text-left text-white">
                                    <div>
                                        <h3 className="text-lg font-semibold text-white">About this font</h3>
                                        <p className="mt-2 text-sm text-white/70">
                                            This family is referenced from your Brand Guidelines (Google Fonts). It is loaded from Google&apos;s servers for previews; we do not store a font file in your asset library.
                                        </p>
                                    </div>
                                    {currentCarouselAsset.google_font_role_label && (
                                        <div>
                                            <p className="text-xs font-semibold uppercase tracking-wide text-white/40">Role in guidelines</p>
                                            <p className="mt-1 text-sm text-white/90">{currentCarouselAsset.google_font_role_label}</p>
                                        </div>
                                    )}
                                    {googleFontSpecimenUrl && (
                                        <a
                                            href={googleFontSpecimenUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center justify-center rounded-md border border-white/20 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white hover:bg-white/15"
                                        >
                                            Download / license on Google Fonts
                                        </a>
                                    )}
                                    {auth?.activeBrand?.id && (
                                        <Link
                                            href={typeof route === 'function'
                                                ? route('brands.brand-guidelines.builder', { brand: auth.activeBrand.id, step: 'standards' })
                                                : `/app/brands/${auth.activeBrand.id}/brand-guidelines/builder?step=standards`}
                                            className="inline-flex items-center justify-center rounded-md px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:opacity-95"
                                            style={{ backgroundColor: brandPrimary }}
                                        >
                                            Edit typography in Brand Guidelines
                                        </Link>
                                    )}
                                    <p className="text-xs text-white/45">
                                        To change roles (e.g. primary vs body) or swap families, use the typography step in Brand Guidelines.
                                    </p>
                                </div>
                            ) : (
                            <AssetDetailPanel
                                asset={currentCarouselAsset}
                                isOpen
                                embeddedInLightbox
                                onClose={() => setShowLightboxDetails(false)}
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
                        </div>
                    )}
                </div>,
                document.body,
            )}

            {/* Retry Confirmation Modal */}
            {showRetryModal && (
                <div className="fixed inset-0 z-[10060] bg-black/50 flex items-center justify-center p-4">
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

            {/* Publish & categorize modal - for builder-staged reference materials */}
            {showFinalizeFromBuilderModal && displayAsset?.id && (
                <div className="fixed inset-0 z-[10060] bg-black/50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center mb-4">
                            <CloudArrowUpIcon className="h-6 w-6 text-indigo-600 mr-3" />
                            <h3 className="text-lg font-semibold text-gray-900">Publish & categorize</h3>
                        </div>
                        <p className="text-sm text-gray-600 mb-4">
                            Choose a category for this reference material. It will be published and appear in the main asset grid.
                        </p>
                        <div className="mb-4">
                            <label className="block text-sm font-medium text-gray-700 mb-2">Category</label>
                            <select
                                value={finalizeCategoryId ?? ''}
                                onChange={(e) => setFinalizeCategoryId(e.target.value ? parseInt(e.target.value, 10) : null)}
                                className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option value="">Select a category...</option>
                                {(filterActiveCategories(categories)).map((cat) => (
                                    <option key={cat.id} value={cat.id}>{cat.name}</option>
                                ))}
                            </select>
                        </div>
                        <div className="flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => { setShowFinalizeFromBuilderModal(false); setFinalizeCategoryId(null) }}
                                disabled={finalizeLoading}
                                className="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={async () => {
                                    if (!finalizeCategoryId) return
                                    setFinalizeLoading(true)
                                    try {
                                        const response = await window.axios.post(`/app/assets/${displayAsset.id}/finalize-from-builder`, { category_id: finalizeCategoryId })
                                        if (response.data?.message) {
                                            setToastMessage('Asset published and categorized')
                                            setToastType('success')
                                            setTimeout(() => setToastMessage(null), 5000)
                                            setShowFinalizeFromBuilderModal(false)
                                            setFinalizeCategoryId(null)
                                            // Reload so asset leaves reference materials view and appears in main grid
                                            router.reload({ only: ['assets', 'next_page_url', 'reference_materials_count'], preserveState: true, preserveScroll: true })
                                            onClose?.()
                                        }
                                    } catch (err) {
                                        setToastMessage(err.response?.data?.message || 'Failed to publish asset')
                                        setToastType('error')
                                        setTimeout(() => setToastMessage(null), 5000)
                                    } finally {
                                        setFinalizeLoading(false)
                                    }
                                }}
                                disabled={finalizeLoading || !finalizeCategoryId}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            >
                                {finalizeLoading ? (
                                    <>
                                        <ArrowPathIcon className="h-4 w-4 mr-2 animate-spin" />
                                        Publishing...
                                    </>
                                ) : (
                                    <>
                                        <CheckIcon className="h-4 w-4 mr-2" />
                                        Publish & categorize
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Publish Confirmation Modal */}
            {showPublishModal && (
                <div className="fixed inset-0 z-[10060] bg-black/50 flex items-center justify-center p-4">
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
                                            accept={damUploadAccept}
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

            {promoteModalOpen && displayAsset?.id && (
                <PromoteBrandReferenceModal
                    isOpen={promoteModalOpen}
                    onClose={() => setPromoteModalOpen(false)}
                    assetId={displayAsset.id}
                    categories={categories}
                    defaultCategoryName={displayAsset?.category?.name ?? null}
                    onSuccess={(payload) => {
                        if (onAssetUpdate) {
                            onAssetUpdate({ ...displayAsset, ...payload })
                        }
                        const kind = payload?.reference_promotion?.kind
                        setToastMessage(
                            kind === 'guideline'
                                ? 'Added to brand guidelines as a creative reference'
                                : 'Added as a brand style reference',
                        )
                        setToastType('success')
                        setTimeout(() => setToastMessage(null), 4000)
                    }}
                />
            )}
            
            {/* Delete asset confirmation — portaled above lightbox (z-[10050]) so Safari/stacking never hides it */}
            {showDeleteConfirm && displayAsset && typeof document !== 'undefined' && createPortal(
                <div className="fixed inset-0 z-[10070] isolate overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div
                            className="fixed inset-0 z-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            onClick={() => !deleteLoading && setShowDeleteConfirm(false)}
                            aria-hidden
                        />
                        <div className="relative z-10 transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
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
                </div>,
                document.body,
            )}

            {/* Phase B2: Permanently delete from trash — portaled above lightbox */}
            {showForceDeleteConfirm && displayAsset && typeof document !== 'undefined' && createPortal(
                <div className="fixed inset-0 z-[10070] isolate overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div
                            className="fixed inset-0 z-0 bg-gray-500 bg-opacity-75 transition-opacity"
                            onClick={() => !forceDeleteLoading && (setShowForceDeleteConfirm(false), setForceDeleteConfirmText(''))}
                            aria-hidden
                        />
                        <div className="relative z-10 transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="sm:flex sm:items-start">
                                <div className="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                    <ExclamationTriangleIcon className="h-6 w-6 text-red-600" />
                                </div>
                                <div className="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900">
                                        Permanently delete asset?
                                    </h3>
                                    <p className="mt-2 text-sm text-gray-500">
                                        This cannot be undone. Type <strong>DELETE</strong> to confirm.
                                    </p>
                                    <input
                                        type="text"
                                        value={forceDeleteConfirmText}
                                        onChange={(e) => setForceDeleteConfirmText(e.target.value)}
                                        placeholder="DELETE"
                                        className="mt-3 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
                                        autoFocus
                                    />
                                </div>
                            </div>
                            <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse gap-2">
                                <button
                                    type="button"
                                    onClick={handleForceDeleteConfirm}
                                    disabled={forceDeleteConfirmText !== 'DELETE' || forceDeleteLoading}
                                    className="inline-flex w-full justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    {forceDeleteLoading ? 'Deleting…' : 'Permanently Delete'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => !forceDeleteLoading && (setShowForceDeleteConfirm(false), setForceDeleteConfirmText(''))}
                                    disabled={forceDeleteLoading}
                                    className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>,
                document.body,
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

/**
 * AssetCard Component
 * 
 * Displays a single asset in the grid view.
 * Shows thumbnail preview, title, and file type badge.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with title, thumbnail_url, file_extension, etc.
 * @param {Function} props.onClick - Optional click handler to open asset detail drawer
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {boolean} props.isSelected - Whether this asset is currently selected
 * @param {string} props.primaryColor - Brand primary color for selected highlight
 * @param {string} props.cardStyle - 'default' | 'guidelines' — guidelines = flat tiles, label below (color-tile style), hover shadow
 */
import { useMemo, useState, useEffect, useRef, useSyncExternalStore, useCallback } from 'react'
import { usePage } from '@inertiajs/react'
import { useSelectionOptional } from '../contexts/SelectionContext'
import { TrashIcon } from '@heroicons/react/24/outline'
import { PauseIcon, PlayIcon, StarIcon } from '@heroicons/react/24/solid'
import ThumbnailPreview from './ThumbnailPreview'
import ExecutionPresentationFrame from './execution/ExecutionPresentationFrame'
import AudioCardVisual from './Audio/AudioCardVisual'
import { audioMasonryWrapperStyle } from '../utils/audioCardSizing'
import { isImageLikeForAssetCard } from '../utils/damFileTypes'
import { getThumbnailVersion, getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
import {
    getExecutionGridHoverCrossfadeUrl,
    resolveExecutionGridThumbnail,
} from '../utils/executionThumbnailDisplay'
import {
    assetCardEnhancedExecutionChromeClass,
    isExecutionEnhancedGridMode,
} from '../utils/assetCardEnhancedExecutionChrome'
import {
    getUploadPreviewSnapshotForAsset,
    subscribeUploadPreviewRegistry,
} from '../utils/uploadPreviewRegistry'
import { getAssetCardVisualState } from '../utils/assetCardVisualState'

/** Grid video tile: same m:ss (or h:mm:ss) chip as {@link AudioCardVisual} duration overlay. */
function formatVideoDurationForCard(seconds) {
    const n = Number(seconds)
    if (!Number.isFinite(n) || n <= 0) return null
    const total = Math.round(n)
    const m = Math.floor(total / 60)
    const s = total % 60
    if (m >= 60) {
        const h = Math.floor(m / 60)
        const mm = m % 60
        return `${h}:${String(mm).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    }
    return `${m}:${String(s).padStart(2, '0')}`
}

function searchTokensForHighlight(q) {
    if (!q || typeof q !== 'string') return []
    return q
        .toLowerCase()
        .trim()
        .split(/\s+/)
        .map((t) => t.replace(/^"|"$/g, ''))
        .filter((t) => t.length >= 2)
}

export default function AssetCard({
    asset,
    onClick = null,
    /** Double-click / double-tap: e.g. open fullscreen zoom without relying on single-click timing */
    onDoubleClick = null,
    showInfo = true,
    isSelected = false,
    primaryColor = '#6366f1',
    isBulkSelected = false,
    onBulkSelect = null,
    isInBucket = false,
    /** Download bucket: parent binds asset id; call with pointer event (e.g. e.shiftKey for range select). */
    onBucketToggle = null,
    isPendingApprovalMode = false,
    isPendingPublicationFilter = false,
    onAssetApproved = null,
    cardVariant = 'default',
    cardStyle = 'default',
    /** Column width preference (px); used to size masonry placeholders like uniform grid tiles */
    cardSize = 220,
    selectionAssetType = 'asset',
    layoutMode = 'grid',
    masonryMaxHeightPx = 560,
    /** Deliverables grid: standard | enhanced | presentation — null disables mode-aware thumbnails */
    executionThumbnailViewMode = null,
    gridSearchQuery = '',
    /** Uniform grid (Assets): 'cover' | 'contain' — passed from View menu; undefined uses ThumbnailPreview defaults */
    gridImageFit = undefined,
    /** Public share: title + extension row under thumb (main asset grid style); hides ext pill on image */
    splitTitleFooter = false,
}) {
    const { auth } = usePage().props
    /** Brand Guidelines Google Fonts (no DAM file) — grid preview only, no drawer */
    const isVirtualGoogleFont = Boolean(asset?.is_virtual_google_font)
    const [googleFontReady, setGoogleFontReady] = useState(false)

    useEffect(() => {
        if (!isVirtualGoogleFont || !asset?.google_font_stylesheet_url) {
            return undefined
        }
        const url = asset.google_font_stylesheet_url
        const elId = `google-font-sheet-${String(asset.id).replace(/[^a-z0-9_-]/gi, '-')}`
        if (document.getElementById(elId)) {
            setGoogleFontReady(true)
            return undefined
        }
        const link = document.createElement('link')
        link.id = elId
        link.rel = 'stylesheet'
        link.href = url
        link.crossOrigin = 'anonymous'
        const done = () => setGoogleFontReady(true)
        link.onload = done
        link.onerror = done
        document.head.appendChild(link)
        return undefined
    }, [isVirtualGoogleFont, asset?.id, asset?.google_font_stylesheet_url])

    // Extract file extension from original_filename, file_extension, or mime_type
    const getFileExtension = () => {
        if (isVirtualGoogleFont) {
            return 'GOOGLE'
        }
        // First try explicit file_extension field
        if (asset.file_extension && asset.file_extension.trim()) {
            return asset.file_extension.toUpperCase()
        }
        
        // Then try to extract from original_filename
        if (asset.original_filename) {
            const parts = asset.original_filename.split('.')
            if (parts.length > 1) {
                const ext = parts[parts.length - 1].trim()
                if (ext) {
                    return ext.toUpperCase()
                }
            }
        }
        
        // Fallback: derive from mime_type
        if (asset.mime_type) {
            // Extract from mime types like "image/jpeg" -> "JPEG", "application/pdf" -> "PDF"
            const mimeParts = asset.mime_type.split('/')
            if (mimeParts.length === 2) {
                const mimeSubtype = mimeParts[1].toLowerCase()
                // Map common mime types to extensions
                const mimeToExt = {
                    'jpeg': 'JPG',
                    'jpg': 'JPG',
                    'png': 'PNG',
                    'gif': 'GIF',
                    'webp': 'WEBP',
                    'svg+xml': 'SVG',
                    'tiff': 'TIF',
                    'x-canon-cr2': 'CR2',
                    'bmp': 'BMP',
                    'pdf': 'PDF',
                    'zip': 'ZIP',
                    'x-zip-compressed': 'ZIP',
                    'mpeg': 'MPG',
                    'mp4': 'MP4',
                    'quicktime': 'MOV',
                    'x-msvideo': 'AVI',
                    'vnd.adobe.photoshop': 'PSD',
                    'vnd.adobe.illustrator': 'AI',
                }
                if (mimeToExt[mimeSubtype]) {
                    return mimeToExt[mimeSubtype]
                }
                // Fallback: use mime subtype uppercase
                return mimeSubtype.split('+')[0].toUpperCase()
            }
        }
        
        // Last resort: return generic "FILE" (should rarely happen)
        return 'FILE'
    }
    
    const fileExtension = getFileExtension()
    
    // Determine if asset is an image based on mime_type or extension
    const extLower = fileExtension.toLowerCase()
    const isImage = isImageLikeForAssetCard(asset.mime_type, extLower)

    // Phase V-1: Detect if asset is a video
    const videoExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v']
    const isVideo = Boolean(asset?.mime_type?.startsWith('video/') || videoExtensions.includes(extLower))

    // Audio: separate visual track (waveform PNG + duration overlay) — see
    // AudioCardVisual. Mirrors the video branch but does not need hover
    // preview / poster frame plumbing because audio has no still frames.
    const audioExtensions = ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac', 'weba']
    const isAudio = Boolean(asset?.mime_type?.startsWith('audio/') || audioExtensions.includes(extLower))
    /** Poster or preview frame — when absent, ThumbnailPreview shows the frosted play placeholder (no double overlay). */
    const videoHasPosterFrame = Boolean(asset?.final_thumbnail_url || asset?.preview_thumbnail_url)

    const thumbnailForceObjectFit = useMemo(() => {
        if (layoutMode === 'grid' && (gridImageFit === 'cover' || gridImageFit === 'contain')) {
            return gridImageFit
        }
        return isVideo ? 'cover' : null
    }, [layoutMode, gridImageFit, isVideo])

    const highlightTokens = useMemo(() => searchTokensForHighlight(gridSearchQuery), [gridSearchQuery])
    const videoSummary =
        typeof asset?.metadata?.ai_video_insights?.summary === 'string'
            ? asset.metadata.ai_video_insights.summary
            : ''
    const videoTags = Array.isArray(asset?.metadata?.ai_video_insights?.tags) ? asset.metadata.ai_video_insights.tags : []
    const aiVideoStatus = asset?.metadata?.ai_video_status

    /** Raster thumbnails (PDF, PSD, …) are not “image-like” for card chrome but still need execution grid modes. */
    const supportsExecutionGridThumbnailMode =
        supportsThumbnail(asset.mime_type, extLower) && !isVideo

    const fontExtensions = ['woff2', 'woff', 'ttf', 'otf', 'eot']
    const isFontMime = Boolean(asset?.mime_type?.startsWith('font/') || fontExtensions.includes(extLower))

    const categorySlug = asset?.category?.slug
    const isCampaignCollectionFont = Boolean(asset?.is_campaign_collection_font)
    const isCampaignFontRole =
        categorySlug === 'fonts' && String(asset?.metadata?.fields?.font_role ?? '').toLowerCase() === 'campaign'
    const showFontSwatch =
        isVirtualGoogleFont ||
        categorySlug === 'fonts' ||
        (isFontMime && !isImage && !isVideo)

    // Phase V-1: Hover preview state (desktop only)
    const [isHovering, setIsHovering] = useState(false)
    // Phase D1: Card hover for bucket checkbox visibility
    // Phase 2 Selection: unified selection store (checkbox checked state from SelectionContext)
    const selection = useSelectionOptional()
    const [isCardHovering, setIsCardHovering] = useState(false)
    const [previewLoaded, setPreviewLoaded] = useState(false)
    const [executionThumbHover, setExecutionThumbHover] = useState(false)
    /** Hover preview URL failed (e.g. unsupported format) — keep showing poster */
    const [videoPreviewFailed, setVideoPreviewFailed] = useState(false)
    const videoPreviewRef = useRef(null)
    const isMobile = typeof window !== 'undefined' ? window.innerWidth < 768 : false
    /** Ignore synthetic click after a touch scroll/drag on the card */
    const TAP_MOVE_THRESHOLD_PX = 14
    const touchStartRef = useRef(null)
    const touchStartPosRef = useRef(null)
    const touchScrollCancelledRef = useRef(false)
    const suppressSyntheticClickUntilRef = useRef(0)
    const touchHandledRef = useRef(false)
    const lastTapRef = useRef(0)
    const lastTapAssetIdRef = useRef(null)
    /** When onDoubleClick is set, single click is delayed so dblclick can cancel the drawer open */
    const cardClickDelayRef = useRef(null)
    
    // Phase 3.1: Derive stable thumbnail version signal
    // This ensures memoized components re-render when thumbnail availability changes
    // after background reconciliation. Recompute only when asset id or thumbnailVersion changes.
    const thumbnailVersion = useMemo(() => getThumbnailVersion(asset), [
        asset?.id,
        asset?.thumbnail_url,
        asset?.thumbnail_status?.value || asset?.thumbnail_status,
        asset?.updated_at,
    ])
    
    // Phase 3.1: Get thumbnail state for processing badge
    // Recompute only when asset id or thumbnailVersion changes (same as thumbnailVersion memo)
    const thumbnailState = useMemo(() => getThumbnailState(asset), [
        asset?.id,
        thumbnailVersion,
    ])

    const aiVideoBusy =
        isVideo && ['queued', 'processing'].includes(String(aiVideoStatus || ''))

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

    /** Re-render when per-asset "Standard" preference changes (localStorage + custom event). */
    const [preferredTierBump, setPreferredTierBump] = useState(0)
    useEffect(() => {
        if (executionThumbnailViewMode !== 'standard') {
            return undefined
        }
        const onChange = () => setPreferredTierBump((n) => n + 1)
        window.addEventListener('jackpot_preferred_thumbnail_tier_changed', onChange)
        return () => window.removeEventListener('jackpot_preferred_thumbnail_tier_changed', onChange)
    }, [executionThumbnailViewMode])

    const executionThumbResolved = useMemo(() => {
        if (executionThumbnailViewMode == null || !supportsExecutionGridThumbnailMode || showFontSwatch) {
            return null
        }
        if (thumbnailState.state !== 'AVAILABLE') {
            return null
        }
        return resolveExecutionGridThumbnail(asset, executionThumbnailViewMode, 'medium')
    }, [
        executionThumbnailViewMode,
        supportsExecutionGridThumbnailMode,
        showFontSwatch,
        thumbnailState.state,
        asset,
        preferredTierBump,
    ])

    const executionDisplayUrl = executionThumbResolved?.imageUrl ?? null
    const executionUsePresentationCss = Boolean(executionThumbResolved?.usePresentationCss)
    const executionPresentationPreset = executionThumbResolved?.presentationPreset ?? null

    const executionHoverUrl = useMemo(() => {
        if (!executionDisplayUrl || executionThumbnailViewMode == null) {
            return null
        }
        return getExecutionGridHoverCrossfadeUrl(asset, executionThumbnailViewMode, 'medium')
    }, [asset, executionThumbnailViewMode, executionDisplayUrl, preferredTierBump])

    const uploadPreviewSnapshot = useSyncExternalStore(
        subscribeUploadPreviewRegistry,
        () => getUploadPreviewSnapshotForAsset(asset?.id),
        () => getUploadPreviewSnapshotForAsset(asset?.id),
    )
    const ephemeralLocalPreviewUrl = useMemo(() => {
        const sep = '\u0001'
        const i = uploadPreviewSnapshot.indexOf(sep)
        if (i < 0) return null
        const url = uploadPreviewSnapshot.slice(i + sep.length)
        return url.length > 0 ? url : null
    }, [uploadPreviewSnapshot])

    const cardVisualState = useMemo(
        () => getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl }),
        [asset, ephemeralLocalPreviewUrl],
    )

    const showExecutionDualThumb = Boolean(executionDisplayUrl && executionHoverUrl)
    const showExecutionSingleThumb = Boolean(executionDisplayUrl && !executionHoverUrl)
    const isExecutionEnhancedGrid = isExecutionEnhancedGridMode(executionThumbnailViewMode)
    const executionEnhancedChromeClass = assetCardEnhancedExecutionChromeClass(executionThumbnailViewMode)

    const hasRasterThumbnailUrl =
        Boolean(thumbnailState.thumbnailUrl) || Boolean(ephemeralLocalPreviewUrl)
    const isExecutionThumbVisual = showExecutionDualThumb || showExecutionSingleThumb

    const [videoPreviewPlaying, setVideoPreviewPlaying] = useState(false)

    const videoDurationLabel = useMemo(() => {
        if (!isVideo) return null
        return formatVideoDurationForCard(
            asset?.video_duration ?? asset?.metadata?.video?.duration_seconds,
        )
    }, [isVideo, asset?.video_duration, asset?.metadata?.video?.duration_seconds])

    const handleVideoCardPlayClick = useCallback(
        (e) => {
            e.preventDefault()
            e.stopPropagation()
            const v = videoPreviewRef.current
            if (v && previewLoaded && !videoPreviewFailed) {
                if (v.paused) {
                    void v.play().catch(() => {})
                } else {
                    v.pause()
                }
                return
            }
            if (!isMobile && asset?.video_preview_url) {
                setIsHovering(true)
            }
        },
        [previewLoaded, videoPreviewFailed, isMobile, asset?.video_preview_url],
    )

    // Audio cards are fully painted by AudioCardVisual (gradient + waveform +
    // chrome) — they do not want the generic "no thumbnail yet" full-bleed
    // placeholder, badge, or processing pill stacked on top.
    const useFullBleedPlaceholder =
        !showFontSwatch && !isExecutionThumbVisual && !hasRasterThumbnailUrl && !isAudio
    const suppressPlaceholderDuplicateBadge =
        useFullBleedPlaceholder && supportsThumbnail(asset?.mime_type, extLower)

    // Get appropriate icon for non-image files
    const getFileIcon = () => {
        if (extLower === 'pdf') {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        } else if (['psd', 'psb', 'ai', 'eps', 'sketch'].includes(extLower)) {
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h11.25c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
            )
        } else {
            // Generic document icon
            return (
                <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )
        }
    }
    
    // UX invariant: Click on card always opens drawer. Only the checkbox adds/removes from download bucket.
    // Mobile: tap = toggle selection, long-press = open detail (best practice for grid selection)
    // Double-tap = open details (works regardless of selection; prevents context menu on long-press)
    const LONG_PRESS_MS = 400
    const DOUBLE_TAP_MS = 350
    const hasSelection = Boolean(onBulkSelect || (selection || onBucketToggle))
    const handleTouchStart = (e) => {
        if (!onClick) return
        const t = e.touches?.[0]
        if (t) {
            touchStartPosRef.current = { x: t.clientX, y: t.clientY }
            touchScrollCancelledRef.current = false
        }
        if (!isMobile || !hasSelection) return
        touchStartRef.current = Date.now()
        touchHandledRef.current = false
    }
    const handleTouchMove = (e) => {
        if (!onClick || !touchStartPosRef.current) return
        const t = e.touches?.[0]
        if (!t) return
        const dx = Math.abs(t.clientX - touchStartPosRef.current.x)
        const dy = Math.abs(t.clientY - touchStartPosRef.current.y)
        if (dx > TAP_MOVE_THRESHOLD_PX || dy > TAP_MOVE_THRESHOLD_PX) {
            touchScrollCancelledRef.current = true
        }
    }
    const handleTouchCancel = () => {
        touchStartRef.current = null
        touchStartPosRef.current = null
        touchScrollCancelledRef.current = false
    }
    const handleTouchEnd = (e) => {
        if (!onClick) return

        if (touchScrollCancelledRef.current) {
            touchStartPosRef.current = null
            touchStartRef.current = null
            touchScrollCancelledRef.current = false
            suppressSyntheticClickUntilRef.current = Date.now() + 450
            return
        }
        touchStartPosRef.current = null

        if (!isMobile) return

        const now = Date.now()
        const isDoubleTap = asset.id === lastTapAssetIdRef.current && (now - lastTapRef.current) <= DOUBLE_TAP_MS
        lastTapRef.current = now
        lastTapAssetIdRef.current = asset.id

        if (isDoubleTap) {
            touchHandledRef.current = true
            touchStartRef.current = null
            if (onDoubleClick) {
                onDoubleClick(asset, e)
            } else {
                onClick(asset, e)
            }
            return
        }

        if (!hasSelection) {
            return
        }

        if (!touchStartRef.current) return
        const duration = Date.now() - touchStartRef.current
        touchStartRef.current = null

        if (duration >= LONG_PRESS_MS) {
            touchHandledRef.current = true
            onClick(asset, e)
        } else {
            touchHandledRef.current = true
            if (onBulkSelect) {
                onBulkSelect()
            } else if (onBucketToggle) {
                onBucketToggle()
            } else if (selection) {
                selection.toggleItem({
                    id: asset.id,
                    type: selectionAssetType,
                    name: asset.title ?? asset.original_filename ?? '',
                    thumbnail_url: asset.final_thumbnail_url ?? asset.thumbnail_url ?? asset.preview_thumbnail_url ?? null,
                    category_id: asset.metadata?.category_id ?? asset.category_id ?? null,
                })
            }
        }
    }
    const handleClick = (e) => {
        if (Date.now() < suppressSyntheticClickUntilRef.current) {
            e.preventDefault()
            e.stopPropagation()
            return
        }
        if (
            typeof window !== 'undefined' &&
            window.__assetGridMarqueeSuppressClickUntil > Date.now()
        ) {
            e.preventDefault()
            e.stopPropagation()
            return
        }
        if (isMobile && hasSelection && touchHandledRef.current) {
            touchHandledRef.current = false
            e.preventDefault()
            e.stopPropagation()
            return
        }
        if (!onClick) return
        if (onDoubleClick) {
            if (cardClickDelayRef.current) clearTimeout(cardClickDelayRef.current)
            cardClickDelayRef.current = setTimeout(() => {
                cardClickDelayRef.current = null
                if (
                    typeof window !== 'undefined' &&
                    window.__assetGridMarqueeSuppressClickUntil > Date.now()
                ) {
                    return
                }
                onClick(asset, e)
            }, 280)
            return
        }
        onClick(asset, e)
    }

    const handleDoubleClick = (e) => {
        if (!onDoubleClick) return
        if (cardClickDelayRef.current) {
            clearTimeout(cardClickDelayRef.current)
            cardClickDelayRef.current = null
        }
        onDoubleClick(asset, e)
    }

    useEffect(() => () => {
        if (cardClickDelayRef.current) {
            clearTimeout(cardClickDelayRef.current)
        }
    }, [])
    const handleContextMenu = (e) => {
        if (isMobile) e.preventDefault()
    }

    // Convert hex color to RGB for shadow opacity
    const hexToRgb = (hex) => {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : { r: 99, g: 102, b: 241 } // Default indigo-500
    }
    
    const rgb = hexToRgb(primaryColor)
    const shadowStyle = isSelected ? {
        borderColor: primaryColor,
        boxShadow: `0 10px 15px -3px rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.3), 0 4px 6px -2px rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.2), 0 0 0 1px ${primaryColor}`,
    } : {}
    
    const isCinematic = cardVariant === 'cinematic'
    const isGuidelines = cardStyle === 'guidelines'
    /** Public share: frosted chrome on the image only; filename row sits on the page (no grey caption tray). */
    const cinematicThumbOnly = isCinematic && splitTitleFooter
    const showOuterCinematicChrome = isCinematic && !cinematicThumbOnly
    const cardBgClass = isGuidelines
        ? 'bg-transparent'
        : cinematicThumbOnly
          ? 'bg-transparent'
          : isCinematic
            ? 'bg-white/10 backdrop-blur-md'
            : 'bg-transparent'
    // Default: outline wraps image only, not title. Guidelines + full-bleed cinematic wrap the whole card.
    const cardBorderClass = isGuidelines
        ? 'border-0'
        : isCinematic
            ? (isSelected ? 'border-2 border-white/60' : 'border-white/20 hover:border-white/40')
            : (isSelected ? 'border-2' : 'border-gray-200 hover:border-gray-300')
    const cardShadowClass = isGuidelines
        ? 'shadow-none' // Guidelines: shadow lives on image only (see image container)
        : isCinematic ? 'shadow-lg hover:shadow-xl' : 'shadow-none'
    const defaultThumbBorderTint =
        isSelected
            ? 'border-2'
            : useFullBleedPlaceholder
              ? 'border-transparent hover:border-gray-300/50'
              : 'border-gray-200 hover:border-gray-300'
    const imageBorderClass = !isGuidelines && !isCinematic
        ? `rounded-2xl border transition-all duration-200 ${defaultThumbBorderTint}`
        : isGuidelines && isSelected
          ? 'rounded-2xl border-2 transition-all duration-200'
          : cinematicThumbOnly
            ? `rounded-2xl border transition-all duration-200 ${cardBorderClass}`
            : ''
    const imageShadowClass = !isGuidelines && !isCinematic
        ? (isSelected ? '' : 'shadow-md group-hover:shadow-lg')
        : isGuidelines
          ? 'shadow-none group-hover:shadow-lg'
          : cinematicThumbOnly
            ? cardShadowClass
            : ''
    const aspectRatio = isGuidelines ? 'aspect-[5/3]' : 'aspect-[4/3]' // More elongated for guidelines
    const isMasonry = layoutMode === 'masonry'
    /** Masonry: min thumbnail height matches grid tile (same column width × same aspect ratio). */
    const masonryThumbnailMinHeightPx = useMemo(() => {
        if (!isMasonry) return undefined
        const w = Math.max(160, Math.min(600, Number(cardSize) || 220))
        return isGuidelines ? Math.round((w * 3) / 5) : Math.round((w * 3) / 4)
    }, [isMasonry, isGuidelines, cardSize])

    /** Light checkerboard so white/light marks stay visible (logos + graphics; CSS-only). */
    /** Applies in both grid styles: "impact" (default card) and "clean" (guidelines — white tile would hide white logos). */
    const isLogoOrGraphicCategory = categorySlug === 'logos' || categorySlug === 'graphics'
    const checkerboardThumbnailStyle =
        isLogoOrGraphicCategory && !isCinematic && !cinematicThumbOnly
            ? {
                  backgroundColor: '#f3f4f6',
                  backgroundImage:
                      'repeating-conic-gradient(#e5e7eb 0% 25%, #ffffff 0% 50%)',
                  backgroundSize: '12px 12px',
              }
            : undefined

    return (
        <div
            onClick={handleClick}
            onDoubleClick={handleDoubleClick}
            onTouchStart={handleTouchStart}
            onTouchMove={handleTouchMove}
            onTouchEnd={handleTouchEnd}
            onTouchCancel={handleTouchCancel}
            onContextMenu={handleContextMenu}
            onMouseEnter={() => setIsCardHovering(true)}
            onMouseLeave={() => setIsCardHovering(false)}
            draggable={false}
            onDragStart={(e) => e.preventDefault()}
            className={`group relative select-none ${cardBgClass} rounded-2xl transition-all duration-200 cursor-pointer overflow-visible flex flex-col ${
                !isGuidelines && !isCinematic ? '' : isGuidelines || showOuterCinematicChrome ? `border ${cardBorderClass} ${cardShadowClass}` : ''
            }`}
            style={{
                ...(isCinematic && !cinematicThumbOnly ? shadowStyle : {}),
                '--primary-color': primaryColor,
            }}
        >
            {/* Phase 3.1: Thumbnail — uniform aspect (grid) or natural height capped (masonry) */}
            {/* Default + guidelines: outline wraps image only. Full cinematic: border on outer card; public share + splitTitleFooter: border on this thumb only. */}
            <div 
                className={`${
                    /* Masonry: center content vertically in min-height tile (short logos vs tall neighbors) */
                    isMasonry ? 'w-full flex flex-col items-center justify-center' : aspectRatio
                } relative overflow-hidden rounded-2xl transition-all duration-200 ${imageBorderClass} ${imageShadowClass} ${executionEnhancedChromeClass} ${isGuidelines ? (isLogoOrGraphicCategory ? 'bg-transparent shadow-none group-hover:shadow-lg' : useFullBleedPlaceholder ? 'bg-transparent shadow-none group-hover:shadow-lg' : 'bg-white shadow-none group-hover:shadow-lg') : isCinematic ? 'bg-black/20 backdrop-blur-[2px]' : isLogoOrGraphicCategory ? 'bg-transparent' : useFullBleedPlaceholder ? 'bg-transparent' : 'bg-gray-50'}`}
                style={{
                    ...(isMasonry
                        ? {
                              maxHeight: masonryMaxHeightPx,
                              minHeight: masonryThumbnailMinHeightPx,
                          }
                        : {}),
                    ...((!isGuidelines && !isCinematic) || isGuidelines || cinematicThumbOnly ? shadowStyle : {}),
                    ...checkerboardThumbnailStyle,
                }}
                onMouseEnter={() => !isMobile && isVideo && setIsHovering(true)}
                onMouseLeave={() => {
                    setIsHovering(false)
                    setPreviewLoaded(false)
                    setVideoPreviewFailed(false)
                    setVideoPreviewPlaying(false)
                    setExecutionThumbHover(false)
                    // Pause and unload preview on mouse leave
                    if (videoPreviewRef.current) {
                        videoPreviewRef.current.pause()
                        videoPreviewRef.current.currentTime = 0
                    }
                }}
            >
                {isExecutionEnhancedGrid && (
                    <span
                        className="pointer-events-none absolute left-2 top-2 z-10 select-none text-sm opacity-80 drop-shadow-sm"
                        title={
                            executionThumbnailViewMode === 'presentation'
                                ? 'Presentation (CSS presets)'
                                : executionThumbnailViewMode === 'ai'
                                  ? 'AI view thumbnails'
                                  : 'Studio grid thumbnails'
                        }
                        aria-hidden
                    >
                        ✨
                    </span>
                )}
                {showFontSwatch ? (
                    <div className="absolute inset-0 flex flex-col items-center justify-center bg-gradient-to-br from-slate-50 to-slate-100 text-zinc-800">
                        <span
                            className="text-[2.75rem] font-semibold leading-none tracking-tight"
                            style={{
                                fontFamily:
                                    isVirtualGoogleFont && googleFontReady && asset.google_font_family
                                        ? `"${String(asset.google_font_family).replace(/["\\\\]/g, '')}", ui-sans-serif, system-ui, sans-serif`
                                        : 'ui-sans-serif, system-ui, sans-serif',
                            }}
                        >
                            Aa
                        </span>
                        <p className="mt-3 max-w-[95%] px-3 text-center text-sm font-medium text-slate-600 line-clamp-2">
                            {asset.title || asset.original_filename || 'Font'}
                        </p>
                        {isVirtualGoogleFont && asset.google_font_role_label && (
                            <p className="mt-1 px-3 text-center text-[10px] uppercase tracking-wider text-slate-500">
                                {asset.google_font_role_label}
                            </p>
                        )}
                        {!isVirtualGoogleFont && isCampaignFontRole && (
                            <p className="mt-1 px-3 text-center text-[10px] uppercase tracking-wider text-violet-700">
                                Campaign
                            </p>
                        )}
                    </div>
                ) : (
                    <>
                        {/* Phase V-1: Video hover preview (desktop only, lazy load) */}
                        {isVideo && isHovering && asset.video_preview_url && !isMobile && !videoPreviewFailed && (
                            <div className="absolute inset-0 z-10 overflow-hidden bg-black">
                                <video
                                    ref={videoPreviewRef}
                                    src={asset.video_preview_url}
                                    className="absolute inset-0 h-full w-full object-cover"
                                    autoPlay
                                    muted
                                    loop
                                    playsInline
                                    onLoadedData={() => setPreviewLoaded(true)}
                                    onError={() => {
                                        setVideoPreviewFailed(true)
                                        setVideoPreviewPlaying(false)
                                    }}
                                    onPlay={() => setVideoPreviewPlaying(true)}
                                    onPause={() => setVideoPreviewPlaying(false)}
                                    style={{
                                        opacity: previewLoaded ? 1 : 0,
                                        transition: 'opacity 0.2s',
                                    }}
                                />
                            </div>
                        )}

                        {isAudio ? (
                            // Masonry: AudioCardVisual paints itself with `h-full`, but the
                            // parent div only sets `min-height` (no explicit height) so a
                            // pure-CSS child with `height:100%` resolves to 0 — the same
                            // issue ThumbnailPreview works around by setting an explicit
                            // `height` for its placeholder. We wrap with that same target
                            // height so the audio waveform actually has a tile to paint
                            // into instead of collapsing and exposing the parent
                            // `bg-gray-50` (which read as a white card on staging).
                            (() => {
                                const wrapStyle = audioMasonryWrapperStyle({
                                    isMasonry,
                                    masonryThumbnailMinHeightPx,
                                    masonryMaxHeightPx,
                                })
                                const child = (
                                    <AudioCardVisual
                                        asset={asset}
                                        primaryColor={primaryColor}
                                        className="w-full h-full"
                                    />
                                )
                                return wrapStyle ? (
                                    <div className="w-full" style={wrapStyle}>{child}</div>
                                ) : child
                            })()
                        ) : showExecutionDualThumb || showExecutionSingleThumb ? (
                            executionUsePresentationCss && executionPresentationPreset ? (
                                <div
                                    className={`relative flex h-full w-full items-center justify-center overflow-hidden rounded-2xl ${
                                        isMasonry ? 'max-h-full min-h-0' : ''
                                    }`}
                                >
                                    <ExecutionPresentationFrame
                                        imageUrl={executionDisplayUrl}
                                        preset={executionPresentationPreset}
                                        className="rounded-2xl"
                                    />
                                </div>
                            ) : (
                                <div
                                    className={`relative flex h-full w-full items-center justify-center ${
                                        isMasonry ? 'max-h-full min-h-0' : ''
                                    }`}
                                    onMouseEnter={() => !isMobile && showExecutionDualThumb && setExecutionThumbHover(true)}
                                    onMouseLeave={() => setExecutionThumbHover(false)}
                                >
                                    <img
                                        src={executionDisplayUrl}
                                        alt=""
                                        className={`max-h-full max-w-full object-contain transition-opacity duration-200 ${
                                            showExecutionDualThumb && executionThumbHover ? 'opacity-0' : 'opacity-100'
                                        }`}
                                        draggable={false}
                                    />
                                    {showExecutionDualThumb ? (
                                        <img
                                            src={executionHoverUrl}
                                            alt=""
                                            className={`absolute inset-0 m-auto max-h-full max-w-full object-contain transition-opacity duration-200 ${
                                                executionThumbHover ? 'opacity-100' : 'opacity-0'
                                            }`}
                                            draggable={false}
                                        />
                                    ) : null}
                                </div>
                            )
                        ) : (
                            <>
                                {/* Phase V-1: Use ThumbnailPreview for videos (same as drawer) */}
                                <ThumbnailPreview
                                    asset={asset}
                                    alt={asset.title || asset.original_filename || (isVideo ? 'Video' : 'Asset')}
                                    className={`${
                                        /* Masonry: no `display:block` — ThumbnailPreview uses flex to center img in min-height box */
                                        isMasonry
                                            ? 'w-full max-h-full min-h-0'
                                            : 'w-full h-full'
                                    } ${isHovering && isVideo && asset.video_preview_url && !isMobile && previewLoaded && !videoPreviewFailed ? 'opacity-0' : 'opacity-100'} transition-opacity duration-200`}
                                    retryCount={0}
                                    onRetry={null}
                                    size="lg"
                                    thumbnailVersion={thumbnailVersion}
                                    shouldAnimateThumbnail={shouldAnimateThumbnail}
                                    primaryColor={primaryColor}
                                    forceObjectFit={thumbnailForceObjectFit}
                                    masonryMaxHeight={isMasonry ? masonryMaxHeightPx : null}
                                    masonryMinHeight={isMasonry ? masonryThumbnailMinHeightPx : null}
                                    ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                                />
                            </>
                        )}

                        {/* Video grid: bottom-left play/pause + bottom-right duration (matches AudioCardVisual) */}
                        {isVideo && videoHasPosterFrame && !isExecutionThumbVisual ? (
                            <>
                                {!isMobile && asset?.video_preview_url ? (
                                    <button
                                        type="button"
                                        onClick={handleVideoCardPlayClick}
                                        className="absolute bottom-2 left-2 z-30 flex h-9 w-9 items-center justify-center rounded-full bg-black/45 text-white shadow-lg ring-1 ring-white/25 backdrop-blur-md transition-transform duration-150 hover:scale-105 hover:bg-black/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                        aria-label={videoPreviewPlaying ? 'Pause video preview' : 'Play video preview'}
                                        aria-pressed={videoPreviewPlaying}
                                    >
                                        {videoPreviewPlaying ? (
                                            <PauseIcon className="h-4 w-4" />
                                        ) : (
                                            <PlayIcon className="h-4 w-4 translate-x-[1px]" />
                                        )}
                                    </button>
                                ) : null}
                                {videoDurationLabel ? (
                                    <span
                                        className={`pointer-events-none absolute z-30 rounded-md bg-black/55 px-2 py-0.5 font-mono text-[11px] font-medium text-white/95 shadow-sm backdrop-blur-md ${
                                            aiVideoBusy ? 'bottom-9 right-2' : 'bottom-2 right-2'
                                        }`}
                                    >
                                        {videoDurationLabel}
                                    </span>
                                ) : null}
                            </>
                        ) : null}

                        {!isVirtualGoogleFont &&
                            !showFontSwatch &&
                            !suppressPlaceholderDuplicateBadge &&
                            cardVisualState.kind !== 'ready' &&
                            cardVisualState.kind !== 'local_preview' &&
                            cardVisualState.badgeShort && (
                                <div className="pointer-events-none absolute bottom-2 left-1/2 z-[5] max-w-[calc(100%-1rem)] -translate-x-1/2">
                                    <span
                                        className={`inline-block max-w-full truncate rounded-md px-2 py-0.5 text-center text-[10px] font-semibold shadow-md ring-1 ring-black/10 ${
                                            cardVisualState.badgeTone === 'danger'
                                                ? 'bg-red-600 text-white'
                                                : cardVisualState.badgeTone === 'warning'
                                                  ? 'bg-amber-100 text-amber-950'
                                                  : 'bg-violet-100 text-violet-900'
                                        }`}
                                        title={`${cardVisualState.label} — ${cardVisualState.description}`}
                                    >
                                        {cardVisualState.badgeShort}
                                    </span>
                                </div>
                            )}
                        {!isVirtualGoogleFont && aiVideoBusy && cardVisualState.kind === 'ready' ? (
                            <div className="pointer-events-none absolute bottom-2 right-2 z-[5]">
                                <span
                                    className="rounded-md bg-black/60 px-1.5 py-0.5 text-[9px] font-medium text-white shadow-sm"
                                    title="Video insights are running in the background"
                                >
                                    Video AI
                                </span>
                            </div>
                        ) : null}
                    </>
                )}
                
                {/* Phase 2 – Step 7: Bulk selection checkbox */}
                {!isVirtualGoogleFont && onBulkSelect && (
                    <div
                        data-help="asset-selection-checkbox"
                        className={`absolute top-2 left-2 z-10 flex items-center justify-center transition-all duration-150 ease-out ${isBulkSelected ? 'scale-105' : 'scale-100'}`}
                    >
                        <div
                            className={`inline-flex items-center justify-center rounded p-0 leading-none transition-all duration-150 ease-out ${
                                isBulkSelected ? 'bg-[var(--primary-color)]' : 'bg-white'
                            } ${isBulkSelected ? 'ring-2 ring-[var(--primary-color)] ring-offset-0' : ''}`}
                            style={{ '--primary-color': primaryColor }}
                        >
                            <input
                                type="checkbox"
                                checked={isBulkSelected}
                                onChange={(e) => {
                                    e.stopPropagation()
                                    onBulkSelect()
                                }}
                                onClick={(e) => e.stopPropagation()}
                                className={`block h-4 w-4 min-h-0 min-w-0 shrink-0 aspect-square rounded p-0 m-0 text-indigo-600 focus:ring-indigo-500 cursor-pointer bg-white shadow-sm transition-all duration-150 ${
                                    isBulkSelected ? 'border-[var(--primary-color)]' : 'border-gray-300'
                                }`}
                                style={{ '--primary-color': primaryColor }}
                            />
                        </div>
                    </div>
                )}

                {/* Phase D1/D3: Download bucket checkbox. SelectionContext is source of truth. Show when selection exists (or legacy onBucketToggle). */}
                {!isVirtualGoogleFont && !onBulkSelect && (selection || onBucketToggle) && (
                    <div
                        data-help="asset-selection-checkbox"
                        className={`absolute top-2 left-2 z-10 flex items-center justify-center transition-all duration-150 ease-out ${isMobile || isCardHovering || (onBucketToggle ? isInBucket : (selection?.isSelected(asset.id) ?? false)) ? 'opacity-100' : 'opacity-0'} ${(onBucketToggle ? isInBucket : (selection?.isSelected(asset.id) ?? false)) ? 'scale-105' : 'scale-100'}`}
                    >
                        <div
                            className={`inline-flex items-center justify-center rounded p-0 leading-none transition-all duration-150 ease-out ${
                                (onBucketToggle ? isInBucket : (selection?.isSelected(asset.id) ?? false)) ? 'bg-[var(--primary-color)]' : 'bg-white'
                            } ${(onBucketToggle ? isInBucket : (selection?.isSelected(asset.id) ?? false)) ? 'ring-2 ring-[var(--primary-color)] ring-offset-0' : ''}`}
                            style={{ '--primary-color': primaryColor }}
                        >
                            <input
                                type="checkbox"
                                checked={onBucketToggle ? isInBucket : (selection ? selection.isSelected(asset.id) : false)}
                                onChange={() => {}}
                                onClick={(e) => {
                                    e.stopPropagation()
                                    if (onBucketToggle) {
                                        onBucketToggle(e)
                                    } else if (selection) {
                                        selection.toggleItem({
                                            id: asset.id,
                                            type: selectionAssetType,
                                            name: asset.title ?? asset.original_filename ?? '',
                                            thumbnail_url: asset.final_thumbnail_url ?? asset.thumbnail_url ?? asset.preview_thumbnail_url ?? null,
                                            category_id: asset.metadata?.category_id ?? asset.category_id ?? null,
                                        })
                                    }
                                }}
                                className={`block h-4 w-4 min-h-0 min-w-0 shrink-0 aspect-square rounded p-0 m-0 cursor-pointer bg-white shadow-sm transition-all duration-150 ${
                                    (onBucketToggle ? isInBucket : (selection?.isSelected(asset.id) ?? false)) ? 'border-[var(--primary-color)]' : 'border-gray-300'
                                }`}
                                style={{ accentColor: primaryColor, '--primary-color': primaryColor }}
                                aria-label={(onBucketToggle ? isInBucket : (selection ? selection.isSelected(asset.id) : false)) ? 'Remove from download' : 'Add to download'}
                            />
                        </div>
                    </div>
                )}

                {/* Top-right badges: file type + star + trash + guideline */}
                <div className="absolute top-2 right-2 z-10 flex flex-col gap-1 items-end">
                    <div className="inline-flex items-center gap-1.5">
                        {showInfo && !isGuidelines && !splitTitleFooter && (
                            <span className="inline-flex items-center rounded-md bg-black/60 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white uppercase tracking-wide">
                                {fileExtension}
                            </span>
                        )}
                        {showInfo && !isGuidelines && isVideo && (
                            <span className="inline-flex items-center rounded-md bg-violet-700/85 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white">
                                Video
                            </span>
                        )}
                        {/* Starred: gold for visibility on varied image backgrounds (brand primary was too dark) */}
                        {asset.starred === true && (
                            <StarIcon className="h-3.5 w-3.5 text-amber-400 drop-shadow-[0_1px_2px_rgba(0,0,0,0.8)]" aria-label="Featured" />
                        )}
                        {/* Phase B2: Trash badge when asset is deleted */}
                        {asset.deleted_at && (
                            <span className="inline-flex items-center rounded-md bg-red-600/90 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white" title="In trash">
                                <TrashIcon className="h-3 w-3" aria-label="In trash" />
                            </span>
                        )}
                        {!asset.deleted_at && asset.reference_promotion?.kind === 'guideline' && (
                            <span className="inline-flex items-center rounded-md bg-violet-600/90 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white" title="Brand guideline reference">
                                Guideline
                            </span>
                        )}
                        {isCampaignCollectionFont && (
                            <span
                                className="inline-flex items-center rounded-md bg-violet-700/90 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white"
                                title="Campaign identity font (not the master brand library default)"
                            >
                                Campaign
                            </span>
                        )}
                        {isVirtualGoogleFont && (
                            <span
                                className="inline-flex items-center rounded-md bg-sky-600/90 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white"
                                title={
                                    isCampaignCollectionFont
                                        ? 'Google Fonts (from a collection’s campaign identity)'
                                        : 'From Brand Guidelines (Google Fonts)'
                                }
                            >
                                Google Fonts
                            </span>
                        )}
                        {!isVirtualGoogleFont && isCampaignFontRole && (
                            <span
                                className="inline-flex items-center rounded-md bg-violet-700/90 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white"
                                title="Filed under Fonts with role Campaign"
                            >
                                Campaign
                            </span>
                        )}
                    </div>
                </div>
            </div>
            
            {/* Title section - Conditionally hidden based on showInfo prop */}
            {showInfo && (
                isGuidelines ? (
                    <div className="mt-3 px-4 flex items-center justify-between gap-2 min-w-0">
                        <span className="text-xs font-medium truncate text-gray-900">
                            {asset.title || asset.original_filename || 'Untitled Asset'}
                        </span>
                        <span className="font-mono text-[10px] font-medium text-gray-600 flex-shrink-0">
                            {fileExtension}
                        </span>
                    </div>
                ) : splitTitleFooter ? (
                    <div className={`mt-3 min-w-0 ${isCinematic ? 'px-0.5' : ''}`}>
                        <div className="flex items-start justify-between gap-2 min-w-0">
                            <span
                                className={`min-w-0 flex-1 text-xs font-medium leading-snug line-clamp-2 transition-colors duration-200 group-hover:text-[var(--primary-color)] ${
                                    isCinematic ? 'text-white drop-shadow-sm' : 'text-gray-900'
                                }`}
                            >
                                {asset.title || asset.original_filename || 'Untitled Asset'}
                            </span>
                            <span
                                className={`shrink-0 font-mono text-[10px] font-semibold tabular-nums uppercase tracking-wide ${
                                    isCinematic ? 'text-white/90' : 'text-gray-500'
                                }`}
                            >
                                {fileExtension}
                            </span>
                        </div>
                        {isVideo && videoSummary && gridSearchQuery.trim() && (
                            <p
                                className={`mt-1 line-clamp-2 text-xs ${
                                    isCinematic ? 'text-white/75' : 'text-gray-500'
                                }`}
                            >
                                {videoSummary}
                            </p>
                        )}
                        {isVideo && highlightTokens.length > 0 && videoTags.length > 0 && (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {videoTags.slice(0, 8).map((tag, idx) => {
                                    const label = String(tag)
                                    const lower = label.toLowerCase()
                                    const hit = highlightTokens.some((t) => lower.includes(t))
                                    return (
                                        <span
                                            key={`${label}-${idx}`}
                                            className={`max-w-full truncate rounded px-1 text-[10px] ${
                                                hit
                                                    ? 'bg-yellow-100 text-gray-900'
                                                    : isCinematic
                                                      ? 'bg-white/15 text-white/90'
                                                      : 'bg-gray-100 text-gray-600'
                                            }`}
                                        >
                                            {label}
                                        </span>
                                    )
                                })}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className={`p-3 pt-2 ${isCinematic ? 'border-t border-white/20' : 'mt-1'}`}>
                        <h3 
                            className={`text-sm font-medium truncate transition-colors duration-200 group-hover:text-[var(--primary-color)] ${isCinematic ? 'text-white drop-shadow-sm' : 'text-gray-900'}`}
                        >
                            {asset.title || asset.original_filename || 'Untitled Asset'}
                        </h3>
                        {isVideo && videoSummary && gridSearchQuery.trim() && (
                            <p
                                className={`mt-1 line-clamp-2 text-xs ${
                                    isCinematic ? 'text-white/75' : 'text-gray-500'
                                }`}
                            >
                                {videoSummary}
                            </p>
                        )}
                        {isVideo && highlightTokens.length > 0 && videoTags.length > 0 && (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {videoTags.slice(0, 8).map((tag, idx) => {
                                    const label = String(tag)
                                    const lower = label.toLowerCase()
                                    const hit = highlightTokens.some((t) => lower.includes(t))
                                    return (
                                        <span
                                            key={`${label}-${idx}`}
                                            className={`max-w-full truncate rounded px-1 text-[10px] ${
                                                hit
                                                    ? 'bg-yellow-100 text-gray-900'
                                                    : isCinematic
                                                      ? 'bg-white/15 text-white/90'
                                                      : 'bg-gray-100 text-gray-600'
                                            }`}
                                        >
                                            {label}
                                        </span>
                                    )
                                })}
                            </div>
                        )}
                    </div>
                )
            )}
            
            {/* Mobile: subtle hint when selected — long-press or double-tap opens details */}
            {isMobile && hasSelection && onClick && (selection ? selection.isSelected(asset.id) : isBulkSelected || isInBucket) && (
                <div className="absolute bottom-0 left-0 right-0 py-1.5 px-2 flex justify-center pointer-events-none md:hidden">
                    <span className="inline-flex items-center gap-1 rounded-full bg-black/50 backdrop-blur-sm px-2.5 py-1 text-[10px] font-medium text-white/90">
                        Hold or double-tap for details
                    </span>
                </div>
            )}

            {/* Phase L.6.2: Visual indicator for unpublished assets - user clicks asset to open drawer */}
            {isPendingApprovalMode && (
                <div className="absolute inset-0 bg-black/20 backdrop-blur-sm flex items-center justify-center z-10 pointer-events-none">
                    <div className="bg-yellow-100 border-2 border-yellow-400 rounded-lg px-3 py-2 shadow-lg">
                        <span className="text-xs font-medium text-yellow-800">Click to view & publish</span>
                    </div>
                </div>
            )}
        </div>
    )
}

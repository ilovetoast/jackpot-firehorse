/**
 * ThumbnailPreview Component
 * 
 * Renders asset thumbnails with strict priority: final > preview > icon
 * 
 * 🔒 THUMBNAIL SYSTEM LOCK:
 * This system is intentionally NON-REALTIME. Thumbnails do NOT auto-update in the grid.
 * Users must refresh the page to see final thumbnails after processing completes.
 * This design prioritizes stability over real-time updates.
 * 
 * HARD STABILIZATION: In grid context, thumbnails are snapshot-only by design.
 * Grid thumbnails are locked on first render and never update after mount.
 * This prevents flicker and re-render thrash.
 * 
 * Live thumbnail updates are ONLY enabled in AssetDrawer context via useDrawerThumbnailPoll.
 * 
 * Terminal state guarantees:
 * - Spinner ONLY shows when thumbnail_status === PROCESSING
 * - Terminal states (COMPLETED, FAILED, SKIPPED) NEVER show spinners
 * - Final thumbnail existence blocks spinner display
 * 
 * TODO (future): Allow manual thumbnail regeneration per asset.
 * TODO (future): Consider websocket-based thumbnail update broadcasting.
 * TODO (future): Consider thumbnail_version field for live UI refresh.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object
 * @param {string} props.alt - Alt text for image
 * @param {string} props.className - Additional CSS classes
 * @param {number} props.retryCount - Retry count (UI only)
 * @param {string} props.size - Size variant ('sm', 'md', 'lg')
 * @param {number|null} props.thumbnailVersion - Thumbnail version (ignored in grid context)
 * @param {boolean} props.shouldAnimateThumbnail - Whether to animate thumbnail appearance
 * @param {string|null} props.primaryColor - Brand primary color for placeholder
 * @param {string|null} props.forceObjectFit - Force object-fit value ('cover' or 'contain'), overrides category-based logic
 * @param {number|null} props.masonryMaxHeight - When set, image sizes to natural aspect ratio up to this max height (masonry grid)
 * @param {number|null} props.masonryMinHeight - Masonry: min height for placeholders / empty states (match grid tile)
 * @param {boolean} props.preferLargeForVector - When true, use 'large' style (4096px) for SVG/vector assets in detail view for crisp rendering
 * @param {string|null} props.forcedImageUrl - When set, render this URL instead of locked final/preview (drawer preview mode)
 * @param {boolean} props.forcedImageSpinnerOverlay - Full-area spinner overlay on forced image (e.g. preferred pipeline processing)
 * @param {boolean} [props.useFocalPoint=true] — When true, `object-fit: cover` uses asset focal point (`metadata`) for `object-position`. Ignored for contain / masonry / small thumbs.
 */
import { useState, useEffect, useRef, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
import { trackImageLoad } from '../utils/performanceTracking'
import { analyzeLogoLightOnWhiteRisk } from '../utils/imageUtils'
import {
    effectiveFocalPointFromAsset,
    focalPointObjectPositionStyle,
    mergeImageStyle,
} from '../utils/guidelinesFocalPoint'
import FileTypeIcon from './FileTypeIcon'
import AssetPlaceholder from './AssetPlaceholder'
import { getAssetPlaceholderTheme } from '../utils/getAssetPlaceholderTheme.js'

// Cache of thumbnail URLs that have failed (404, etc.) - prevents retrying across instances
// Enables graceful fallback when thumbnails are processing or missing from S3
const failedThumbnailUrls = new Set()

/**
 * LQIP URL for image assets only (tiny blurred preview on S3).
 * PDF/video/docs may still have backend preview paths; grid keeps file-type / poster behavior for those.
 */
function lqipPreviewUrlForAsset(asset) {
    const mime = (asset?.mime_type || '').toLowerCase()
    if (!mime.startsWith('image/')) {
        return null
    }
    return asset?.preview_thumbnail_url ?? null
}

export default function ThumbnailPreview({
    asset,
    alt = 'Asset',
    className = '',
    retryCount = 0,
    size = 'md',
    thumbnailVersion = null,
    shouldAnimateThumbnail = false,
    primaryColor = null,
    forceObjectFit = null,
    masonryMaxHeight = null,
    masonryMinHeight = null,
    preferLargeForVector = false,
    forcedImageUrl = null,
    forcedImageSpinnerOverlay = false,
    useFocalPoint = true,
    /** Session blob URL until server preview/final exists (asset grid only). */
    ephemeralLocalPreviewUrl = null,
}) {
    const { auth } = usePage().props
    /**
     * Grid chrome passes `primaryColor` from getWorkspaceButtonColor (can be white/black/context).
     * Placeholder hue + AssetProcessingPlaceholder must anchor to **brand primary** when available
     * so Collections, Assets, and drawer tiles match the same family as nav / Add buttons (see brand settings).
     */
    const placeholderBrandPrimary =
        auth?.activeBrand?.primary_color || primaryColor || '#6366f1'

    const placeholderSurfaceStyle = useMemo(
        () =>
            getAssetPlaceholderTheme(asset, {
                primary_color: placeholderBrandPrimary,
                accent_color: auth?.activeBrand?.accent_color ?? auth?.activeBrand?.secondary_color,
            }).surfaceStyle,
        [asset, placeholderBrandPrimary, auth?.activeBrand?.accent_color, auth?.activeBrand?.secondary_color],
    )
    const effectiveForced =
        typeof forcedImageUrl === 'string' && forcedImageUrl.length > 0 ? forcedImageUrl : null
    const [imageLoaded, setImageLoaded] = useState(false)
    const [imageError, setImageError] = useState(false)
    /** Last successfully shown forced URL; kept visible while the next URL preloads (mode switch). */
    const [forcedStableUrl, setForcedStableUrl] = useState(null)
    const [forcedPendingUrl, setForcedPendingUrl] = useState(null)
    const forcedFailedOnceRef = useRef(new Set())
    const [isAnimating, setIsAnimating] = useState(false)
    const animationCompletedRef = useRef(false)
    const imgRef = useRef(null)

    // HARD STABILIZATION: Lock thumbnail URL on first render for grid context
    // Once a thumbnail is visible, it never reloads or flashes.
    // NOTE: Thumbnails intentionally do NOT live-update on the grid.
    // Stability > real-time updates.
    // Live thumbnail upgrades can be reintroduced later via explicit user action (refresh / reopen page).
    
    // DRAWER CONTEXT: If thumbnailVersion is provided, allow live updates
    // This enables drawer previews to update when new thumbnails are generated
    const isDrawerContext = thumbnailVersion !== null

    // For SVG/vector assets in detail view: use 'large' style (4096px) for crisp rendering
    const isSvg = asset?.mime_type === 'image/svg+xml' ||
        (asset?.original_filename?.toLowerCase().endsWith('.svg') || asset?.file_extension === 'svg')
    const useLargeForVector = preferLargeForVector && isSvg && asset?.id
    const effectiveFinalUrl = asset?.final_thumbnail_url && useLargeForVector
        ? (asset?.thumbnail_url_large ?? asset?.final_thumbnail_url)
        : asset?.final_thumbnail_url

    // SVG fallback: when no rasterized WebP thumbnail exists, use the original SVG file
    // (vectors render natively in <img>). Prefer the crisp vector over LQIP blobs — for SVG
    // an LQIP is usually an inferior preview and, worse, often 404s before the thumbnail job finishes.
    const lqipUrl = lqipPreviewUrlForAsset(asset)
    const hasLocalUploadBlob =
        typeof ephemeralLocalPreviewUrl === 'string' && ephemeralLocalPreviewUrl.length > 0
    /**
     * Early server LQIP often races S3/derivatives (404 or blank) while the session still has a
     * reliable blob from the uploader. Prefer that blob on the grid until final_thumbnail_url exists.
     * Without this, preview_thumbnail_url blocks ephemeral and the grid stays on “Processing preview”.
     */
    const serverLqipBlocksEphemeral = Boolean(
        lqipUrl &&
            !failedThumbnailUrls.has(lqipUrl) &&
            !(hasLocalUploadBlob && !effectiveFinalUrl),
    )

    const svgOriginalFallback = isSvg && !effectiveFinalUrl
        ? (asset?.original || null)
        : null

    // Lock the URL on first render for grid, but allow updates for drawer.
    // Priority for SVG: final > SVG original > LQIP. For everything else: final > LQIP.
    const [lockedUrl, setLockedUrl] = useState(() => {
        const initialFinal = effectiveFinalUrl
        const initialPreview = lqipUrl
        if (initialFinal) return initialFinal
        if (svgOriginalFallback) return svgOriginalFallback
        return initialPreview || null
    })

    const [lockedType, setLockedType] = useState(() => {
        if (asset?.final_thumbnail_url) return 'final'
        if (svgOriginalFallback) return 'final'
        if (lqipUrl) return 'preview'
        return null
    })
    
    // Track previous asset ID to detect asset changes
    const prevAssetIdRef = useRef(asset?.id)
    
    // Update URL when asset changes (drawer context) OR when thumbnail becomes available (grid context with polling)
    useEffect(() => {
        if (asset) {
            const newFinal = effectiveFinalUrl
            const newPreview = lqipPreviewUrlForAsset(asset)
            const newUrl = newFinal || newPreview || null
            const assetIdChanged = prevAssetIdRef.current !== asset?.id
            
            // In drawer context: always allow updates (including when preview is removed)
            // In grid context: only update if thumbnail becomes available (was null, now has URL)
            const shouldUpdate = isDrawerContext 
                ? (assetIdChanged || (newUrl && newUrl !== lockedUrl) || (!newUrl && lockedUrl && prevAssetIdRef.current === asset?.id))
                : (assetIdChanged || (!lockedUrl && newUrl)) // Grid: only update if we didn't have a URL and now we do
            
            if (shouldUpdate) {
                setLockedUrl(newUrl)
                if (newFinal) {
                    setLockedType('final')
                } else if (newPreview) {
                    setLockedType('preview')
                } else {
                    setLockedType(null)
                }
                // Reset image state when URL changes
                setImageLoaded(false)
                setImageError(false)
                
                // Update ref to track current asset ID
                prevAssetIdRef.current = asset?.id
            }
        } else if (isDrawerContext && !asset) {
            // Asset became null (drawer closed) - reset tracking
            prevAssetIdRef.current = null
        }
    }, [isDrawerContext, asset?.id, effectiveFinalUrl, asset?.preview_thumbnail_url, asset?.mime_type, thumbnailVersion, lockedUrl])
    
    // Handle case where preview was removed - if locked URL exists but asset data shows no preview, clear it
    useEffect(() => {
        if (lockedUrl && lockedType === 'preview' && !lqipPreviewUrlForAsset(asset) && !asset?.final_thumbnail_url) {
            // Preview was removed - clear locked URL to show icon
            setLockedUrl(null)
            setLockedType(null)
            setImageLoaded(false)
            setImageError(false)
        }
    }, [lockedUrl, lockedType, asset?.preview_thumbnail_url, asset?.mime_type, asset?.final_thumbnail_url])
    
    // Determine if locked URL is final or preview (based on what was locked at mount)
    const lockedIsFinal = lockedType === 'final'
    const lockedIsPreview = lockedType === 'preview'
    
    // Final thumbnails only win AFTER they successfully load
    // If final exists but failed to load (imageError), fall back to preview
    // Skip URLs we've already seen fail (404, etc.) - prevents retries and console noise
    const urlKnownFailed = lockedUrl ? failedThumbnailUrls.has(lockedUrl) : false
    const canUseFinal = lockedIsFinal && !imageError && !urlKnownFailed
    const activeThumbnailUrl = lockedUrl
    const isPreview = lockedIsPreview
    
    // Get state for icon rendering (but don't use it to change URLs)
    // NOTE: Thumbnails intentionally do NOT live-update on the grid.
    // Stability > real-time updates.
    const { state } = useMemo(() => {
        return getThumbnailState(asset, retryCount)
    }, [asset?.id, retryCount])

    const fileExtForThumb = useMemo(
        () =>
            (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
                .toLowerCase()
                .replace(/^\./, ''),
        [asset?.file_extension, asset?.original_filename]
    )

    const showRichPlaceholder = useMemo(() => {
        if (!supportsThumbnail(asset?.mime_type, fileExtForThumb)) {
            return false
        }
        const ts = (asset?.thumbnail_status?.value || asset?.thumbnail_status || '')
            .toString()
            .toLowerCase()
        if (asset?.preview_unavailable_user_message || asset?.metadata?.preview_unavailable_user_message) {
            return true
        }
        if (state === 'FAILED' || state === 'SKIPPED' || ts === 'failed' || ts === 'skipped') {
            return true
        }
        const hasLegacyCompletedThumb =
            !!asset?.thumbnail_url && (ts === 'completed' || asset?.thumbnail_status?.value === 'completed')
        const awaitingServerRaster =
            state === 'PENDING' &&
            !asset?.final_thumbnail_url &&
            !asset?.preview_thumbnail_url &&
            !hasLegacyCompletedThumb
        return Boolean(awaitingServerRaster && ts !== 'failed' && ts !== 'skipped')
    }, [
        asset?.id,
        asset?.mime_type,
        asset?.thumbnail_status,
        asset?.preview_unavailable_user_message,
        asset?.metadata?.preview_unavailable_user_message,
        asset?.final_thumbnail_url,
        asset?.preview_thumbnail_url,
        asset?.thumbnail_url,
        state,
        fileExtForThumb,
    ])

    /** Rich grid tile copy — {@link AssetPlaceholder} */
    const assetPlaceholderHint = useMemo(() => {
        if (asset?.preview_unavailable_user_message || asset?.metadata?.preview_unavailable_user_message) {
            return 'unavailable'
        }
        const ts = (asset?.thumbnail_status?.value || asset?.thumbnail_status || '')
            .toString()
            .toLowerCase()
        if (state === 'FAILED' || ts === 'failed') return 'failed'
        if (state === 'SKIPPED' || ts === 'skipped') return 'skipped'
        if (state === 'PENDING') return 'processing'
        return 'default'
    }, [
        asset?.id,
        asset?.thumbnail_status,
        asset?.preview_unavailable_user_message,
        asset?.metadata?.preview_unavailable_user_message,
        state,
    ])

    useEffect(() => {
        setImageLoaded(false)
        setImageError(false)
    }, [ephemeralLocalPreviewUrl])

    // Small thumbnails (<100px): center image as-is, don't use cover (avoids blurry upscale in container)
    const isSmallThumbnail = useMemo(() => {
        const dims = asset?.metadata?.thumbnail_dimensions
        const thumb = dims?.thumb
        const medium = dims?.medium
        return (thumb && (thumb.width < 100 || thumb.height < 100)) ||
               (medium && (medium.width < 100 || medium.height < 100))
    }, [asset?.metadata?.thumbnail_dimensions])

    const objectFitClass = useMemo(() => {
        if (forceObjectFit) return `object-${forceObjectFit}`
        if (isSmallThumbnail) return 'object-contain object-center'
        if (asset?.category?.slug === 'logos' || asset?.category?.slug === 'graphics') return 'object-contain'
        return 'object-cover'
    }, [forceObjectFit, isSmallThumbnail, asset?.category?.slug])

    const isMasonryHeight = masonryMaxHeight != null && Number(masonryMaxHeight) > 0

    /** `object-fit: cover` + optional POI — not for contain, masonry letterboxing, or tiny thumbs */
    const coverFocalStyle = useMemo(() => {
        if (!useFocalPoint || !asset) return undefined
        if (isMasonryHeight) return undefined
        if (isSmallThumbnail) return undefined
        if (asset?.category?.slug === 'logos' || asset?.category?.slug === 'graphics') return undefined
        const isCover =
            objectFitClass.includes('object-cover') || String(forceObjectFit || '').toLowerCase() === 'cover'
        if (!isCover) return undefined
        return focalPointObjectPositionStyle(effectiveFocalPointFromAsset(asset))
    }, [
        useFocalPoint,
        asset,
        isMasonryHeight,
        isSmallThumbnail,
        objectFitClass,
        forceObjectFit,
    ])
    const effectiveMasonryMinHeightPx = useMemo(() => {
        if (!isMasonryHeight) return 120
        const maxH = Number(masonryMaxHeight)
        const raw = masonryMinHeight != null ? Number(masonryMinHeight) : 120
        let minH = Number.isFinite(raw) && raw > 0 ? raw : 120
        if (Number.isFinite(maxH) && maxH > 0) {
            minH = Math.min(minH, maxH)
        }
        return Math.max(80, minH)
    }, [isMasonryHeight, masonryMaxHeight, masonryMinHeight])
    const imgFitClasses = isMasonryHeight
        ? `w-full h-auto max-h-full ${forceObjectFit ? `${objectFitClass} object-center` : 'object-contain object-center'}`
        : `w-full h-full ${objectFitClass}`
    const masonryWrapperStyle = isMasonryHeight
        ? { maxHeight: Number(masonryMaxHeight), minHeight: effectiveMasonryMinHeightPx }
        : undefined
    /**
     * Masonry: placeholders must match uniform grid tile height (PSD / aspect-[4/3] | aspect-[5/3]).
     * `minHeight` alone is not enough: AssetPlaceholder uses `h-full`, and percentage height needs a
     * definite parent height — so we set explicit `height` to the same pixel target as AssetCard's masonry min.
     */
    const masonryPlaceholderStyle = isMasonryHeight
        ? {
              maxHeight: Number(masonryMaxHeight),
              minHeight: effectiveMasonryMinHeightPx,
              height: effectiveMasonryMinHeightPx,
              width: '100%',
              boxSizing: 'border-box',
          }
        : undefined

    // Check thumbnail status - if FAILED, show icon immediately
    const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
    const isFailed = thumbnailStatus === 'FAILED' || thumbnailStatus === 'failed' || state === 'FAILED'
    const hasThumbnailError = !!asset?.thumbnail_error

    const catSlug = asset?.category?.slug
    const isLogoOrGraphicCategory = catSlug === 'logos' || catSlug === 'graphics'
    const [needsContrastBackdrop, setNeedsContrastBackdrop] = useState(false)

    // Canvas check: light-on-white / low-contrast rasters (categories without checkerboard use flat gray).
    // Logos + graphics use checkerboard on AssetCard; skip duplicate analysis.
    useEffect(() => {
        setNeedsContrastBackdrop(false)
        if (!lockedUrl || isLogoOrGraphicCategory || isSvg || urlKnownFailed) return
        let cancelled = false
        analyzeLogoLightOnWhiteRisk(lockedUrl).then((r) => {
            if (cancelled) return
            if (!r.skipped && r.ok === false) setNeedsContrastBackdrop(true)
        })
        return () => {
            cancelled = true
        }
    }, [lockedUrl, isLogoOrGraphicCategory, isSvg, urlKnownFailed])

    const contrastBackdropClass = needsContrastBackdrop ? 'bg-neutral-200' : ''

    useEffect(() => {
        setForcedStableUrl(null)
        setForcedPendingUrl(null)
        forcedFailedOnceRef.current.clear()
    }, [asset?.id])

    useEffect(() => {
        if (!effectiveForced) {
            setForcedStableUrl(null)
            setForcedPendingUrl(null)
            forcedFailedOnceRef.current.clear()
        }
    }, [effectiveForced])

    useEffect(() => {
        if (!effectiveForced) {
            return
        }
        if (forcedFailedOnceRef.current.has(effectiveForced)) {
            return
        }
        if (forcedStableUrl === null) {
            setForcedPendingUrl(effectiveForced)
            return
        }
        if (effectiveForced !== forcedStableUrl) {
            setForcedPendingUrl(effectiveForced)
        } else {
            setForcedPendingUrl(null)
        }
    }, [effectiveForced, forcedStableUrl])

    /* ------------------------------------------------------------
       Animation trigger (only for meaningful transitions)
    ------------------------------------------------------------ */
    useEffect(() => {
        if (shouldAnimateThumbnail && !animationCompletedRef.current) {
            setIsAnimating(true)
            const t = setTimeout(() => {
                setIsAnimating(false)
                animationCompletedRef.current = true
            }, 500)
            return () => clearTimeout(t)
        }
    }, [shouldAnimateThumbnail])

    // HARD STABILIZATION: Never reset imageLoaded after mount
    // Removed all image state resets to prevent flashing

    /* ------------------------------------------------------------
       Load / error handling
       - Preview errors are NON-TERMINAL
       - Final errors are TERMINAL
    ------------------------------------------------------------ */
    const handleImageLoad = (e) => {
        setImageLoaded(true)
        setImageError(false)
        trackImageLoad(e)
    }

    const handleImageError = () => {
        if (activeThumbnailUrl) {
            failedThumbnailUrls.add(activeThumbnailUrl)
        }
        // Recovery path: an SVG whose rasterized thumbnail 404s should fall back to the original
        // SVG bytes. Without this, the tile goes to the "unavailable" state even though the vector
        // is perfectly renderable in <img>.
        if (isSvg && asset?.original && lockedUrl !== asset.original) {
            setLockedUrl(asset.original)
            setLockedType('final')
            setImageLoaded(false)
            setImageError(false)
            return
        }
        if (isPreview) {
            setImageLoaded(false)
            if (!lqipPreviewUrlForAsset(asset) && !asset?.final_thumbnail_url) {
                setLockedUrl(null)
                setLockedType(null)
                setImageError(false)
            } else {
                setLockedUrl(null)
                setLockedType(null)
                setImageError(false)
            }
        } else {
            setImageLoaded(false)
            setImageError(true)
            if (!isFailed && !hasThumbnailError) {
                setLockedUrl(null)
                setLockedType(null)
            }
        }
    }

    const showEphemeralLocalPreview =
        typeof ephemeralLocalPreviewUrl === 'string' &&
        ephemeralLocalPreviewUrl.length > 0 &&
        !effectiveForced &&
        !effectiveFinalUrl &&
        !serverLqipBlocksEphemeral &&
        !svgOriginalFallback &&
        !isFailed &&
        !hasThumbnailError &&
        state !== 'NOT_SUPPORTED'

    /* ------------------------------------------------------------
       PRIORITY -0.5 — EPHEMERAL LOCAL BLOB (post-upload grid fallback)
    ------------------------------------------------------------ */
    if (showEphemeralLocalPreview) {
        const hasFinalThumbnail = !!asset?.final_thumbnail_url
        const ts = (thumbnailStatus || '').toString().toLowerCase()
        const isTerminalState = ts === 'completed' || ts === 'failed' || ts === 'skipped'
        const isActivelyProcessing =
            (ts === 'processing' || ts === 'pending' || !thumbnailStatus) &&
            !hasFinalThumbnail &&
            !hasThumbnailError &&
            !isTerminalState

        return (
            <div
                className={`relative flex w-full min-h-0 items-center justify-center ${className} ${contrastBackdropClass}`}
                style={masonryWrapperStyle}
            >
                {!imageLoaded ? (
                    <div
                        className="absolute inset-0 overflow-hidden rounded-[inherit] transition-opacity duration-[450ms] ease-out"
                        style={{
                            ...placeholderSurfaceStyle,
                            opacity: 1,
                        }}
                    />
                ) : null}
                <img
                    src={ephemeralLocalPreviewUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`relative z-[1] ${imgFitClasses} pointer-events-none`}
                    loading="eager"
                    style={mergeImageStyle(
                        {
                            opacity: imageLoaded ? 1 : 0,
                            transition: 'opacity 0.45s ease-out',
                        },
                        coverFocalStyle,
                    )}
                    onLoad={handleImageLoad}
                    onError={() => {
                        setImageLoaded(false)
                    }}
                />
                {isActivelyProcessing && (
                    <span
                        className="pointer-events-none absolute bottom-1.5 left-1/2 z-10 -translate-x-1/2 rounded bg-black/55 px-2 py-0.5 text-[10px] font-medium text-white shadow-sm"
                        title="Server preview is still generating"
                    >
                        Processing preview
                    </span>
                )}
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY -1 — DRAWER FORCED URL (preview style / pipeline mode)
       Keeps the previous image visible until the next URL has loaded (no empty flash).
    ------------------------------------------------------------ */
    if (effectiveForced) {
        const isPreparingNext =
            !!forcedPendingUrl &&
            (forcedStableUrl === null || forcedPendingUrl !== forcedStableUrl)
        const forcedShowFatal =
            !!effectiveForced &&
            forcedStableUrl === null &&
            forcedPendingUrl === null &&
            forcedFailedOnceRef.current.has(effectiveForced)
        const showForcedOverlaySpinner = isPreparingNext || !!forcedImageSpinnerOverlay

        const handleForcedPendingLoad = (e) => {
            if (forcedPendingUrl) {
                setForcedStableUrl(forcedPendingUrl)
                setForcedPendingUrl(null)
            }
            trackImageLoad(e)
        }

        const handleForcedPendingError = () => {
            if (!forcedPendingUrl) {
                return
            }
            failedThumbnailUrls.add(forcedPendingUrl)
            forcedFailedOnceRef.current.add(forcedPendingUrl)
            setForcedPendingUrl(null)
        }

        const handleForcedStableLoad = (e) => {
            trackImageLoad(e)
        }

        const handleForcedStableError = () => {
            if (forcedStableUrl) {
                failedThumbnailUrls.add(forcedStableUrl)
            }
        }

        if (forcedShowFatal) {
            return (
                <div
                    className={
                        isMasonryHeight
                            ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                            : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
                    }
                    style={masonryPlaceholderStyle}
                >
                    <AssetPlaceholder
                        asset={asset}
                        primaryColor={placeholderBrandPrimary}
                        brand={auth?.activeBrand}
                        size={size}
                        rich={showRichPlaceholder}
                        placeholderHint={assetPlaceholderHint}
                        ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                    />
                </div>
            )
        }

        return (
            <div
                className={`relative flex w-full min-h-0 items-center justify-center ${className}`}
                style={masonryWrapperStyle}
            >
                {forcedStableUrl === null && !isAnimating ? (
                    <div
                        className="absolute inset-0 overflow-hidden rounded-[inherit] transition-opacity duration-[450ms] ease-out"
                        style={placeholderSurfaceStyle}
                    />
                ) : null}
                {forcedStableUrl ? (
                    <img
                        key={forcedStableUrl}
                        ref={imgRef}
                        src={forcedStableUrl}
                        alt={alt}
                        draggable={false}
                        onDragStart={(e) => e.preventDefault()}
                        className={imgFitClasses}
                        loading="eager"
                        style={mergeImageStyle(
                            {
                                opacity: 1,
                                transition:
                                    isAnimating && forcedStableUrl ? 'opacity 500ms ease-out' : 'none',
                            },
                            coverFocalStyle,
                        )}
                        onLoad={handleForcedStableLoad}
                        onError={handleForcedStableError}
                    />
                ) : null}
                {forcedPendingUrl && forcedPendingUrl !== forcedStableUrl ? (
                    <img
                        key={`pending-${forcedPendingUrl}`}
                        src={forcedPendingUrl}
                        alt=""
                        aria-hidden
                        draggable={false}
                        onDragStart={(e) => e.preventDefault()}
                        className={`${imgFitClasses} absolute inset-0 opacity-0 pointer-events-none`}
                        loading="eager"
                        style={mergeImageStyle(undefined, coverFocalStyle)}
                        onLoad={handleForcedPendingLoad}
                        onError={handleForcedPendingError}
                    />
                ) : null}
                {showForcedOverlaySpinner && (
                    <span
                        className="pointer-events-none absolute bottom-1.5 left-1/2 z-10 -translate-x-1/2 rounded bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white shadow-sm"
                        title="Thumbnail is still processing"
                    >
                        Processing
                    </span>
                )}
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 0 — FAILED STATE: Show icon when no usable URL
       If LQIP was persisted (image + preview_thumbnail_url), still show blur before icon.
    ------------------------------------------------------------ */
    if ((isFailed || hasThumbnailError) && !effectiveFinalUrl && !lqipUrl) {
        return (
            <div
                className={
                    isMasonryHeight
                        ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                        : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
                }
                style={masonryPlaceholderStyle}
            >
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={placeholderBrandPrimary}
                    brand={auth?.activeBrand}
                    size={size}
                    rich={showRichPlaceholder}
                    placeholderHint={assetPlaceholderHint}
                    ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                />
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 1 — FINAL THUMBNAIL (only if successfully loaded)
       HARD STABILIZATION: Render ONLY lockedUrl - never respond to updates
       If image fails to load AND status is FAILED, show icon instead
       urlKnownFailed: skip img render for URLs we've seen 404 - show placeholder
    ------------------------------------------------------------ */
    if (canUseFinal && lockedUrl && !urlKnownFailed) {
        // If image failed to load AND status is FAILED, show icon
        if (imageError && (isFailed || hasThumbnailError)) {
            return (
                <div
                    className={
                        isMasonryHeight
                            ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${contrastBackdropClass}`
                            : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className} ${contrastBackdropClass}`
                    }
                    style={masonryPlaceholderStyle}
                >
                    <AssetPlaceholder
                        asset={asset}
                        primaryColor={placeholderBrandPrimary}
                        brand={auth?.activeBrand}
                        size={size}
                        rich={showRichPlaceholder}
                        placeholderHint={assetPlaceholderHint}
                        ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                    />
                </div>
            )
        }
        
        return (
            <div
                className={`relative flex w-full min-h-0 items-center justify-center ${className} ${contrastBackdropClass}`}
                style={masonryWrapperStyle}
            >
                <div
                    className="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit] transition-opacity duration-[450ms] ease-out"
                    style={{
                        ...placeholderSurfaceStyle,
                        opacity: imageLoaded ? 0 : 1,
                    }}
                />

                <img
                    key={lockedUrl}
                    ref={imgRef}
                    src={lockedUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`relative z-[1] ${imgFitClasses}`}
                    loading="eager"
                    style={mergeImageStyle(
                        {
                            opacity: imageLoaded ? 1 : 0,
                            transition:
                                isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'opacity 0.45s ease-out',
                        },
                        coverFocalStyle,
                    )}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />
            </div>
        )
    }



    /* ------------------------------------------------------------
       FALLBACK: Final failed but preview exists → render preview
       HARD STABILIZATION: Render ONLY lockedUrl - never respond to updates
    ------------------------------------------------------------ */
    if (lockedIsFinal && imageError && lockedIsPreview && lockedUrl) {
        // HARD TERMINAL STATE: Spinner may ONLY render when actively processing
        // Spinner must NEVER render for terminal states (COMPLETED, FAILED, SKIPPED)
        // Spinner must NEVER render when final thumbnail exists
        const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
        const hasFinalThumbnail = !!asset?.final_thumbnail_url
        const hasThumbnailError = !!asset?.thumbnail_error
        
        // Explicit terminal state guard - spinner must never render for these
        const isTerminalState = thumbnailStatus === 'COMPLETED' || 
                               thumbnailStatus === 'FAILED' || 
                               thumbnailStatus === 'SKIPPED'
        
        // Strict condition: spinner ONLY when actively processing
        const isActivelyProcessing = thumbnailStatus === 'PROCESSING' &&
                                     !hasFinalThumbnail &&
                                     !hasThumbnailError &&
                                     !isTerminalState
        
        return (
            <div
                className={`relative flex w-full min-h-0 items-center justify-center ${className} ${contrastBackdropClass}`}
                style={masonryWrapperStyle}
            >
                <div
                    className="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit] transition-opacity duration-[450ms] ease-out"
                    style={{
                        ...placeholderSurfaceStyle,
                        opacity: imageLoaded ? 0 : 1,
                    }}
                />
                <img
                    key={lockedUrl}
                    ref={imgRef}
                    src={lockedUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`relative z-[1] ${imgFitClasses}`}
                    loading="eager"
                    style={mergeImageStyle(
                        {
                            opacity: imageLoaded ? 1 : 0,
                            imageRendering: isPreview ? 'pixelated' : 'auto',
                            transform: isPreview ? 'scale(1.03)' : 'none',
                            transition:
                                isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'opacity 0.45s ease-out',
                        },
                        coverFocalStyle,
                    )}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />

                {isActivelyProcessing && (
                    <span
                        className="pointer-events-none absolute bottom-1.5 left-1/2 z-10 -translate-x-1/2 rounded bg-black/55 px-2 py-0.5 text-[10px] font-medium text-white shadow-sm"
                        title="Final thumbnail is still generating"
                    >
                        Processing
                    </span>
                )}
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 2 — PREVIEW THUMBNAIL (NO ICONS EVER)
       HARD STABILIZATION: Render ONLY lockedUrl - never respond to updates
       Preview must render immediately when preview_thumbnail_url exists
       imageLoaded only affects opacity, not whether image exists in DOM
       urlKnownFailed: skip img render for URLs we've seen 404 - show placeholder
    ------------------------------------------------------------ */
    if (lockedIsPreview && lockedUrl && !urlKnownFailed) {
        // HARD TERMINAL STATE: Spinner may ONLY render when actively processing
        // Spinner must NEVER render for terminal states (COMPLETED, FAILED, SKIPPED)
        // Spinner must NEVER render when final thumbnail exists
        const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
        const hasFinalThumbnail = !!asset?.final_thumbnail_url
        const hasThumbnailError = !!asset?.thumbnail_error
        
        // Explicit terminal state guard - spinner must never render for these
        const isTerminalState = thumbnailStatus === 'COMPLETED' || 
                               thumbnailStatus === 'FAILED' || 
                               thumbnailStatus === 'SKIPPED'
        
        // Strict condition: spinner ONLY when actively processing
        // ALL of these must be true:
        // 1. thumbnail_status is PROCESSING (not terminal)
        // 2. No final thumbnail exists yet (final means processing is done)
        // 3. No thumbnail error exists (error means processing failed)
        // 4. Not in a terminal state (explicit guard)
        const isActivelyProcessing = thumbnailStatus === 'PROCESSING' &&
                                     !hasFinalThumbnail &&
                                     !hasThumbnailError &&
                                     !isTerminalState
        
        return (
            <div
                className={`relative flex w-full min-h-0 items-center justify-center ${className} ${contrastBackdropClass}`}
                style={masonryWrapperStyle}
            >
                <div
                    className="pointer-events-none absolute inset-0 overflow-hidden rounded-[inherit] transition-opacity duration-[450ms] ease-out"
                    style={{
                        ...placeholderSurfaceStyle,
                        opacity: imageLoaded ? 0 : 1,
                    }}
                />

                {/* Preview image always renders when preview_thumbnail_url exists */}
                {/* NEVER render FileTypeIcon when preview exists */}
                {/* imageLoaded only affects opacity, not DOM presence */}
                <img
                    key={lockedUrl}
                    ref={imgRef}
                    src={lockedUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`relative z-[1] ${imgFitClasses}`}
                    loading="eager"
                    style={mergeImageStyle(
                        {
                            opacity: imageLoaded ? 1 : 0,
                            imageRendering: isPreview ? 'pixelated' : 'auto',
                            transform: isPreview ? 'scale(1.03)' : 'none',
                            transition:
                                isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'opacity 0.45s ease-out',
                        },
                        coverFocalStyle,
                    )}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />

                {isActivelyProcessing && (
                    <span
                        className="pointer-events-none absolute bottom-1.5 left-1/2 z-10 -translate-x-1/2 rounded bg-black/55 px-2 py-0.5 text-[10px] font-medium text-white shadow-sm"
                        title="Final thumbnail is still generating"
                    >
                        Processing
                    </span>
                )}
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 3 — ICON (STRICT RULES)
       Icons may ONLY render when:
       - state === NOT_SUPPORTED
       - state === FAILED
       - state === SKIPPED
       - state === PENDING AND preview_thumbnail_url is null AND final_thumbnail_url is null
       
       CRITICAL: Icons NEVER render when preview_thumbnail_url exists
       Icons may render if final_thumbnail_url exists but failed (no preview available)
    ------------------------------------------------------------ */
    // URL known failed (404, etc.) - show placeholder without retrying
    if (lockedUrl && urlKnownFailed) {
        return (
            <div
                className={
                    isMasonryHeight
                        ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                        : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
                }
                style={masonryPlaceholderStyle}
            >
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={placeholderBrandPrimary}
                    brand={auth?.activeBrand}
                    size={size}
                    rich={showRichPlaceholder}
                    placeholderHint={assetPlaceholderHint}
                    ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                />
            </div>
        )
    }

    // Icons are terminal-only - never render if preview exists
    // Guard: If preview exists, we should have already returned above
    // HARD STABILIZATION: Check locked URL, not live URL
    if (lockedUrl) {
        // Should not reach here - preview/final branch should have handled it
        return null
    }
    
    // SVG fallback: render original SVG inline when thumbnails were skipped/failed
    if (isSvg && svgOriginalFallback && (state === 'SKIPPED' || state === 'FAILED' || state === 'PENDING')) {
        return (
            <div
                className={
                    isMasonryHeight
                        ? 'relative flex w-full items-center justify-center bg-white'
                        : `flex items-center justify-center bg-white ${className}`
                }
                style={masonryPlaceholderStyle}
            >
                <img
                    src={svgOriginalFallback}
                    alt={alt}
                    className="max-w-full max-h-full object-contain p-2"
                    loading="lazy"
                />
            </div>
        )
    }

    if (state === 'NOT_SUPPORTED' || state === 'FAILED' || state === 'SKIPPED') {
        return (
            <div
                className={
                    isMasonryHeight
                        ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                        : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
                }
                style={masonryPlaceholderStyle}
            >
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={placeholderBrandPrimary}
                    brand={auth?.activeBrand}
                    size={size}
                    rich={showRichPlaceholder}
                    placeholderHint={assetPlaceholderHint}
                    ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                />
            </div>
        )
    }

    // PENDING state - only show icon if no preview and no final exists
    // HARD STABILIZATION: Check locked URL, not live URL
    if (state === 'PENDING' && !lockedUrl) {
        return (
            <div
                className={
                    isMasonryHeight
                        ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                        : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
                }
                style={masonryPlaceholderStyle}
            >
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={placeholderBrandPrimary}
                    brand={auth?.activeBrand}
                    size={size}
                    rich={showRichPlaceholder}
                    placeholderHint={assetPlaceholderHint}
                    ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
                />
            </div>
        )
    }

    // Fallback: Should not reach here, but show icon if we do
    return (
        <div
            className={
                isMasonryHeight
                    ? `relative flex w-full items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'}`
                    : `flex items-center justify-center ${showRichPlaceholder ? '' : 'bg-gray-50'} ${className}`
            }
            style={masonryPlaceholderStyle}
        >
            <AssetPlaceholder
                asset={asset}
                primaryColor={placeholderBrandPrimary}
                brand={auth?.activeBrand}
                size={size}
                rich={showRichPlaceholder}
                placeholderHint={assetPlaceholderHint}
                ephemeralLocalPreviewUrl={ephemeralLocalPreviewUrl}
            />
        </div>
    )
}

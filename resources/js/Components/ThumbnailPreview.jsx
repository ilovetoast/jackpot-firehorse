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
 */
import { useState, useEffect, useRef, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
import FileTypeIcon from './FileTypeIcon'
import AssetPlaceholder from './AssetPlaceholder'


export default function ThumbnailPreview({
    asset,
    alt = 'Asset',
    className = '',
    retryCount = 0,
    size = 'md',
    thumbnailVersion = null,
    shouldAnimateThumbnail = false,
    primaryColor = null
}) {
    const { auth } = usePage().props
    // Use current brand's primary color, fallback to default if not provided
    const brandPrimaryColor = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
    const [imageLoaded, setImageLoaded] = useState(false)
    const [imageError, setImageError] = useState(false)
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
    
    // Lock the URL on first render for grid, but allow updates for drawer
    const [lockedUrl, setLockedUrl] = useState(() => {
        // Determine initial URL: final > preview > null
        const initialFinal = asset?.final_thumbnail_url
        const initialPreview = asset?.preview_thumbnail_url
        return initialFinal || initialPreview || null
    })
    
    // Also lock the type (final vs preview) at mount time
    const [lockedType, setLockedType] = useState(() => {
        if (asset?.final_thumbnail_url) return 'final'
        if (asset?.preview_thumbnail_url) return 'preview'
        return null
    })
    
    // Track previous asset ID to detect asset changes
    const prevAssetIdRef = useRef(asset?.id)
    
    // Update URL when asset changes (drawer context) OR when thumbnail becomes available (grid context with polling)
    useEffect(() => {
        if (asset) {
            const newFinal = asset?.final_thumbnail_url
            const newPreview = asset?.preview_thumbnail_url
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
    }, [isDrawerContext, asset?.id, asset?.final_thumbnail_url, asset?.preview_thumbnail_url, thumbnailVersion, lockedUrl])
    
    // Handle case where preview was removed - if locked URL exists but asset data shows no preview, clear it
    useEffect(() => {
        if (lockedUrl && lockedType === 'preview' && !asset?.preview_thumbnail_url && !asset?.final_thumbnail_url) {
            // Preview was removed - clear locked URL to show icon
            setLockedUrl(null)
            setLockedType(null)
            setImageLoaded(false)
            setImageError(false)
        }
    }, [lockedUrl, lockedType, asset?.preview_thumbnail_url, asset?.final_thumbnail_url])
    
    // Determine if locked URL is final or preview (based on what was locked at mount)
    const lockedIsFinal = lockedType === 'final'
    const lockedIsPreview = lockedType === 'preview'
    
    // Final thumbnails only win AFTER they successfully load
    // If final exists but failed to load (imageError), fall back to preview
    const canUseFinal = lockedIsFinal && !imageError
    const activeThumbnailUrl = lockedUrl
    const isPreview = lockedIsPreview
    
    // Get state for icon rendering (but don't use it to change URLs)
    // NOTE: Thumbnails intentionally do NOT live-update on the grid.
    // Stability > real-time updates.
    const { state } = useMemo(() => {
        return getThumbnailState(asset, retryCount)
    }, [asset?.id, retryCount])

    // Check thumbnail status - if FAILED, show icon immediately
    const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
    const isFailed = thumbnailStatus === 'FAILED' || thumbnailStatus === 'failed' || state === 'FAILED'
    const hasThumbnailError = !!asset?.thumbnail_error

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
    const handleImageLoad = () => {
        setImageLoaded(true)
        setImageError(false)
    }

    const handleImageError = () => {
        if (isPreview) {
            console.warn('[ThumbnailPreview] Preview load failed (non-terminal)', {
                assetId: asset?.id,
                url: activeThumbnailUrl,
            })
            setImageLoaded(false)
            // Check if preview was actually removed (no preview URL in asset data)
            if (!asset?.preview_thumbnail_url && !asset?.final_thumbnail_url) {
                // Preview was removed - clear locked URL to show icon
                setLockedUrl(null)
                setLockedType(null)
                setImageError(false)
            } else {
                // Preview URL exists but image failed to load - likely broken/missing file
                // Clear locked URL to show icon instead of white space
                setLockedUrl(null)
                setLockedType(null)
                setImageError(false)
            }
        } else {
            console.error('[ThumbnailPreview] Final thumbnail failed', {
                assetId: asset?.id,
                url: activeThumbnailUrl,
            })
            setImageLoaded(false)
            setImageError(true)
            
            // If thumbnail status is FAILED, trigger re-render to show icon
            // This handles the case where a broken URL exists but status is FAILED
            if (isFailed || hasThumbnailError) {
                // Force component to re-evaluate and show icon
                // The isFailed check at the top will catch this on next render
            } else {
                // Final thumbnail URL exists but image failed to load - clear to show icon
                setLockedUrl(null)
                setLockedType(null)
            }
        }
    }

    /* ------------------------------------------------------------
       PRIORITY 0 — FAILED STATE: Show icon immediately if thumbnail failed
       If thumbnail generation failed, show icon even if URL exists (broken/corrupted file)
    ------------------------------------------------------------ */
    if (isFailed || hasThumbnailError) {
        return (
            <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={brandPrimaryColor}
                />
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 1 — FINAL THUMBNAIL (only if successfully loaded)
       HARD STABILIZATION: Render ONLY lockedUrl - never respond to updates
       If image fails to load AND status is FAILED, show icon instead
    ------------------------------------------------------------ */
    if (canUseFinal && lockedUrl) {
        // If image failed to load AND status is FAILED, show icon
        if (imageError && (isFailed || hasThumbnailError)) {
            return (
                <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                    <AssetPlaceholder
                        asset={asset}
                        primaryColor={brandPrimaryColor}
                    />
                </div>
            )
        }
        
        return (
            <div className={`relative ${className}`}>
                {/* Background placeholder - only show if image not loaded and not animating */}
                {!imageLoaded && !isAnimating && (
                    <div className="absolute inset-0 bg-gray-100" />
                )}

                <img
                    key={lockedUrl}
                    ref={imgRef}
                    src={lockedUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`w-full h-full ${
                        asset?.category?.slug === 'logos' 
                            ? 'object-contain' 
                            : 'object-cover'
                    }`}
                    loading="eager"
                    style={{
                        opacity: imageLoaded ? 1 : 0,
                        transition: isAnimating && imageLoaded
                            ? 'opacity 500ms ease-out'
                            : 'none',
                    }}
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
            <div className={`relative ${className}`}>
                {/* Preview image always renders when preview_thumbnail_url exists */}
                {/* imageLoaded only affects opacity, not DOM presence */}
                <img
                    key={lockedUrl}
                    ref={imgRef}
                    src={lockedUrl}
                    alt={alt}
                    draggable={false}
                    onDragStart={(e) => e.preventDefault()}
                    className={`w-full h-full ${
                        asset?.category?.slug === 'logos' 
                            ? 'object-contain' 
                            : 'object-cover'
                    }`}
                    loading="eager"
                    style={{
                        opacity: imageLoaded ? 1 : 0.5,
                        imageRendering: isPreview ? 'pixelated' : 'auto',
                        transform: isPreview ? 'scale(1.03)' : 'none',
                        transition: isAnimating && imageLoaded
                            ? 'opacity 500ms ease-out'
                            : 'none',
                    }}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />

                {/* Preview spinner overlay - bottom-right corner */}
                {/* ONLY render when actively processing (strict terminal-aware condition) */}
                {isActivelyProcessing && (
                    <div 
                        className="absolute bottom-2 right-2 pointer-events-none flex items-center justify-center rounded-full"
                        style={{
                            width: '28px',
                            height: '28px',
                            backgroundColor: brandPrimaryColor,
                            boxShadow: '0 2px 4px rgba(0, 0, 0, 0.1)',
                        }}
                    >
                        <svg
                            className="w-4 h-4 animate-spin"
                            style={{
                                color: 'rgba(255, 255, 255, 0.8)',
                                animationDuration: '1.2s',
                            }}
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                            />
                        </svg>
                    </div>
                )}
            </div>
        )
    }

    /* ------------------------------------------------------------
       PRIORITY 2 — PREVIEW THUMBNAIL (NO ICONS EVER)
       HARD STABILIZATION: Render ONLY lockedUrl - never respond to updates
       Preview must render immediately when preview_thumbnail_url exists
       imageLoaded only affects opacity, not whether image exists in DOM
    ------------------------------------------------------------ */
    if (lockedIsPreview && lockedUrl) {
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
            <div className={`relative ${className}`}>
                {/* Background placeholder - show while image loads */}
                {!imageLoaded && (
                    <div className="absolute inset-0 bg-gray-100" />
                )}
                
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
                    className={`w-full h-full ${
                        asset?.category?.slug === 'logos' 
                            ? 'object-contain' 
                            : 'object-cover'
                    }`}
                    loading="eager"
                    style={{
                        opacity: imageLoaded ? 1 : 0.5,
                        imageRendering: isPreview ? 'pixelated' : 'auto',
                        transform: isPreview ? 'scale(1.03)' : 'none',
                        transition: isAnimating && imageLoaded
                            ? 'opacity 500ms ease-out'
                            : 'none',
                    }}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />

                {/* Preview spinner overlay - bottom-right corner */}
                {/* ONLY render when actively processing (strict terminal-aware condition) */}
                {isActivelyProcessing && (
                    <div 
                        className="absolute bottom-2 right-2 pointer-events-none flex items-center justify-center rounded-full"
                        style={{
                            width: '28px',
                            height: '28px',
                            backgroundColor: brandPrimaryColor,
                            boxShadow: '0 2px 4px rgba(0, 0, 0, 0.1)',
                        }}
                    >
                        <svg
                            className="w-4 h-4 animate-spin"
                            style={{
                                color: 'rgba(255, 255, 255, 0.8)',
                                animationDuration: '1.2s',
                            }}
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                            />
                        </svg>
                    </div>
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
    // Icons are terminal-only - never render if preview exists
    // Guard: If preview exists, we should have already returned above
    // HARD STABILIZATION: Check locked URL, not live URL
    if (lockedUrl) {
        // Should not reach here - preview/final branch should have handled it
        // Return null to prevent icon rendering
        return null
    }
    
    if (state === 'NOT_SUPPORTED' || state === 'FAILED' || state === 'SKIPPED') {
        return (
            <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                <AssetPlaceholder
                    asset={asset}
                    primaryColor={brandPrimaryColor}
                    />
            </div>
        )
    }

    // PENDING state - only show icon if no preview and no final exists
    // HARD STABILIZATION: Check locked URL, not live URL
    if (state === 'PENDING' && !lockedUrl) {
        return (
            <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                <AssetPlaceholder
                asset={asset}
                    primaryColor={brandPrimaryColor}
                />
            </div>
        )
    }

    // Fallback: Should not reach here, but show icon if we do
    return (
        <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
            <AssetPlaceholder
                asset={asset}
                    primaryColor={brandPrimaryColor}
                />
        </div>
    )
}

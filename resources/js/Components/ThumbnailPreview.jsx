/**
 * Phase 3.1E: ThumbnailPreview Component with strict state contracts
 * 
 * Displays thumbnails with four explicit UI states:
 * - NOT_SUPPORTED: File type doesn't support thumbnails (never render <img>, never poll)
 * - PENDING: Thumbnail generation in progress (never render <img>, show processing indicator)
 * - FAILED: Thumbnail generation failed (never render <img>, show error message)
 * - AVAILABLE: Thumbnail ready (render <img>, fade-in ONLY on meaningful state transitions)
 * 
 * Features:
 * - Strict state contracts prevent misleading UI
 * - Icon → thumbnail fade-in (CSS opacity only) - ONLY on meaningful state transitions
 * - File-type icon fallback for non-thumbnail files
 * - Retry affordance for failed thumbnails (UI only, max 2 retries)
 * - Runtime <img> error handling with console logs
 * 
 * Phase 3.1E: Thumbnails should NEVER fade in on initial render.
 * Fade animations are reserved exclusively for meaningful state transitions
 * (e.g. FileTypeIcon → real thumbnail after background processing).
 * This avoids UI jank and ensures animations communicate progress, not load timing.
 * 
 * Smart poll authority: Only polling/reconciliation may promote to AVAILABLE.
 * UI must never assume thumbnail availability.
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with thumbnail_url, thumbnail_status, mime_type, file_extension
 * @param {string} props.alt - Alt text for image
 * @param {string} props.className - CSS classes for container
 * @param {number} props.retryCount - Current retry count (for UI-only retry logic)
 * @param {Function} props.onRetry - Callback when retry is requested (UI only)
 * @param {string} props.size - Icon size ('sm', 'md', 'lg')
 * @param {string} props.thumbnailVersion - Stable version signal (derived from asset props) - triggers re-render when thumbnail availability changes
 * @param {boolean} props.shouldAnimateThumbnail - If true, apply fade-in animation (only set on meaningful state transitions)
 */
import { useState, useEffect, useRef, useMemo } from 'react'
import { getThumbnailState } from '../utils/thumbnailUtils'
import FileTypeIcon from './FileTypeIcon'

export default function ThumbnailPreview({
    asset,
    alt = 'Asset',
    className = '',
    retryCount = 0,
    onRetry = null,
    size = 'md',
    thumbnailVersion = null,
    shouldAnimateThumbnail = false,
}) {
    const [imageLoaded, setImageLoaded] = useState(false)
    const [imageError, setImageError] = useState(false)
    const [isAnimating, setIsAnimating] = useState(false)
    const animationCompletedRef = useRef(false)
    
    // Phase 3.1: Recompute thumbnail state only when asset id or thumbnailVersion changes
    // This ensures state machine re-evaluates after background reconciliation updates asset props
    const { state, thumbnailUrl, canRetry } = useMemo(() => {
        return getThumbnailState(asset, retryCount)
    }, [asset?.id, thumbnailVersion, retryCount])
    
    // Phase 3.1E: Handle animation trigger from parent (meaningful state transition detected)
    // Animation is one-time only - once it completes, don't replay on re-renders
    useEffect(() => {
        if (shouldAnimateThumbnail && !animationCompletedRef.current) {
            setIsAnimating(true)
            // Reset animation flag after transition completes (500ms)
            const timer = setTimeout(() => {
                setIsAnimating(false)
                animationCompletedRef.current = true
            }, 500)
            return () => clearTimeout(timer)
        }
    }, [shouldAnimateThumbnail])
    
    // Phase 3.1E: Reset animation state when thumbnail URL changes (new thumbnail = new animation opportunity)
    useEffect(() => {
        if (thumbnailUrl) {
            setImageLoaded(false)
            setImageError(false)
            // Reset animation completion flag when thumbnail URL changes
            // This allows animation on next meaningful transition
            animationCompletedRef.current = false
            setIsAnimating(false)
        }
    }, [thumbnailUrl, thumbnailVersion])
    
    const handleImageLoad = () => {
        setImageLoaded(true)
        setImageError(false)
    }
    
    // Phase 3.1E: Runtime <img> error handling
    // onError: Immediately hide image, fallback to FileTypeIcon, log error
    // Do NOT retry silently - user must explicitly retry if needed
    const handleImageError = () => {
        console.error('[ThumbnailPreview] Runtime image error', {
            assetId: asset?.id,
            thumbnailUrl,
            state,
            note: 'Image failed to load - falling back to FileTypeIcon',
        })
        setImageError(true)
        setImageLoaded(false)
    }
    
    // Extract file extension and MIME type for FileTypeIcon
    const fileExtension = asset?.file_extension || 
                         asset?.original_filename?.split('.').pop()?.toLowerCase() ||
                         asset?.file?.name?.split('.').pop()?.toLowerCase()
    const mimeType = asset?.mime_type || asset?.file?.type
    
    // ============================================================================
    // ABSOLUTE INVARIANT: REAL THUMBNAILS ALWAYS WIN OVER STATE
    // ============================================================================
    // Priority #1 — Reality wins
    // If thumbnail_url exists and is valid, render it immediately.
    // No loading state, no icon, no message, no "Processing..." text may ever
    // override a real thumbnail. This prevents misleading UI and regressions.
    // ============================================================================
    // State contracts (NOT_SUPPORTED, PENDING, FAILED) only apply when
    // thumbnail_url does NOT exist. Once a thumbnail exists, it must be shown.
    // ============================================================================
    if (thumbnailUrl) {
        // Phase 3.1E: Runtime <img> error - immediately hide image, fallback to FileTypeIcon
        // This makes the UI bulletproof against backend bugs (1x1 pixels, missing files, 404, etc.)
        // Error is already logged in handleImageError()
        if (imageError) {
            return (
                <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                    <FileTypeIcon
                        fileExtension={fileExtension}
                        mimeType={mimeType}
                        size={size}
                    />
                </div>
            )
        }
        
        // Render thumbnail image with fade-in ONLY on meaningful state transitions
        return (
            <div className={`relative ${className}`}>
                {/* File-type icon (shown when thumbnail is loading or failed) */}
                {(!imageLoaded || imageError) && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-50">
                        <FileTypeIcon
                            fileExtension={fileExtension}
                            mimeType={mimeType}
                            size={size}
                        />
                    </div>
                )}
                
                {/* Thumbnail image - Phase 3.1E: Only fade-in on meaningful state transitions */}
                {/* No fade on initial render - prevents UI jank */}
                {/* Animation is semantic (communicates progress), not decorative */}
                <img
                    src={thumbnailUrl}
                    alt={alt}
                    className="w-full h-full object-cover"
                    style={{
                        opacity: imageLoaded && !imageError ? 1 : 0,
                        // Only apply fade-in transition if animation is explicitly triggered
                        // (meaningful state transition detected by parent component)
                        transition: isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'none',
                    }}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />
            </div>
        )
    }
    
    // ============================================================================
    // STATE CONTRACTS (only apply when thumbnail_url does NOT exist)
    // ============================================================================
    
    // State A) NOT_SUPPORTED - Never render <img>, always show FileTypeIcon
    // Only applies when thumbnail_url does NOT exist
    if (state === 'NOT_SUPPORTED') {
        return (
            <div className={`relative flex flex-col items-center justify-center bg-gray-50 ${className}`}>
                <FileTypeIcon
                    fileExtension={fileExtension}
                    mimeType={mimeType}
                    size={size}
                />
                {/* User-facing message */}
                <div className="absolute bottom-2 left-2 right-2">
                    <span className="inline-flex items-center rounded-md bg-gray-900/70 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white">
                        Preview not supported for this file type.
                    </span>
                </div>
            </div>
        )
    }
    
    // State B) PENDING / PROCESSING - Never render <img>, show FileTypeIcon + processing indicator
    // Only applies when thumbnail_url does NOT exist AND thumbnail_status is pending/processing
    if (state === 'PENDING') {
        return (
            <div className={`relative flex items-center justify-center bg-gray-50 ${className}`}>
                <FileTypeIcon
                    fileExtension={fileExtension}
                    mimeType={mimeType}
                    size={size}
                />
                {/* Processing indicator */}
                <div className="absolute bottom-2 left-2 right-2">
                    <span className="inline-flex items-center rounded-md bg-gray-900/70 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white">
                        Processing…
                    </span>
                </div>
            </div>
        )
    }
    
    // State C) FAILED - Never render <img>, show FileTypeIcon + error message
    // Only applies when thumbnail_url does NOT exist AND thumbnail_status is failed
    if (state === 'FAILED') {
        return (
            <div className={`relative flex items-center justify-center bg-gray-50 ${className}`}>
                <FileTypeIcon
                    fileExtension={fileExtension}
                    mimeType={mimeType}
                    size={size}
                />
                {/* Error indicator with user-facing message */}
                <div className="absolute bottom-2 left-2 right-2">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center rounded-md bg-red-900/70 backdrop-blur-sm px-2 py-1 text-xs font-medium text-white">
                            Preview failed to generate.
                        </span>
                        {canRetry && onRetry && (
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    onRetry()
                                }}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                            >
                                Retry
                            </button>
                        )}
                    </div>
                </div>
            </div>
        )
    }
    
    // Fallback: Should not reach here, but show FileTypeIcon if we do
    return (
        <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
            <FileTypeIcon
                fileExtension={fileExtension}
                mimeType={mimeType}
                size={size}
            />
        </div>
    )
    
    // Phase 3.1E: Runtime <img> error - immediately hide image, fallback to FileTypeIcon
    // This makes the UI bulletproof against backend bugs (1x1 pixels, missing files, 404, etc.)
    // Error is already logged in handleImageError()
    if (imageError) {
        return (
            <div className={`flex items-center justify-center bg-gray-50 ${className}`}>
                <FileTypeIcon
                    fileExtension={fileExtension}
                    mimeType={mimeType}
                    size={size}
                />
            </div>
        )
    }
    
    return (
        <div className={`relative ${className}`}>
            {/* File-type icon (shown when thumbnail is loading or failed) */}
            {(!imageLoaded || imageError) && (
                <div className="absolute inset-0 flex items-center justify-center bg-gray-50">
                    <FileTypeIcon
                        fileExtension={fileExtension}
                        mimeType={mimeType}
                        size={size}
                    />
                </div>
            )}
            
            {/* Thumbnail image - Phase 3.1E: Only fade-in on meaningful state transitions */}
            {/* No fade on initial render - prevents UI jank */}
            {/* Animation is semantic (communicates progress), not decorative */}
            <img
                src={thumbnailUrl}
                alt={alt}
                className="w-full h-full object-cover"
                style={{
                    opacity: imageLoaded && !imageError ? 1 : 0,
                    // Only apply fade-in transition if animation is explicitly triggered
                    // (meaningful state transition detected by parent component)
                    transition: isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'none',
                }}
                onLoad={handleImageLoad}
                onError={handleImageError}
            />
        </div>
    )
}

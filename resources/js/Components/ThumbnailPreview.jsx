/**
 * ThumbnailPreview Component - Step 2: Frontend Rendering with Strict Priority
 * 
 * CRITICAL RENDERING PRIORITY (enforced strictly):
 * ================================================
 * 
 * 1. FINAL THUMBNAIL (highest priority)
 *    - If final_thumbnail_url exists → render <img> immediately
 *    - No fade on initial render (prevents UI jank)
 *    - No icon or placeholder shown
 *    - Final thumbnails are permanent, full-quality, versioned
 * 
 * 2. PREVIEW THUMBNAIL (second priority)
 *    - If preview_thumbnail_url exists → render preview <img> immediately
 *    - Apply blur/pixelation styling to indicate it's temporary
 *    - Optional subtle spinner (top-right only) if still processing
 *    - Preview thumbnails are temporary, low-quality, shown during processing
 * 
 * 3. ICON (last resort)
 *    - Only render when NO thumbnails exist (unsupported format, failed, or not ready)
 *    - Never show icons if preview or final exists
 *    - Icons are a last resort, not a first choice
 * 
 * WHY THIS PRIORITY EXISTS:
 * -------------------------
 * - Prevents icon flash: Icons never render before checking for thumbnails
 * - Prevents green tiles: No placeholder images means no cached placeholders
 * - Enables clean preview→final swap: Different URLs ensure no cache collision
 * - Final always wins: Once final exists, preview is ignored
 * 
 * PREVIEW vs FINAL SEPARATION:
 * ----------------------------
 * - Preview and final URLs are distinct (different paths)
 * - Browser treats them as separate resources (no cache confusion)
 * - Preview can be replaced by final without cache issues
 * - Version query param on final ensures browser refetches when version changes
 * 
 * @param {Object} props
 * @param {Object} props.asset - Asset object with preview_thumbnail_url, final_thumbnail_url, thumbnail_status
 * @param {string} props.alt - Alt text for image
 * @param {string} props.className - CSS classes for container
 * @param {number} props.retryCount - Current retry count (for UI-only retry logic)
 * @param {Function} props.onRetry - Callback when retry is requested (UI only)
 * @param {string} props.size - Icon size ('sm', 'md', 'lg')
 * @param {string} props.thumbnailVersion - Stable version signal (derived from asset props)
 * @param {boolean} props.shouldAnimateThumbnail - If true, apply fade-in animation (only set on meaningful state transitions)
 */
import { useState, useEffect, useRef, useMemo } from 'react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
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
    const imgRef = useRef(null)
    
    // Get thumbnail state with preview and final URLs
    const { state, thumbnailUrl, previewThumbnailUrl, finalThumbnailUrl, canRetry } = useMemo(() => {
        return getThumbnailState(asset, retryCount)
    }, [asset?.id, thumbnailVersion, retryCount, asset?.preview_thumbnail_url, asset?.final_thumbnail_url])
    
    // Determine which thumbnail URL to use (final > preview > null)
    const activeThumbnailUrl = finalThumbnailUrl || previewThumbnailUrl || thumbnailUrl
    const isPreview = !finalThumbnailUrl && !!previewThumbnailUrl
    
    // Handle animation trigger from parent (meaningful state transition detected)
    useEffect(() => {
        if (shouldAnimateThumbnail && !animationCompletedRef.current) {
            setIsAnimating(true)
            const timer = setTimeout(() => {
                setIsAnimating(false)
                animationCompletedRef.current = true
            }, 500)
            return () => clearTimeout(timer)
        }
    }, [shouldAnimateThumbnail])
    
    // Reset animation state when thumbnail URL changes
    useEffect(() => {
        if (activeThumbnailUrl) {
            animationCompletedRef.current = false
            setIsAnimating(false)
            
            // Pre-check if image is already loaded (cached images may not fire onLoad)
            const img = new Image()
            let isHandled = false
            
            const handleLoad = () => {
                if (!isHandled) {
                    isHandled = true
                    setImageLoaded(true)
                    setImageError(false)
                }
            }
            
            const handleError = () => {
                if (!isHandled) {
                    isHandled = true
                    setImageError(true)
                    setImageLoaded(false)
                }
            }
            
            img.onload = handleLoad
            img.onerror = handleError
            img.src = activeThumbnailUrl
            
            // Check if image is already cached
            if (img.complete) {
                if (img.naturalWidth > 0 && img.naturalHeight > 0) {
                    setImageLoaded(true)
                    setImageError(false)
                } else {
                    setImageError(true)
                    setImageLoaded(false)
                }
            } else {
                setImageLoaded(false)
                setImageError(false)
            }
        } else {
            setImageLoaded(false)
            setImageError(false)
        }
    }, [activeThumbnailUrl, thumbnailVersion])
    
    const handleImageLoad = () => {
        setImageLoaded(true)
        setImageError(false)
    }
    
    const handleImageError = () => {
        console.error('[ThumbnailPreview] Runtime image error', {
            assetId: asset?.id,
            thumbnailUrl: activeThumbnailUrl,
            isPreview,
            state,
            note: 'Image failed to load - falling back to FileTypeIcon',
        })
        setImageError(true)
        setImageLoaded(false)
    }
    
    // Check if image is already loaded after render (handles cached images)
    useEffect(() => {
        if (activeThumbnailUrl && imgRef.current) {
            const img = imgRef.current
            if (img.complete) {
                if (img.naturalWidth > 0 && img.naturalHeight > 0) {
                    if (!imageLoaded) {
                        setImageLoaded(true)
                        setImageError(false)
                    }
                } else {
                    if (!imageError) {
                        setImageError(true)
                        setImageLoaded(false)
                    }
                }
            }
        }
    }, [activeThumbnailUrl, imageLoaded, imageError])
    
    // Extract file extension and MIME type for FileTypeIcon
    const fileExtension = asset?.file_extension || 
                         asset?.original_filename?.split('.').pop()?.toLowerCase() ||
                         asset?.file?.name?.split('.').pop()?.toLowerCase()
    const mimeType = asset?.mime_type || asset?.file?.type
    
    // ============================================================================
    // PRIORITY 1: FINAL THUMBNAIL (highest priority)
    // ============================================================================
    // If final thumbnail exists, render it immediately.
    // No icon, no placeholder, no fade on initial render.
    // Final thumbnails are permanent, full-quality, and versioned.
    // ============================================================================
    if (finalThumbnailUrl) {
        // Runtime error - fallback to icon
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
        
        // Render final thumbnail immediately
        return (
            <div className={`relative ${className}`}>
                {/* Icon shown only while loading */}
                {!imageLoaded && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-50">
                        <FileTypeIcon
                            fileExtension={fileExtension}
                            mimeType={mimeType}
                            size={size}
                        />
                    </div>
                )}
                
                {/* Final thumbnail - no blur, full quality */}
                <img
                    ref={imgRef}
                    src={finalThumbnailUrl}
                    alt={alt}
                    className="w-full h-full object-cover"
                    loading="eager"
                    style={{
                        opacity: imageLoaded && !imageError ? 1 : 0,
                        transition: isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'none',
                    }}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />
            </div>
        )
    }
    
    // ============================================================================
    // PRIORITY 2: PREVIEW THUMBNAIL (second priority)
    // ============================================================================
    // If preview thumbnail exists, render it immediately with blur/pixelation.
    // Preview thumbnails are temporary, low-quality, shown during processing.
    // Optional subtle spinner if still processing.
    // ============================================================================
    if (previewThumbnailUrl) {
        // Runtime error - fallback to icon
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
        
        // Check if thumbnail is still processing (show spinner)
        const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
        const isProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'
        const thumbnailExpected = supportsThumbnail(mimeType, fileExtension)
        
        return (
            <div className={`relative ${className}`}>
                {/* Icon shown only while loading */}
                {!imageLoaded && (
                    <div className="absolute inset-0 flex items-center justify-center bg-gray-50">
                        <FileTypeIcon
                            fileExtension={fileExtension}
                            mimeType={mimeType}
                            size={size}
                        />
                    </div>
                )}
                
                {/* Preview thumbnail - blur/pixelation to indicate temporary */}
                <img
                    ref={imgRef}
                    src={previewThumbnailUrl}
                    alt={alt}
                    className="w-full h-full object-cover"
                    loading="eager"
                    style={{
                        opacity: imageLoaded && !imageError ? 1 : 0,
                        filter: 'blur(2px)', // Blur to indicate it's temporary/preview
                        transition: isAnimating && imageLoaded ? 'opacity 500ms ease-out' : 'none',
                    }}
                    onLoad={handleImageLoad}
                    onError={handleImageError}
                />
                
                {/* Subtle spinner - top-right corner only, only when processing */}
                {isProcessing && thumbnailExpected && (
                    <div className="absolute top-2 right-2">
                        <svg 
                            className="h-4 w-4 text-gray-400 opacity-50 animate-spin" 
                            xmlns="http://www.w3.org/2000/svg" 
                            fill="none" 
                            viewBox="0 0 24 24"
                        >
                            <circle 
                                className="opacity-25" 
                                cx="12" 
                                cy="12" 
                                r="10" 
                                stroke="currentColor" 
                                strokeWidth="4"
                            />
                            <path 
                                className="opacity-75" 
                                fill="currentColor" 
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                    </div>
                )}
            </div>
        )
    }
    
    // ============================================================================
    // PRIORITY 3: ICON (last resort - only when NO thumbnails exist)
    // ============================================================================
    // Icons are only shown when:
    // - Unsupported format (NOT_SUPPORTED)
    // - Failed thumbnail generation (FAILED)
    // - Skipped thumbnail generation (SKIPPED)
    // - Pending/processing with no preview available (PENDING)
    // 
    // Icons are NEVER shown if preview or final thumbnails exist.
    // This prevents icon flash and ensures thumbnails always win.
    // ============================================================================
    
    // State A) NOT_SUPPORTED - File type doesn't support thumbnails
    if (state === 'NOT_SUPPORTED') {
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
    
    // State B) PENDING / PROCESSING - Thumbnail generation in progress
    if (state === 'PENDING') {
        const thumbnailExpected = supportsThumbnail(mimeType, fileExtension)
        
        return (
            <div className={`relative flex items-center justify-center bg-gray-50 ${className}`}>
                <FileTypeIcon
                    fileExtension={fileExtension}
                    mimeType={mimeType}
                    size={size}
                />
                {/* Subtle spinner - top-right corner only, only when thumbnail is expected */}
                {thumbnailExpected && (
                    <div className="absolute top-2 right-2">
                        <svg 
                            className="h-4 w-4 text-gray-400 opacity-50 animate-spin" 
                            xmlns="http://www.w3.org/2000/svg" 
                            fill="none" 
                            viewBox="0 0 24 24"
                        >
                            <circle 
                                className="opacity-25" 
                                cx="12" 
                                cy="12" 
                                r="10" 
                                stroke="currentColor" 
                                strokeWidth="4"
                            />
                            <path 
                                className="opacity-75" 
                                fill="currentColor" 
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                    </div>
                )}
            </div>
        )
    }
    
    // State C) FAILED - Thumbnail generation failed
    if (state === 'FAILED') {
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
    
    // State D) SKIPPED - Thumbnail generation skipped
    if (state === 'SKIPPED') {
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
    
    // Fallback: Should not reach here, but show icon if we do
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

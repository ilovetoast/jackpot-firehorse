/**
 * AssetImage Component
 * 
 * Reusable responsive image component for assets.
 * Uses srcset and sizes for responsive loading based on viewport size.
 * 
 * Features:
 * - Responsive image loading (only loads appropriate size for viewport)
 * - Retina support (automatically handled by browser with srcset)
 * - Fallback handling
 * - Lazy loading support
 * - Fade-in animation when image loads
 * 
 * Usage:
 * <AssetImage 
 *   assetId={asset.id}
 *   alt={asset.title}
 *   className="w-full h-full object-cover"
 *   containerWidth={400} // Expected container width for optimal size selection
 *   thumbnailUrl={asset.thumbnail_url} // Optional: for cache-busting detection
 * />
 * 
 * Responsive behavior:
 * - On small screens (< 640px): Loads 'thumb' (320px) - sufficient for mobile
 * - On large screens (>= 640px): Loads 'medium' (1024px) - good for desktop and retina
 * - Browser automatically selects the right size based on viewport and pixel density
 * 
 * @param {Object} props
 * @param {string} props.assetId - Asset UUID
 * @param {string} props.alt - Alt text for the image
 * @param {string} props.className - CSS classes for the img element
 * @param {number} props.containerWidth - Expected container width in pixels (for sizes attribute)
 * @param {boolean} props.lazy - Enable lazy loading (default: true)
 * @param {string} props.fallback - Fallback image URL if thumbnail fails
 * @param {string} props.thumbnailUrl - Thumbnail URL (for cache-busting detection, triggers re-fade)
 */
import { useState, useEffect, useRef } from 'react'

export default function AssetImage({
    assetId,
    alt = 'Asset',
    className = '',
    containerWidth = 400,
    lazy = true,
    fallback = null,
    thumbnailUrl = null,
}) {
    // Track if we initially had a thumbnail URL (no fade on initial render)
    const hadInitialThumbnailRef = useRef(!!thumbnailUrl)
    // Track if we've transitioned from no thumbnail to having a thumbnail (should fade in)
    const [shouldFadeIn, setShouldFadeIn] = useState(false)
    const [isLoaded, setIsLoaded] = useState(false)
    const [hasError, setHasError] = useState(false)
    const lastThumbnailUrlRef = useRef(thumbnailUrl)

    if (!assetId) {
        return null
    }

    // Only fade in when thumbnail_url transitions from null/undefined to a value
    // Don't fade in on cache-busting or if thumbnail existed initially
    useEffect(() => {
        const hadThumbnailBefore = !!lastThumbnailUrlRef.current
        const hasThumbnailNow = !!thumbnailUrl
        
        // If we transition from no thumbnail to having a thumbnail, enable fade-in
        if (!hadThumbnailBefore && hasThumbnailNow) {
            setShouldFadeIn(true)
        }
        
        lastThumbnailUrlRef.current = thumbnailUrl
    }, [thumbnailUrl])

    // Base URL for thumbnail endpoint
    const baseUrl = `/app/assets/${assetId}/thumbnail`

    // Build srcset with width descriptors
    // thumb: 320px, medium: 1024px
    // Browser will automatically select the right image based on:
    // - Viewport size (from sizes attribute)
    // - Pixel density (retina displays get higher resolution)
    const srcset = [
        `${baseUrl}/thumb 320w`,
        `${baseUrl}/medium 1024w`,
    ].join(', ')

    // sizes attribute tells browser which image to load based on viewport
    // Format: (media query) size, (media query) size, default size
    // For ~400px container:
    // - On screens < 640px (mobile): use thumb (320px is sufficient)
    // - On screens >= 640px (tablet/desktop): use medium (1024px handles retina well)
    // Browser automatically accounts for pixel density (retina = 2x)
    const sizes = `(max-width: 639px) 320px, ${containerWidth}px`

    // Default src (fallback for older browsers or if srcset fails)
    // Use thumb as default (smallest, fastest to load)
    const defaultSrc = `${baseUrl}/thumb`

    const handleLoad = () => {
        setIsLoaded(true)
        setHasError(false)
    }

    const handleError = (e) => {
        setHasError(true)
        // Fallback to provided fallback image if available
        if (fallback && e.currentTarget.src !== fallback) {
            e.currentTarget.src = fallback
            setHasError(false) // Retry with fallback
        }
    }

    // SVG placeholder for missing images (image icon)
    const placeholderSvg = `data:image/svg+xml,${encodeURIComponent(`
        <svg width="400" height="300" xmlns="http://www.w3.org/2000/svg">
            <rect width="400" height="300" fill="#f3f4f6"/>
            <g fill="#9ca3af" opacity="0.5">
                <rect x="140" y="90" width="120" height="100" rx="4" stroke="#9ca3af" stroke-width="2" fill="none"/>
                <circle cx="180" cy="125" r="12"/>
                <path d="M140 160 L160 140 L200 140 L220 160 L220 180 L140 180 Z" fill="#9ca3af"/>
            </g>
        </svg>
    `)}`

    // Derive visibility from thumbnail existence (not from onLoad event)
    const hasValidThumbnail = Boolean(thumbnailUrl)
    const showImage = hasValidThumbnail && !hasError

    // Show placeholder if image failed to load
    if (hasError && !fallback) {
        return (
            <img
                src={placeholderSvg}
                alt={alt}
                className={className}
            />
        )
    }

    return (
        <>
            {/* Placeholder background (only shown when no valid thumbnail exists) */}
            {!hasValidThumbnail && (!isLoaded || hasError) && (
                <img
                    src={placeholderSvg}
                    alt=""
                    className={className}
                    style={{
                        position: 'absolute',
                        inset: 0,
                        opacity: 1,
                    }}
                    aria-hidden="true"
                />
            )}
            <img
                src={defaultSrc}
                srcSet={srcset}
                sizes={sizes}
                alt={alt}
                className={className}
                style={{
                    position: 'relative',
                    opacity: showImage ? 1 : 0,
                    filter: showImage ? 'none' : 'blur(4px)',
                    transform: showImage ? 'scale(1)' : 'scale(1.05)',
                    // Transition for fade-in when image loads (enhancement only, doesn't block visibility)
                    transition: shouldFadeIn ? 'opacity 500ms ease-out, filter 500ms ease-out, transform 500ms ease-out' : 'opacity 200ms ease',
                }}
                loading={lazy ? 'lazy' : 'eager'}
                onLoad={handleLoad}
                onError={handleError}
            />
        </>
    )
}
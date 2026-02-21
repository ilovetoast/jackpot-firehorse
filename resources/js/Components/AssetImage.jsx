/**
 * AssetImage Component
 *
 * Renders asset thumbnails using fully resolved URLs from the backend (AssetDeliveryService).
 * Never constructs URLs — always uses thumbnailUrl/thumbnailUrlLarge from API payload.
 *
 * Usage:
 * <AssetImage
 *   thumbnailUrl={asset.final_thumbnail_url ?? asset.thumbnail_url ?? asset.preview_thumbnail_url}
 *   thumbnailUrlLarge={asset.thumbnail_url_large}
 *   alt={asset.title}
 *   className="w-full h-full object-contain"
 *   containerWidth={448}
 * />
 *
 * @param {Object} props
 * @param {string} props.thumbnailUrl - Resolved thumbnail URL from backend (required for display)
 * @param {string} props.thumbnailUrlLarge - Optional large variant for srcset (drawer/zoom)
 * @param {string} props.alt - Alt text for the image
 * @param {string} props.className - CSS classes for the img element
 * @param {number} props.containerWidth - Expected container width in pixels (for sizes attribute)
 * @param {boolean} props.lazy - Enable lazy loading (default: true)
 * @param {string} props.fallback - Fallback image URL if thumbnail fails
 */
import { useState, useEffect, useRef } from 'react'

export default function AssetImage({
    thumbnailUrl,
    thumbnailUrlLarge = null,
    alt = 'Asset',
    className = '',
    containerWidth = 400,
    lazy = true,
    fallback = null,
}) {
    const [shouldFadeIn, setShouldFadeIn] = useState(false)
    const [isLoaded, setIsLoaded] = useState(false)
    const [hasError, setHasError] = useState(false)
    const lastThumbnailUrlRef = useRef(thumbnailUrl)

    useEffect(() => {
        const hadThumbnailBefore = !!lastThumbnailUrlRef.current
        const hasThumbnailNow = !!thumbnailUrl

        if (!hadThumbnailBefore && hasThumbnailNow) {
            setShouldFadeIn(true)
        }

        lastThumbnailUrlRef.current = thumbnailUrl
    }, [thumbnailUrl])

    const handleLoad = () => {
        setIsLoaded(true)
        setHasError(false)
    }

    const handleError = (e) => {
        setHasError(true)
        if (fallback && e.currentTarget.src !== fallback) {
            e.currentTarget.src = fallback
            setHasError(false)
        }
    }

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

    const hasValidThumbnail = Boolean(thumbnailUrl)
    const showImage = hasValidThumbnail && !hasError

    if (thumbnailUrl) {
        console.log('IMAGE URL FROM API:', thumbnailUrl)
    }
    if (thumbnailUrlLarge) {
        console.log('IMAGE LARGE URL FROM API:', thumbnailUrlLarge)
    }

    if (!thumbnailUrl) {
        return (
            <img
                src={placeholderSvg}
                alt={alt}
                className={className}
                aria-hidden="true"
            />
        )
    }

    if (hasError && !fallback) {
        return (
            <img
                src={placeholderSvg}
                alt={alt}
                className={className}
            />
        )
    }

    const srcset = thumbnailUrlLarge
        ? `${thumbnailUrl} 1024w, ${thumbnailUrlLarge} 4096w`
        : undefined

    const sizes = thumbnailUrlLarge
        ? `(max-width: 639px) 1024px, ${containerWidth}px`
        : undefined

    return (
        <>
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
                src={thumbnailUrl}
                srcSet={srcset}
                sizes={sizes}
                alt={alt}
                className={className}
                style={{
                    position: 'relative',
                    opacity: showImage ? 1 : 0,
                    filter: showImage ? 'none' : 'blur(4px)',
                    transform: showImage ? 'scale(1)' : 'scale(1.05)',
                    transition: shouldFadeIn ? 'opacity 500ms ease-out, filter 500ms ease-out, transform 500ms ease-out' : 'opacity 200ms ease',
                }}
                loading={lazy ? 'lazy' : 'eager'}
                onLoad={handleLoad}
                onError={handleError}
            />
        </>
    )
}

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
 * 
 * Usage:
 * <AssetImage 
 *   assetId={asset.id}
 *   alt={asset.title}
 *   className="w-full h-full object-cover"
 *   containerWidth={400} // Expected container width for optimal size selection
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
 */
export default function AssetImage({
    assetId,
    alt = 'Asset',
    className = '',
    containerWidth = 400,
    lazy = true,
    fallback = null,
}) {
    if (!assetId) {
        return null
    }

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

    return (
        <img
            src={defaultSrc}
            srcSet={srcset}
            sizes={sizes}
            alt={alt}
            className={className}
            loading={lazy ? 'lazy' : 'eager'}
            onError={(e) => {
                // Fallback to provided fallback image or placeholder
                if (fallback && e.currentTarget.src !== fallback) {
                    e.currentTarget.src = fallback
                }
            }}
        />
    )
}
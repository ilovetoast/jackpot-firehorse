import GifPlaybackOverlay from './GifPlaybackOverlay'
import { useAnimatedGifPlayback, isGifRasterAsset } from '../hooks/useAnimatedGifPlayback'

/**
 * Full-stage lightbox raster: still image with optional animated-GIF play (poster pipeline URL vs original file).
 */
export default function LightboxRasterImage({
    asset,
    posterUrl,
    transitionDirection,
    alt,
    onImageLoad,
    onImageError,
}) {
    const trimmed = String(posterUrl || '').trim()
    const gif = useAnimatedGifPlayback({
        enabled: isGifRasterAsset(asset),
        asset,
        posterUrl: trimmed,
    })

    if (!trimmed) {
        return null
    }

    const src = gif.displaySrc || trimmed

    return (
        <div className="relative flex max-h-full max-w-full items-center justify-center">
            <img
                key={`${asset?.id}-lb-${trimmed.slice(0, 96)}-${gif.playing ? 'anim' : 'poster'}`}
                src={src}
                alt={alt}
                className="h-auto w-auto max-h-full max-w-full object-contain transition-all duration-300 ease-in-out"
                style={{
                    transform:
                        transitionDirection === 'left'
                            ? 'translateX(30px)'
                            : transitionDirection === 'right'
                              ? 'translateX(-30px)'
                              : 'translateX(0)',
                    opacity: transitionDirection ? 0 : 1,
                }}
                onLoad={() => onImageLoad?.()}
                onError={() => onImageError?.()}
            />
            {gif.showPlayback ? (
                <GifPlaybackOverlay variant="lightbox" playing={gif.playing} onToggle={gif.toggle} />
            ) : null}
        </div>
    )
}

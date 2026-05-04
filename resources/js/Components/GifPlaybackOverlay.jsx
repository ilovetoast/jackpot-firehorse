import { PlayIcon, PauseIcon } from '@heroicons/react/24/solid'

/**
 * Play / pause control for animated GIF poster + full-file swap (drawer + lightbox).
 *
 * @param {object} props
 * @param {boolean} props.playing
 * @param {() => void} props.onToggle
 * @param {'drawer'|'lightbox'} [props.variant]
 */
export default function GifPlaybackOverlay({ playing, onToggle, variant = 'drawer' }) {
    const isLightbox = variant === 'lightbox'
    return (
        <button
            type="button"
            onClick={(e) => {
                e.stopPropagation()
                e.preventDefault()
                onToggle()
            }}
            className={
                isLightbox
                    ? 'pointer-events-auto absolute bottom-16 right-4 z-30 flex h-12 w-12 items-center justify-center rounded-full bg-black/55 text-white shadow-lg ring-1 ring-white/25 backdrop-blur-sm transition hover:bg-black/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80 md:bottom-24'
                    : 'pointer-events-auto absolute bottom-3 right-3 z-30 flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white shadow-md ring-1 ring-black/20 backdrop-blur-sm transition hover:bg-black/65 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2'
            }
            aria-label={playing ? 'Pause GIF animation' : 'Play GIF animation'}
            title={playing ? 'Pause' : 'Play'}
        >
            {playing ? (
                <PauseIcon className={isLightbox ? 'h-6 w-6' : 'h-5 w-5'} aria-hidden />
            ) : (
                <PlayIcon className={isLightbox ? 'h-6 w-6 pl-0.5' : 'h-5 w-5 pl-0.5'} aria-hidden />
            )}
        </button>
    )
}

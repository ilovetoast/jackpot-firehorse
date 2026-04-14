/**
 * CSS-only presentation presets for execution grids / drawer (no AI, no raster export).
 *
 * Visual intent: **top-down / flat-lay** — the Studio (or source) image reads like a print on a surface,
 * not a card inside a second rounded “bubble”. No extra vertical wash: transparent pixels show the texture.
 *
 * ## Optional surface textures
 *
 * Add images under **`public/img/`** (URLs are `/img/...`):
 *
 * - `presentation-neutral-texture.jpg` — neutral_studio
 * - `presentation-desk-texture.jpg` — desk_surface
 * - `presentation-wall-texture.jpg` — wall_pin
 *
 * Omit any file to use the solid surface color only. Use JPG/PNG/WebP; wide photos work well (`cover`).
 *
 * @param {Object} props
 * @param {string|null|undefined} props.imageUrl
 * @param {'neutral_studio'|'desk_surface'|'wall_pin'} [props.preset]
 * @param {string} [props.className]
 * @param {'default'|'tile'} [props.variant] `tile` = small fixed-height slots (drawer tiles); tighter padding and `max-h-full` on the image.
 */
export default function ExecutionPresentationFrame({
    imageUrl,
    preset = 'neutral_studio',
    className = '',
    variant = 'default',
}) {
    const p = preset === 'desk_surface' ? 'desk_surface' : preset === 'wall_pin' ? 'wall_pin' : 'neutral_studio'

    const textureUrl =
        p === 'desk_surface'
            ? '/img/presentation-desk-texture.jpg'
            : p === 'wall_pin'
              ? '/img/presentation-wall-texture.jpg'
              : '/img/presentation-neutral-texture.jpg'

    const surfaceBase =
        p === 'desk_surface'
            ? '#c9b8a4'
            : p === 'wall_pin'
              ? '#b8bcc4'
              : '#d6d4d0'

    /* No vertical wash overlay: transparent Studio/WebP pixels must read against texture + base only. */

    /* Desk reads as a loose print on wood — a touch more angle than wall/neutral. */
    const imageTilt = p === 'desk_surface' ? 'rotate-[-2.25deg]' : p === 'wall_pin' ? 'rotate-[0.85deg]' : ''
    const tile = variant === 'tile'

    /**
     * Drop-shadow follows non-transparent pixels (unlike box-shadow + ring on the img box,
     * which drew a visible “plate” around letterboxed transparent margins).
     */
    const imageLift = 'drop-shadow-[0_2px_5px_rgba(0,0,0,0.22)]'

    const emptyState = (
        <div
            className={`relative flex h-full min-h-[120px] w-full items-center justify-center overflow-hidden text-center text-xs text-gray-700 ${className}`}
            style={{ backgroundColor: surfaceBase }}
        >
            <div
                className="pointer-events-none absolute inset-0 bg-cover bg-center opacity-[0.38] mix-blend-multiply"
                style={{ backgroundImage: `url(${textureUrl})` }}
                aria-hidden
            />
            <span className="relative z-[1] px-3">No preview image</span>
        </div>
    )

    if (!imageUrl) {
        return emptyState
    }

    return (
        <div
            className={`relative flex h-full min-h-0 w-full items-center justify-center overflow-hidden ${
                tile ? 'p-0.5' : 'px-1.5 py-1.5 sm:px-2 sm:py-2'
            } ${className}`}
            style={{ backgroundColor: surfaceBase }}
        >
            <div
                className="pointer-events-none absolute inset-0 bg-cover bg-center opacity-[0.38] mix-blend-multiply"
                style={{ backgroundImage: `url(${textureUrl})` }}
                aria-hidden
            />
            <img
                src={imageUrl}
                alt=""
                className={`relative z-[1] max-w-full object-contain ${imageLift} ${
                    tile ? 'max-h-full' : 'max-h-[min(280px,55vh)]'
                } ${imageTilt}`}
                loading="lazy"
            />
        </div>
    )
}

/**
 * Sizing helpers for `AudioCardVisual` — extracted as a pure module so the
 * grid-collapse bug we hit on staging (audio tiles rendering as fully white
 * cards) is locked behind unit tests, not just visual review.
 *
 * The bug: AudioCardVisual paints itself with `h-full`. In masonry layout
 * the AssetCard parent only sets `min-height` (no explicit `height`). A CSS
 * percentage height needs a *definite* parent height to resolve — so the
 * audio component collapsed to 0px and the parent's `bg-gray-50` showed
 * through, reading as a white card.
 *
 * Two layered defenses:
 *   1. {@link audioMasonryWrapperStyle} — at the AssetCard call site we wrap
 *      AudioCardVisual in a div with explicit `height` (matching the masonry
 *      tile target), the same trick ThumbnailPreview already uses for its
 *      placeholder.
 *   2. {@link audioCardIntrinsicMinHeightPx} — AudioCardVisual itself sets a
 *      sensible `min-height` floor so it can never paint into a 0px box even
 *      when a parent forgets the wrapper.
 */

/**
 * Wrapper style for AudioCardVisual when the asset card is in masonry
 * layout. Returns `null` for non-masonry layouts (the grid path uses
 * `aspect-[4/3]` so percentage heights resolve fine).
 *
 * @param {object} opts
 * @param {boolean} opts.isMasonry
 * @param {number|undefined} opts.masonryThumbnailMinHeightPx
 * @param {number|undefined} opts.masonryMaxHeightPx
 * @returns {{ height: number, maxHeight: number, width: string }|null}
 */
export function audioMasonryWrapperStyle({
    isMasonry,
    masonryThumbnailMinHeightPx,
    masonryMaxHeightPx,
}) {
    if (!isMasonry) return null
    const min = Number(masonryThumbnailMinHeightPx)
    const max = Number(masonryMaxHeightPx)
    if (!Number.isFinite(min) || min <= 0) return null
    const height = Number.isFinite(max) && max > 0 ? Math.min(min, max) : min
    const maxHeight = Number.isFinite(max) && max > 0 ? max : height
    return { height, maxHeight, width: '100%' }
}

/**
 * Intrinsic min-height (px) for AudioCardVisual itself, by size variant.
 * Used as a belt-and-braces floor so the component is always visible
 * regardless of parent layout.
 *
 * @param {'card'|'drawer'|'lightbox'} size
 * @returns {number}
 */
export function audioCardIntrinsicMinHeightPx(size) {
    if (size === 'lightbox') return 220
    if (size === 'drawer') return 160
    return 140
}

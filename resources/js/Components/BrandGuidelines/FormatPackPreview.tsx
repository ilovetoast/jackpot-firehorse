/**
 * FormatPackPreview
 *
 * Given a recipe key + brand ad style + a {@link FormatPack}, render one
 * {@link RecipePreview} per size in the pack, each sized to preserve the
 * target format's aspect ratio while fitting inside a shared bounding box.
 *
 * Why this lives as its own component:
 *   The RecipePreview already handles blueprint → positioned-div render; all
 *   FormatPackPreview adds is the *layout math* that turns a pack's
 *   `{width, height}` sizes into a grid of differently-shaped thumbnails
 *   that still reads as a single, coherent matrix. That math is non-trivial
 *   (each tile fits within `maxTileDim` while keeping its aspect), so it
 *   deserves its own component rather than being inlined everywhere a pack
 *   preview is needed.
 *
 * Why NOT memoize on style:
 *   Recipes compose in sub-millisecond; wrapping in useMemo adds complexity
 *   for no measurable benefit and blocks the "flip a brand DNA setting → see
 *   all 15 thumbnails repaint instantly" interaction that makes this
 *   component useful.
 */

import RecipePreview from './RecipePreview'
import type { BrandAdStyle, FormatPack, RecipeContent, RecipeKey } from '../../Pages/Editor/recipes'

export type FormatPackPreviewProps = {
    recipeKey: RecipeKey
    style: BrandAdStyle
    pack: FormatPack
    /** Content passed to every preview (same seeded placeholder across tiles). */
    content?: RecipeContent
    /**
     * Max pixel dimension for any single thumbnail (along its longest edge).
     * Super-wide banner sizes in the IAB pack will hit this on width; tall
     * skyscrapers will hit it on height. Default 180.
     */
    maxTileDim?: number
    /** Tailwind gap between tiles. Default `gap-3`. */
    gapClass?: string
}

/**
 * Scale a format's (width, height) so its longest edge equals `maxTileDim`.
 * Small formats (narrower than maxTileDim on both edges) are scaled *up* so
 * they don't look dwarfed next to larger formats in the grid.
 */
function scaleToFit(width: number, height: number, maxTileDim: number): { w: number; h: number } {
    const longest = Math.max(width, height)
    const scale = maxTileDim / longest
    return {
        w: Math.round(width * scale),
        h: Math.round(height * scale),
    }
}

export default function FormatPackPreview({
    recipeKey,
    style,
    pack,
    content,
    maxTileDim = 180,
    gapClass = 'gap-4',
}: FormatPackPreviewProps) {
    return (
        <div className={`flex flex-wrap ${gapClass}`}>
            {pack.sizes.map((size, i) => {
                const { w, h } = scaleToFit(size.width, size.height, maxTileDim)
                return (
                    <div
                        key={`${size.width}x${size.height}-${i}`}
                        className="flex flex-col items-center gap-1.5"
                    >
                        <RecipePreview
                            recipeKey={recipeKey}
                            style={style}
                            width={w}
                            height={h}
                            content={content}
                            label={`${size.label} at ${size.width}×${size.height}`}
                            className="shadow ring-1 ring-white/10"
                        />
                        <div className="text-center leading-tight">
                            <div className="text-[11px] font-medium text-white/80">{size.label}</div>
                            <div className="text-[10px] text-white/40">
                                {size.width}×{size.height}
                            </div>
                        </div>
                    </div>
                )
            })}
        </div>
    )
}

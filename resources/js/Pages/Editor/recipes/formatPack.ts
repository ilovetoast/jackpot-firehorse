/**
 * Brand-DNA Ad Recipes — Format Pack subsystem.
 *
 * A Format Pack is an ordered list of `{ width, height, label }` sizes that
 * a single recipe can be rendered into. Inspired by Lurvey's Spring Blooms
 * mass-deliverable (1 creative × 15 sizes).
 *
 * For the MVP the Pack types + canonical lists ship here, but the
 * bulk-render UI is deferred to a later phase. Downstream consumers can use
 * {@link renderRecipeAcrossPack} to compose the same recipe at each target
 * size — useful for preview grids and future "Export Pack" flows.
 */

import type { RecipeKey } from './types'
import { composeRecipe } from './registry'
import type { RecipeInput, RecipeOutput } from './types'

export type FormatPackSize = {
    /** Pixel width of the target canvas. */
    width: number
    /** Pixel height of the target canvas. */
    height: number
    /** Display label (e.g. "Facebook Feed Square"). */
    label: string
}

export type FormatPack = {
    id: string
    name: string
    description: string
    sizes: FormatPackSize[]
}

// ── Canonical packs ──────────────────────────────────────────────

/**
 * IAB standard display sizes. Leaderboard through skyscraper + mobile
 * banner. Intended for programmatic display buys.
 */
export const IAB_STANDARD_PACK: FormatPack = {
    id: 'iab_standard',
    name: 'IAB Standard',
    description: 'Programmatic display — Leaderboard, MREC, Skyscraper, Mobile Banner.',
    sizes: [
        { width: 970, height: 250, label: 'Billboard' },
        { width: 728, height: 90, label: 'Leaderboard' },
        { width: 300, height: 250, label: 'Medium Rectangle' },
        { width: 300, height: 600, label: 'Half Page' },
        { width: 160, height: 600, label: 'Wide Skyscraper' },
        { width: 320, height: 50, label: 'Mobile Banner' },
        { width: 320, height: 100, label: 'Large Mobile' },
    ],
}

/**
 * Social-media aspect pack. Covers Instagram / Facebook / LinkedIn / X.
 */
export const SOCIAL_PACK: FormatPack = {
    id: 'social',
    name: 'Social Media',
    description: 'Instagram / Facebook / LinkedIn / X feed + story sizes.',
    sizes: [
        { width: 1080, height: 1080, label: 'Feed Square' },
        { width: 1080, height: 1350, label: 'Feed Portrait' },
        { width: 1080, height: 1920, label: 'Story / Reel' },
        { width: 1200, height: 628, label: 'Link Card' },
        { width: 1200, height: 675, label: 'X Post' },
        { width: 1200, height: 1500, label: 'Pinterest Pin' },
    ],
}

/**
 * Lurvey-style comprehensive pack — union of IAB + Social + large programmatic
 * extras. Matches the Spring Blooms deliverable grid as closely as practical.
 */
export const COMPREHENSIVE_PACK: FormatPack = {
    id: 'comprehensive',
    name: 'Comprehensive Campaign',
    description: 'Every major display + social size in one export (Lurvey-style deliverable).',
    sizes: [
        ...SOCIAL_PACK.sizes,
        ...IAB_STANDARD_PACK.sizes,
        { width: 1920, height: 1080, label: 'Full HD Banner' },
        { width: 1024, height: 768, label: '4:3 Landscape' },
        { width: 768, height: 1024, label: '4:3 Portrait' },
        { width: 480, height: 320, label: 'Medium Mobile' },
        { width: 608, height: 240, label: 'Wide Mobile' },
        { width: 458, height: 240, label: 'Inline Mobile' },
        { width: 320, height: 480, label: 'Mobile Interstitial' },
        { width: 100, height: 600, label: 'Narrow Sky' },
    ],
}

export const FORMAT_PACKS: ReadonlyArray<FormatPack> = [IAB_STANDARD_PACK, SOCIAL_PACK, COMPREHENSIVE_PACK]

export function getFormatPack(id: string): FormatPack | undefined {
    return FORMAT_PACKS.find((p) => p.id === id)
}

// ── Rendering across a pack ──────────────────────────────────────

export type FormatPackRenderResult = {
    size: FormatPackSize
    output: RecipeOutput
}

/**
 * Compose a single recipe across every size in a Format Pack. Returns a
 * flat list so callers can render a preview grid (or ship to an exporter).
 *
 * Per-recipe reflow rules live inside each recipe (by inspecting `format`
 * and adapting blueprint ratios). A future phase adds an explicit
 * `reflow()` hook on recipes for more sophisticated anchor-based layout
 * changes (logo always bottom-left with 5% margin regardless of size, etc.).
 */
export function renderRecipeAcrossPack(
    recipeKey: RecipeKey,
    input: Omit<RecipeInput, 'format'>,
    pack: FormatPack,
): FormatPackRenderResult[] {
    return pack.sizes.map((size) => ({
        size,
        output: composeRecipe(recipeKey, {
            ...input,
            format: { width: size.width, height: size.height },
        }),
    }))
}

/**
 * Brand-DNA Ad Recipes — registry + composition entrypoint.
 *
 * Recipes live here as an immutable map keyed by {@link RecipeKey}. Callers
 * use {@link composeRecipe} to run a recipe against brand DNA + content, and
 * {@link getRecipeDescriptor} to look up metadata (name, description, icon)
 * for pickers / wizards.
 *
 * Adding a new recipe means:
 *   1. Implement the recipe as a `Recipe` function in its own file.
 *   2. Add its `RecipeKey` to the union in `./types.ts`.
 *   3. Register the descriptor here.
 */

import type { Recipe, RecipeDescriptor, RecipeInput, RecipeKey, RecipeOutput } from './types'
import { monochromaticProductHero } from './monochromaticProductHero'
import { photoBgWordmarkFooter } from './photoBgWordmarkFooter'
import { lifestyleAction } from './lifestyleAction'
import { boldDisplayTech } from './boldDisplayTech'
import { heritageTexturedCta } from './heritageTexturedCta'
import { framedShowcase } from './framedShowcase'
import { bannerSpread } from './bannerSpread'
import { boldStatementAction } from './boldStatementAction'
import { illustratedEventPoster } from './illustratedEventPoster'
import { splitPanelCopyPhoto } from './splitPanelCopyPhoto'
import { productLineupSpotlight } from './productLineupSpotlight'
import { explodedDiagram } from './explodedDiagram'
import { dualProductRetailAnnounce } from './dualProductRetailAnnounce'

/**
 * All recipes currently implemented. Keys not listed here are "reserved" in
 * the type union but don't yet have an implementation — the compiler will
 * stop you from registering a descriptor that doesn't exist, but won't stop
 * you from referencing a not-yet-implemented key (by design — new recipes
 * land one by one, and the wizard filters out unavailable ones at runtime).
 */
const RECIPES: Partial<Record<RecipeKey, RecipeDescriptor>> = {
    monochromatic_product_hero: {
        key: 'monochromatic_product_hero',
        name: 'Monochromatic Product Hero',
        description: 'Brand-hue background with a ghost/filled headline pair, holding rectangle, and centered product.',
        category: 'product',
        icon: 'product',
        build: monochromaticProductHero,
    },
    photo_bg_wordmark_footer: {
        key: 'photo_bg_wordmark_footer',
        name: 'Photo Background w/ Footer Bar',
        description: 'Full-bleed photography with a stacked display headline and a white footer bar carrying the logo + CTA.',
        category: 'brand',
        icon: 'brand',
        build: photoBgWordmarkFooter,
    },
    lifestyle_action: {
        key: 'lifestyle_action',
        name: 'Lifestyle / Event',
        description: 'Full-bleed action photo with a heritage italic headline, date block, and sponsor logo strip. Best for events, launches, and sponsorship moments.',
        category: 'event',
        icon: 'event',
        build: lifestyleAction,
    },
    bold_display_tech: {
        key: 'bold_display_tech',
        name: 'Tech Reveal',
        description: 'Near-black canvas with a brand-hue glow behind a centered product, a thin display headline, and an optional spec strip.',
        category: 'tech',
        icon: 'tech',
        build: boldDisplayTech,
    },
    heritage_textured_cta: {
        key: 'heritage_textured_cta',
        name: 'Heritage / Craft',
        description: 'Warm textured background, script + caps headline, ornamented stamp around the product, and an outlined CTA. For launches and heritage moments.',
        category: 'brand',
        icon: 'brand',
        build: heritageTexturedCta,
    },
    framed_showcase: {
        key: 'framed_showcase',
        name: 'Framed Showcase',
        description: 'Editorial / lookbook layout — paper background, hairline frame around the product, tracked-caps tag above and product name below. Deliberately quiet.',
        category: 'product',
        icon: 'product',
        build: framedShowcase,
    },
    banner_spread: {
        key: 'banner_spread',
        name: 'Banner Spread',
        description: 'Wide-format banner — stacked copy + inline CTA on the left, product hero on the right, brand-hue background. Auto-stacks to portrait/square when misused.',
        category: 'retail',
        icon: 'retail',
        build: bannerSpread,
    },
    bold_statement_action: {
        key: 'bold_statement_action',
        name: 'Bold Statement',
        description: 'Full-bleed action photo with a single commanding statement and optional outlined CTA. Manifesto / campaign-tagline creative.',
        category: 'lifestyle',
        icon: 'lifestyle',
        build: boldStatementAction,
    },
    illustrated_event_poster: {
        key: 'illustrated_event_poster',
        name: 'Illustrated Event Poster',
        description: 'Paper background with an engraved hairline border, ghost/filled headline, oversized centered date, and sponsor row. Heritage event one-sheet.',
        category: 'event',
        icon: 'event',
        build: illustratedEventPoster,
    },
    split_panel_copy_photo: {
        key: 'split_panel_copy_photo',
        name: 'Split Panel',
        description: '50/50 split — brand-hue copy panel with tagline/headline/CTA on one side, full-bleed photo on the other. Classic editorial lookbook ad.',
        category: 'product',
        icon: 'product',
        build: splitPanelCopyPhoto,
    },
    product_lineup_spotlight: {
        key: 'product_lineup_spotlight',
        name: 'Product Lineup',
        description: 'Hero product centered with 2–4 supporting SKUs flanking at reduced scale. Collection tag + CTA. For launches and "new flavors" announcements.',
        category: 'product',
        icon: 'product',
        build: productLineupSpotlight,
    },
    exploded_diagram: {
        key: 'exploded_diagram',
        name: 'Exploded Diagram',
        description: 'Tech-reveal with numbered callout chips radiating around a centered product. Brand-hue glow, near-black canvas, thin product name. Spec-forward.',
        category: 'tech',
        icon: 'tech',
        build: explodedDiagram,
    },
    dual_product_retail_announce: {
        key: 'dual_product_retail_announce',
        name: 'Retail Announce',
        description: 'Two product heroes with an announcement headline ("Now at Target") and a retailer logo strip. For channel-launch and retail moment ads.',
        category: 'retail',
        icon: 'retail',
        build: dualProductRetailAnnounce,
    },
}

/**
 * Public registry — only contains recipes that have a real implementation.
 */
export const RECIPE_REGISTRY: ReadonlyArray<RecipeDescriptor> = Object.values(RECIPES).filter(
    (v): v is RecipeDescriptor => !!v,
)

/**
 * Look up a recipe descriptor by key. Returns `undefined` for keys that are
 * declared in the type union but not yet implemented — callers should treat
 * that as "recipe not available in this build" and fall back to a classic
 * layout style.
 */
export function getRecipeDescriptor(key: RecipeKey): RecipeDescriptor | undefined {
    return RECIPES[key]
}

/**
 * Resolve a recipe implementation by key. Throws if the key isn't registered
 * — callers should guard with {@link getRecipeDescriptor} when the key came
 * from user input.
 */
export function getRecipe(key: RecipeKey): Recipe {
    const d = RECIPES[key]
    if (!d) throw new Error(`Recipe "${key}" is not implemented`)
    return d.build
}

/**
 * Run a recipe end-to-end. Returns `LayerBlueprint[]` + metadata that the
 * caller can feed straight into `blueprintToLayersAndGroups`.
 */
export function composeRecipe(key: RecipeKey, input: RecipeInput): RecipeOutput {
    return getRecipe(key)(input)
}

/**
 * Is this recipe implemented and runnable for a given format? Currently a
 * thin wrapper around `getRecipeDescriptor` — later we'll gate on
 * aspect-ratio support (e.g. `exploded_diagram` only for tall formats).
 */
export function isRecipeAvailable(key: RecipeKey): boolean {
    return getRecipeDescriptor(key) !== undefined
}

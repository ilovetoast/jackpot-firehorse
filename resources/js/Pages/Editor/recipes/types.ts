/**
 * Brand-DNA Ad Recipes — types.
 *
 * Recipes are parameterized blueprint factories that take a brand + content +
 * target format and emit a list of `LayerBlueprint` that the existing Studio
 * materializer ({@see blueprintToLayersAndGroups}) knows how to turn into
 * concrete layers. They live alongside the existing `LAYOUT_STYLES` in
 * `templateConfig.ts` — the wizard can offer either.
 *
 * The intent is that recipes encode the *design grammar* of real-world ad
 * references (Shefit, Lurvey, Augusta, etc.) so a user picks "Monochromatic
 * Product Hero" once and any brand's primary color + logo + hero photo flow
 * through it automatically.
 */

import type { LayerBlueprint } from '../templateConfig'

/**
 * Stable keys for the recipe registry. Adding a recipe means:
 *   1. Add its key here.
 *   2. Implement the recipe as a `Recipe` function.
 *   3. Register it in `registry.ts`.
 *   4. (optional) Expose it in the wizard by surfacing it in `LAYOUT_STYLES`
 *      via {@see recipesAsLayoutStyles}.
 */
export type RecipeKey =
    | 'monochromatic_product_hero'
    | 'photo_bg_wordmark_footer'
    // Reserved for upcoming recipes (kept here so TypeScript catches typos
    // before the implementations land).
    | 'lifestyle_action'
    | 'banner_spread'
    | 'bold_statement_action'
    | 'heritage_textured_cta'
    | 'framed_showcase'
    | 'illustrated_event_poster'
    | 'split_panel_copy_photo'
    | 'product_lineup_spotlight'
    | 'bold_display_tech'
    | 'exploded_diagram'
    | 'dual_product_retail_announce'

/**
 * Per-brand ad-style profile. Derived at runtime from the brand's DNA (colors,
 * typography, voice) with sensible fallbacks; recipes read this to pick stroke
 * widths, watermark modes, footer treatments, etc., so the output feels
 * on-brand without per-brand template authoring.
 *
 * This lives in-memory only for the MVP. A later phase will persist overrides
 * in a `brand_ad_styles` DB table (see the plan).
 */
export type BrandAdStyle = {
    /** Where the composition's "dominant hue" comes from. */
    dominantHueStrategy: 'brand_primary' | 'product_color' | 'accent'
    /** Default BG fill when a recipe needs one and no photo is available. */
    backgroundPreference:
        | 'solid'
        | 'gradient_radial'
        | 'gradient_linear'
        | 'texture'
        | 'photo'
        | 'black'
        | 'paper'
    headlineStyle:
        | 'ghost_filled_pair'
        | 'filled_single'
        | 'script_plus_caps'
        | 'grunge_stacked'
        | 'bold_display_stack'
    /** Opacity for the "ghost" (outline) word in a ghost+filled pair. */
    headlineGhostOpacity: number
    /** Stroke width (in px at canvas resolution) for the ghost word. */
    headlineGhostStrokePx: number
    holdingShapeStyle:
        | 'hairline_rect'
        | 'rounded_pill'
        | 'double_frame'
        | 'ornamented'
        | 'none'
    holdingShapeStrokePx: number
    holdingShapeCornerRadius: number
    /** Watermark behavior for the brand mark. */
    watermarkMode: 'faded_bg' | 'corner_only' | 'both' | 'none'
    watermarkOpacity: number
    photoTreatment: 'duotone_primary' | 'tone_mapped' | 'natural' | 'grayscale' | 'glow'
    footerStyle: 'white_bar' | 'dark_bar' | 'none' | 'logo_centered'
    ctaStyle: 'pill_filled' | 'pill_outlined' | 'underline' | 'none'
    voiceTone: 'playful' | 'bold' | 'heritage' | 'technical' | 'minimal' | 'celebratory'
    /** Brand's primary + accent hues, always present for recipe math. */
    primaryColor: string
    secondaryColor: string
    accentColor: string
    /** Resolved primary logo asset id (used for watermark/footer primitives). */
    primaryLogoAssetId?: string
    /** Optional dark-safe / light-safe logo asset ids — recipes pick the right one. */
    darkLogoAssetId?: string
    lightLogoAssetId?: string
}

/**
 * Canvas size the recipe is composing for. Recipes may internally reflow their
 * output based on aspect ratio (vertical vs banner vs square). The Format Pack
 * subsystem uses this to request the same recipe at multiple sizes.
 */
export type RecipeFormat = {
    width: number
    height: number
}

/**
 * User / AI supplied content slots. All optional — recipes must produce a
 * sensible default when a slot is empty (e.g. "Your Headline Here") so the
 * canvas never crashes on missing data.
 */
export type RecipeContent = {
    /** Outline word in a ghost+filled pair. */
    ghostWord?: string
    /** Filled word in a ghost+filled pair (main emphasis). */
    filledWord?: string
    /** Single-line secondary text under the headline. */
    subline?: string
    /** Smaller tag line (e.g. "LIMITED EDITION"). */
    tagline?: string
    /** Product name (e.g. "Flex Sports Bra"). */
    productName?: string
    /** Variant name (e.g. "SUMMER LIME"). */
    productVariant?: string
    /** CTA button label (e.g. "Shop Now"). */
    cta?: string
    /** Long-form body copy for tech/spec recipes. */
    body?: string
    /** List of feature bullets for spec recipes. */
    featureList?: string[]
    /** Date blocks for event-poster recipes. */
    dates?: Array<{ label: string; numeral: string; detail?: string }>
    /** Partner / sponsor logo asset ids. */
    partnerLogoAssetId?: string
    sponsorLogoAssetIds?: string[]
    /** Hero asset id (product cut-out, lifestyle photo, etc.). */
    heroAssetId?: string
    /** Secondary hero — dual-product retail announce recipe. */
    secondaryHeroAssetId?: string
    /** Texture asset id (for heritage / paper recipes). */
    textureAssetId?: string
    /** Optional user-forced dominant hue override (e.g. product color). */
    dominantHueOverride?: string
    /** Website / footer URL. */
    url?: string
}

/**
 * Group spec emitted by a recipe. Mirrors the shape `blueprintToLayersAndGroups`
 * returns, but we construct it via `groupKey` on blueprints rather than this
 * object (recipes set `groupKey` per blueprint; the materializer bundles them).
 *
 * Exposed here for future recipe APIs that want to declare groups explicitly
 * (e.g. composite primitives that emit several blueprints all in one group).
 */
export type GroupSpec = {
    key: string
    name: string
}

export type RecipeInput = {
    style: BrandAdStyle
    format: RecipeFormat
    content: RecipeContent
}

export type RecipeOutput = {
    blueprints: LayerBlueprint[]
    /**
     * Optional human-readable notes about decisions the recipe made
     * (e.g. "Used product color for dominance"). Surfaced in the wizard
     * preview so users understand what's being generated.
     */
    notes?: string[]
}

export type Recipe = (input: RecipeInput) => RecipeOutput

/**
 * Recipe metadata shown in pickers / wizards.
 */
export type RecipeDescriptor = {
    key: RecipeKey
    name: string
    description: string
    /** Short lowercase tag used to filter / label (e.g. "product", "event"). */
    category: 'product' | 'brand' | 'lifestyle' | 'event' | 'tech' | 'retail' | 'reveal'
    /** Icon id resolved by the wizard's icon switch. Keep it stable. */
    icon: string
    /** Implementation. */
    build: Recipe
}

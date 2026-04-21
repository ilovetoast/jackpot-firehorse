import type { Layer, StudioSyncRole, TextBoostStyle } from './documentModel'
import { generateId } from './documentModel'
import { placementToXY, type Placement } from '../../utils/snapEngine'
import { composeRecipe, deriveBrandAdStyle, getRecipeDescriptor, RECIPE_REGISTRY, type BrandAdStyleHint, type RecipeKey } from './recipes'
import type { WizardDefaults } from './wizardDefaults'

// ── Types ──────────────────────────────────────────────────────────

export type TemplateCategory =
    | 'social_media'
    | 'web_banners'
    | 'presentation'
    | 'custom'

export type LayerBlueprint = {
    name: string
    type: Layer['type']
    role: 'background' | 'hero_image' | 'text_boost' | 'headline' | 'subheadline' | 'cta' | 'cta_button' | 'logo' | 'body' | 'overlay'
    widthRatio: number
    heightRatio: number
    xRatio: number
    yRatio: number
    defaults?: Record<string, unknown>
    /**
     * Optional 3x3 placement override. When set, the layer is centered in the
     * corresponding quadrant instead of using xRatio/yRatio. Driven by the
     * template wizard's Placement picker and the editor properties panel.
     */
    placement?: Placement
    /**
     * Optional enable/disable flag. Used by the wizard to let the user drop
     * individual layers from the blueprint list before creating a composition.
     * Undefined / true = include; false = skip during materialization.
     */
    enabled?: boolean
    /**
     * Optional group key. Blueprints sharing a `groupKey` are bundled into a
     * single {@link Group} during `blueprintToLayers` so the resulting
     * composition arrives pre-grouped. Typical example: CTA fill + text share
     * `groupKey: 'cta'` so they move/resize together out of the gate.
     *
     * Keys are scoped per-template-materialization — the same key reused
     * across template loads produces distinct groups.
     */
    groupKey?: string
}

/**
 * Maps wizard/template blueprint roles to persisted {@link Layer.studioSyncRole} when safe for cross-version sync.
 * Official cross-version roles: headline, subheadline, cta, logo, badge, disclaimer (mirrors server `StudioDocumentSyncRoleFinder`).
 */
export function studioSyncRoleFromBlueprint(bp: LayerBlueprint): StudioSyncRole | undefined {
    switch (bp.role) {
        case 'headline':
            return 'headline'
        case 'subheadline':
            return 'subheadline'
        case 'cta':
        case 'cta_button':
            return 'cta'
        case 'logo':
            return 'logo'
        case 'overlay':
            if (/\bbadge\b/i.test(bp.name)) {
                return 'badge'
            }
            return undefined
        case 'body':
            if (/disclaimer|legal|fine\s*print/i.test(bp.name)) {
                return 'disclaimer'
            }
            return undefined
        default:
            return undefined
    }
}

export type TemplateFormat = {
    id: string
    name: string
    width: number
    height: number
    layers: LayerBlueprint[]
}

export type TemplatePlatform = {
    id: string
    name: string
    icon?: string
    formats: TemplateFormat[]
}

export type TemplateCategoryDef = {
    id: TemplateCategory
    label: string
    platforms: TemplatePlatform[]
}

// ── Shared layer presets ───────────────────────────────────────────

/**
 * Default background is an empty **image** slot rather than a generative layer.
 * Rationale:
 *   - Brand-library photography is the product's primary visual source; AI
 *     generation is a secondary tool, not the default.
 *   - When {@see applyWizardAssetDefaults} has a tag-matched candidate, this
 *     slot is auto-filled on template materialization so the user sees a real
 *     photo immediately.
 *   - The guided / Jackpot-Spin flow (`executeAiLayoutGeneration`) can flip
 *     the layer back to `generative_image` when the AI decides to synthesize
 *     a background — see the `layer.type === 'image'` branch there.
 * The explicit `defaults.fit = 'cover'` keeps the slot edge-to-edge even when
 * the picked photo's aspect differs from the canvas.
 */
const BG_LAYER: LayerBlueprint = {
    name: 'Background',
    type: 'image',
    role: 'background',
    widthRatio: 1,
    heightRatio: 1,
    xRatio: 0,
    yRatio: 0,
    defaults: { fit: 'cover' },
}

const TEXT_BOOST: LayerBlueprint = {
    name: 'Text Boost',
    type: 'fill',
    role: 'text_boost',
    widthRatio: 1,
    heightRatio: 0.35,
    xRatio: 0,
    yRatio: 0.65,
    // Transparent → dark, anchored at the bottom so text stays readable on photography.
    // CSS gradient angle: 180° = progression runs top→bottom, so `start`(transparent)
    // is at the top and `end`(dark) is at the bottom. (Previously set to 0° which
    // flipped the gradient upside-down — dark at the top, light over the copy.)
    defaults: {
        fillKind: 'gradient',
        color: '#000000cc',
        gradientStartColor: 'transparent',
        gradientEndColor: '#000000cc',
        gradientAngleDeg: 180,
    },
}

const HEADLINE: LayerBlueprint = {
    name: 'Headline',
    type: 'text',
    role: 'headline',
    widthRatio: 0.85,
    heightRatio: 0.12,
    xRatio: 0.075,
    yRatio: 0.72,
    defaults: { content: 'Your Headline Here', fontSize: 48, fontWeight: 700, color: '#ffffff' },
}

const SUBHEADLINE: LayerBlueprint = {
    name: 'Sub-Headline',
    type: 'text',
    role: 'subheadline',
    widthRatio: 0.75,
    heightRatio: 0.06,
    xRatio: 0.125,
    yRatio: 0.85,
    defaults: { content: 'Supporting text goes here', fontSize: 20, fontWeight: 400, color: '#ffffffcc' },
}

const LOGO: LayerBlueprint = {
    name: 'Logo',
    type: 'image',
    role: 'logo',
    widthRatio: 0.15,
    heightRatio: 0.15,
    xRatio: 0.05,
    yRatio: 0.05,
}

const CTA_BG: LayerBlueprint = {
    name: 'CTA Button',
    type: 'fill',
    role: 'cta_button',
    widthRatio: 0.35,
    heightRatio: 0.06,
    xRatio: 0.325,
    yRatio: 0.92,
    // Color omitted — `blueprintToLayersAndGroups` uses brand primary for CTA fills.
    defaults: { fillKind: 'solid', borderRadius: 8 },
    // CTA = a button. The fill and the text above it share `groupKey: 'cta'`
    // so `blueprintToLayers` emits them pre-grouped. Dragging either member
    // moves both; resizing scales both proportionally around the union rect.
    groupKey: 'cta',
}

const CTA: LayerBlueprint = {
    name: 'CTA',
    type: 'text',
    role: 'cta',
    widthRatio: 0.35,
    heightRatio: 0.06,
    xRatio: 0.325,
    yRatio: 0.92,
    defaults: { content: 'Learn More', fontSize: 18, fontWeight: 600, color: '#ffffff' },
    groupKey: 'cta',
}

function postLayers(): LayerBlueprint[] {
    return [BG_LAYER, TEXT_BOOST, HEADLINE, SUBHEADLINE, LOGO]
}

function adLayers(): LayerBlueprint[] {
    return [BG_LAYER, TEXT_BOOST, HEADLINE, SUBHEADLINE, CTA_BG, CTA, LOGO]
}

function storyLayers(): LayerBlueprint[] {
    return [
        BG_LAYER,
        { ...TEXT_BOOST, heightRatio: 0.3, yRatio: 0.7 },
        { ...HEADLINE, yRatio: 0.75, widthRatio: 0.9, xRatio: 0.05 },
        { ...SUBHEADLINE, yRatio: 0.88, widthRatio: 0.8, xRatio: 0.1 },
        { ...LOGO, xRatio: 0.425, yRatio: 0.04, widthRatio: 0.15, heightRatio: 0.06 },
    ]
}

function bannerLayers(): LayerBlueprint[] {
    return [
        BG_LAYER,
        { ...TEXT_BOOST, heightRatio: 1, yRatio: 0, defaults: { ...TEXT_BOOST.defaults, gradientAngleDeg: 90 } },
        { ...HEADLINE, widthRatio: 0.45, xRatio: 0.05, yRatio: 0.3, heightRatio: 0.35 },
        { ...SUBHEADLINE, widthRatio: 0.4, xRatio: 0.05, yRatio: 0.65 },
        { ...CTA_BG, widthRatio: 0.2, xRatio: 0.05, yRatio: 0.8 },
        { ...CTA, widthRatio: 0.2, xRatio: 0.05, yRatio: 0.8 },
        LOGO,
    ]
}

function presentationLayers(): LayerBlueprint[] {
    return [
        { ...BG_LAYER, type: 'fill', defaults: { fillKind: 'solid', color: '#ffffff' } },
        { ...HEADLINE, yRatio: 0.08, xRatio: 0.06, widthRatio: 0.88, defaults: { ...HEADLINE.defaults, color: '#1a1a1a', fontSize: 42 } },
        {
            name: 'Body',
            type: 'text',
            role: 'body',
            widthRatio: 0.88,
            heightRatio: 0.5,
            xRatio: 0.06,
            yRatio: 0.22,
            defaults: { content: 'Slide content goes here', fontSize: 24, fontWeight: 400, color: '#333333' },
        },
        { ...LOGO, xRatio: 0.06, yRatio: 0.9, widthRatio: 0.1, heightRatio: 0.08 },
    ]
}

// ── Category definitions ───────────────────────────────────────────

export const TEMPLATE_CATEGORIES: TemplateCategoryDef[] = [
    {
        id: 'social_media',
        label: 'Social Media',
        platforms: [
            {
                id: 'facebook',
                name: 'Facebook',
                formats: [
                    { id: 'fb_feed_square', name: 'Feed Post', width: 1080, height: 1080, layers: postLayers() },
                    { id: 'fb_feed_portrait', name: 'Feed Portrait', width: 1080, height: 1350, layers: postLayers() },
                    { id: 'fb_stories', name: 'Stories & Reels', width: 1080, height: 1920, layers: storyLayers() },
                    { id: 'fb_carousel', name: 'Carousel Ad', width: 1080, height: 1080, layers: adLayers() },
                    { id: 'fb_cover', name: 'Cover Photo', width: 820, height: 312, layers: bannerLayers() },
                    { id: 'fb_ad', name: 'Link Ad', width: 1200, height: 628, layers: adLayers() },
                ],
            },
            {
                id: 'instagram',
                name: 'Instagram',
                formats: [
                    { id: 'ig_feed_square', name: 'Feed Post', width: 1080, height: 1080, layers: postLayers() },
                    { id: 'ig_feed_portrait', name: 'Portrait Post', width: 1080, height: 1350, layers: postLayers() },
                    { id: 'ig_stories', name: 'Stories & Reels', width: 1080, height: 1920, layers: storyLayers() },
                    { id: 'ig_carousel', name: 'Carousel', width: 1080, height: 1080, layers: postLayers() },
                    { id: 'ig_landscape', name: 'Landscape Post', width: 1080, height: 566, layers: postLayers() },
                ],
            },
            {
                id: 'x_twitter',
                name: 'X (Twitter)',
                formats: [
                    { id: 'tw_post', name: 'Post Image', width: 1600, height: 900, layers: postLayers() },
                    { id: 'tw_card', name: 'Card Image', width: 800, height: 418, layers: adLayers() },
                    { id: 'tw_header', name: 'Header Photo', width: 1500, height: 500, layers: bannerLayers() },
                ],
            },
            {
                id: 'linkedin',
                name: 'LinkedIn',
                formats: [
                    { id: 'li_post', name: 'Post Image', width: 1200, height: 627, layers: postLayers() },
                    { id: 'li_stories', name: 'Stories', width: 1080, height: 1920, layers: storyLayers() },
                    { id: 'li_cover', name: 'Company Cover', width: 1128, height: 191, layers: bannerLayers() },
                    { id: 'li_carousel', name: 'Carousel Slide', width: 1080, height: 1080, layers: postLayers() },
                ],
            },
            {
                id: 'tiktok',
                name: 'TikTok',
                formats: [
                    { id: 'tt_post', name: 'Post / Cover', width: 1080, height: 1920, layers: storyLayers() },
                ],
            },
            {
                id: 'pinterest',
                name: 'Pinterest',
                formats: [
                    { id: 'pin_standard', name: 'Standard Pin', width: 1000, height: 1500, layers: postLayers() },
                    { id: 'pin_square', name: 'Square Pin', width: 1000, height: 1000, layers: postLayers() },
                ],
            },
            {
                id: 'youtube',
                name: 'YouTube',
                formats: [
                    { id: 'yt_thumbnail', name: 'Thumbnail', width: 1280, height: 720, layers: postLayers() },
                    { id: 'yt_banner', name: 'Channel Banner', width: 2560, height: 1440, layers: bannerLayers() },
                ],
            },
        ],
    },
    {
        id: 'web_banners',
        label: 'Web Banners',
        platforms: [
            {
                id: 'display_ads',
                name: 'Display Ads',
                formats: [
                    { id: 'ad_leaderboard', name: 'Leaderboard', width: 728, height: 90, layers: bannerLayers() },
                    { id: 'ad_medium_rect', name: 'Medium Rectangle', width: 300, height: 250, layers: adLayers() },
                    { id: 'ad_large_rect', name: 'Large Rectangle', width: 336, height: 280, layers: adLayers() },
                    { id: 'ad_half_page', name: 'Half Page', width: 300, height: 600, layers: adLayers() },
                    { id: 'ad_billboard', name: 'Billboard', width: 970, height: 250, layers: bannerLayers() },
                    { id: 'ad_skyscraper', name: 'Wide Skyscraper', width: 160, height: 600, layers: adLayers() },
                    { id: 'ad_mobile', name: 'Mobile Banner', width: 320, height: 50, layers: bannerLayers() },
                ],
            },
            {
                id: 'email',
                name: 'Email',
                formats: [
                    { id: 'email_header', name: 'Email Header', width: 600, height: 200, layers: bannerLayers() },
                    { id: 'email_hero', name: 'Email Hero', width: 600, height: 400, layers: postLayers() },
                ],
            },
            {
                id: 'web_hero',
                name: 'Website',
                formats: [
                    { id: 'hero_full', name: 'Hero Banner', width: 1920, height: 600, layers: bannerLayers() },
                    { id: 'hero_mobile', name: 'Mobile Hero', width: 750, height: 1000, layers: postLayers() },
                    { id: 'og_image', name: 'OG / Share Image', width: 1200, height: 630, layers: postLayers() },
                ],
            },
        ],
    },
    {
        id: 'presentation',
        label: 'Presentation',
        platforms: [
            {
                id: 'slides',
                name: 'Slides',
                formats: [
                    { id: 'slide_16_9', name: 'Widescreen (16:9)', width: 1920, height: 1080, layers: presentationLayers() },
                    { id: 'slide_4_3', name: 'Standard (4:3)', width: 1024, height: 768, layers: presentationLayers() },
                    { id: 'slide_16_10', name: 'Widescreen (16:10)', width: 1920, height: 1200, layers: presentationLayers() },
                ],
            },
        ],
    },
    {
        id: 'custom',
        label: 'Custom',
        platforms: [],
    },
]

// ── Layout Styles (Ad Types) ──────────────────────────────────────

export type LayoutStyleId = 'product_focused' | 'brand_focused' | 'lifestyle' | 'special' | RecipeKey

export type LayoutStyle = {
    id: LayoutStyleId
    name: string
    description: string
    icon: string
    buildLayers: (isVertical: boolean, isBanner: boolean) => LayerBlueprint[]
}

const PRODUCT_IMAGE: LayerBlueprint = {
    name: 'Product Image',
    type: 'image',
    role: 'hero_image',
    widthRatio: 0.5,
    heightRatio: 0.45,
    xRatio: 0.25,
    yRatio: 0.2,
}

export const LAYOUT_STYLES: LayoutStyle[] = [
    {
        id: 'product_focused',
        name: 'Product Focused',
        description: 'Hero product image with CTA, headline, and text boost overlay',
        icon: 'product',
        buildLayers: (isVertical, isBanner) => {
            if (isBanner) return [
                BG_LAYER,
                { ...TEXT_BOOST, heightRatio: 1, yRatio: 0, defaults: { ...TEXT_BOOST.defaults, gradientAngleDeg: 90 } },
                { ...PRODUCT_IMAGE, widthRatio: 0.35, heightRatio: 0.7, xRatio: 0.6, yRatio: 0.15 },
                { ...HEADLINE, widthRatio: 0.45, xRatio: 0.05, yRatio: 0.2, heightRatio: 0.25 },
                { ...SUBHEADLINE, widthRatio: 0.4, xRatio: 0.05, yRatio: 0.5 },
                { ...CTA_BG, widthRatio: 0.22, xRatio: 0.05, yRatio: 0.7 },
                { ...CTA, widthRatio: 0.22, xRatio: 0.05, yRatio: 0.7 },
                LOGO,
            ]
            if (isVertical) return [
                BG_LAYER,
                { ...PRODUCT_IMAGE, widthRatio: 0.7, heightRatio: 0.35, xRatio: 0.15, yRatio: 0.08 },
                { ...TEXT_BOOST, heightRatio: 0.4, yRatio: 0.6 },
                { ...HEADLINE, yRatio: 0.5, widthRatio: 0.9, xRatio: 0.05 },
                { ...SUBHEADLINE, yRatio: 0.63, widthRatio: 0.8, xRatio: 0.1 },
                { ...CTA_BG, yRatio: 0.74, widthRatio: 0.5, xRatio: 0.25 },
                { ...CTA, yRatio: 0.74, widthRatio: 0.5, xRatio: 0.25 },
                { ...LOGO, xRatio: 0.425, yRatio: 0.9, widthRatio: 0.15, heightRatio: 0.05 },
            ]
            return [BG_LAYER, PRODUCT_IMAGE, TEXT_BOOST, HEADLINE, SUBHEADLINE, CTA_BG, CTA, LOGO]
        },
    },
    {
        id: 'brand_focused',
        name: 'Brand Focused',
        description: 'Full background image with brand message, headline, and logo',
        icon: 'brand',
        buildLayers: (isVertical, isBanner) => {
            if (isBanner) return [
                BG_LAYER,
                { ...TEXT_BOOST, heightRatio: 1, yRatio: 0, defaults: { ...TEXT_BOOST.defaults, gradientAngleDeg: 90 } },
                { ...HEADLINE, widthRatio: 0.45, xRatio: 0.05, yRatio: 0.25, heightRatio: 0.4 },
                { ...SUBHEADLINE, widthRatio: 0.4, xRatio: 0.05, yRatio: 0.65 },
                LOGO,
            ]
            if (isVertical) return [
                BG_LAYER,
                { ...TEXT_BOOST, heightRatio: 0.35, yRatio: 0.65 },
                { ...HEADLINE, yRatio: 0.7, widthRatio: 0.9, xRatio: 0.05 },
                { ...SUBHEADLINE, yRatio: 0.84, widthRatio: 0.8, xRatio: 0.1 },
                { ...LOGO, xRatio: 0.425, yRatio: 0.04, widthRatio: 0.15, heightRatio: 0.06 },
            ]
            return [BG_LAYER, TEXT_BOOST, HEADLINE, SUBHEADLINE, LOGO]
        },
    },
    {
        id: 'lifestyle',
        name: 'Lifestyle',
        description: 'Immersive full-bleed image with minimal text overlay',
        icon: 'lifestyle',
        buildLayers: (isVertical, isBanner) => {
            if (isVertical) return [
                BG_LAYER,
                { ...TEXT_BOOST, heightRatio: 0.2, yRatio: 0.8, defaults: { ...TEXT_BOOST.defaults, gradientEndColor: '#000000aa' } },
                { ...HEADLINE, yRatio: 0.84, widthRatio: 0.9, xRatio: 0.05, heightRatio: 0.08, defaults: { ...HEADLINE.defaults, fontSize: 36 } },
                { ...LOGO, xRatio: 0.425, yRatio: 0.03, widthRatio: 0.15, heightRatio: 0.05 },
            ]
            return [
                BG_LAYER,
                { ...TEXT_BOOST, heightRatio: 0.25, yRatio: 0.75, defaults: { ...TEXT_BOOST.defaults, gradientEndColor: '#000000aa' } },
                { ...HEADLINE, yRatio: 0.78, widthRatio: 0.8, xRatio: 0.1, heightRatio: 0.1, defaults: { ...HEADLINE.defaults, fontSize: 40 } },
                LOGO,
            ]
        },
    },
    {
        id: 'special',
        name: 'Special / Custom',
        description: 'Minimal starting point — just a background. Build your own layout',
        icon: 'custom',
        buildLayers: () => [BG_LAYER],
    },
]

export function getLayoutStyle(id: LayoutStyleId): LayoutStyle | undefined {
    return LAYOUT_STYLES.find(s => s.id === id)
}

export function buildLayersForStyle(
    styleId: LayoutStyleId,
    width: number,
    height: number,
    brand?: BrandAdStyleHint | null,
    wizardDefaults?: WizardDefaults | null,
): LayerBlueprint[] {
    // Recipe keys are dispatched to the recipe engine first. Any unregistered
    // key (recipe not yet implemented) falls through to the legacy LAYOUT_STYLES
    // lookup so the wizard still works on old-style LAYOUT_STYLES ids.
    const recipeDescriptor = getRecipeDescriptor(styleId as RecipeKey)
    if (recipeDescriptor) {
        const style = deriveBrandAdStyle(brand ?? null, wizardDefaults ?? null)
        // Pull wizard-auto-picked hero/logo into the recipe's content slots so
        // the first pass lands on a real-feeling composition. Per-layer edits
        // happen in the editor after materialization.
        const wizardHeroId = wizardDefaults?.background_candidates?.[0]?.id
        const { blueprints } = composeRecipe(recipeDescriptor.key, {
            style,
            format: { width, height },
            content: {
                heroAssetId: wizardHeroId,
            },
        })
        return blueprints
    }

    const style = getLayoutStyle(styleId)
    if (!style) return [BG_LAYER]
    const isVertical = height > width * 1.3
    const isBanner = width > height * 2
    return style.buildLayers(isVertical, isBanner)
}

/**
 * Recipe-backed LayoutStyle entries surfaced in the wizard Step 2 picker.
 * Each wrapper's `buildLayers(isVertical, isBanner)` returns a blueprint list
 * built from a neutral BrandAdStyle — call sites that have brand context
 * should call {@link buildLayersForStyle} with `(brand, wizardDefaults)` to
 * get the on-brand variant. The neutral fallback keeps the previews alive
 * for logged-out / brand-less demos.
 */
export const RECIPE_LAYOUT_STYLES: LayoutStyle[] = RECIPE_REGISTRY.map((rd) => ({
    id: rd.key as LayoutStyleId,
    name: rd.name,
    description: rd.description,
    icon: rd.icon,
    buildLayers: (_isVertical: boolean, _isBanner: boolean): LayerBlueprint[] => {
        // Fallback neutral style — real composition runs through
        // buildLayersForStyle once the wizard knows the brand + wizardDefaults.
        const neutralStyle = deriveBrandAdStyle(null, null)
        // We don't actually know width/height here; pass a square 1080 so the
        // ratio math in primitives works. Real invocation uses the correct size.
        const { blueprints } = composeRecipe(rd.key, {
            style: neutralStyle,
            format: { width: 1080, height: 1080 },
            content: {},
        })
        return blueprints
    },
}))

/**
 * LAYOUT_STYLES merged with the recipe-backed entries. Use this in the wizard
 * style picker so recipes appear as selectable cards alongside the classic
 * styles.
 */
export function getAllLayoutStyles(): LayoutStyle[] {
    return [...LAYOUT_STYLES, ...RECIPE_LAYOUT_STYLES]
}

// ── Helpers ────────────────────────────────────────────────────────

export function allFormats(): TemplateFormat[] {
    return TEMPLATE_CATEGORIES.flatMap((c) => c.platforms.flatMap((p) => p.formats))
}

export function aspectLabel(w: number, h: number): string {
    const gcd = (a: number, b: number): number => (b === 0 ? a : gcd(b, a % b))
    const d = gcd(w, h)
    return `${w / d}:${h / d}`
}

/**
 * Minimal shape we read off the Brand DNA / auth context for text-boost inference.
 * Kept loose because the actual brand object has many more fields we don't need
 * here, and DNA is frequently partial for new accounts.
 */
export type TextBoostBrandHint = {
    primary_color?: string | null
    /** Optional DNA override like 'solid_scrim', 'gradient_bottom', etc. */
    headline_treatment?: string | null
}

/**
 * Context of the composition the text-boost sits on. `background_is_photo`
 * flips us toward a gradient (which is what reads on busy imagery); a solid
 * color or empty background leans toward `solid` instead.
 */
export type TextBoostContext = {
    background_is_photo?: boolean
    /** Optional brightness/luminance hint: 0 = black, 1 = white. Unused currently. */
    background_luminance?: number
}

/**
 * Infer a sensible text-boost preset + color from brand + composition context.
 *
 * Called on materialization for any blueprint with `role=text_boost` whose
 * source is 'auto'. The inference is deliberately simple so users can reason
 * about the output; the properties panel exposes the knobs for anything
 * non-trivial.
 */
export function inferTextBoostStyle(
    brand: TextBoostBrandHint | null | undefined,
    ctx: TextBoostContext = {},
): { style: TextBoostStyle; color: string; opacity: number } {
    const primary = (brand?.primary_color && /^#[0-9a-fA-F]{6}$/.test(brand.primary_color))
        ? brand.primary_color
        : '#000000'

    const treatment = (brand?.headline_treatment ?? '').toLowerCase()
    const explicit: TextBoostStyle | null =
        treatment === 'solid' || treatment === 'solid_scrim' ? 'solid'
            : treatment === 'gradient_bottom' ? 'gradient_bottom'
                : treatment === 'gradient_top' ? 'gradient_top'
                    : treatment === 'radial' || treatment === 'vignette' ? 'radial'
                        : null

    if (explicit) {
        return {
            style: explicit,
            color: primary,
            opacity: explicit === 'solid' ? 0.85 : 0.7,
        }
    }

    // Default: photography backgrounds get a bottom-up gradient (keeps the
    // middle of the photo clean), non-photo backgrounds get a solid wash.
    if (ctx.background_is_photo) {
        return { style: 'gradient_bottom', color: primary, opacity: 0.7 }
    }
    return { style: 'solid', color: primary, opacity: 0.85 }
}

/**
 * Convert a hex `#rrggbb` into `rgba(r,g,b,a)` so we can apply opacity to the
 * text-boost color without losing the underlying brand hue (which we want the
 * properties panel color picker to keep showing).
 */
function hexToRgba(hex: string, alpha: number): string {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex)
    if (!m) return hex
    const n = parseInt(m[1], 16)
    const r = (n >> 16) & 0xff
    const g = (n >> 8) & 0xff
    const b = n & 0xff
    const a = Math.max(0, Math.min(1, alpha))
    return `rgba(${r}, ${g}, ${b}, ${a})`
}

/**
 * Map a `TextBoostStyle` + color + opacity into the concrete FillLayer fields
 * the renderer already knows how to paint (`gradientStartColor` / `gradientEndColor`
 * / `gradientAngleDeg` / `color` / `fillKind`). Keeps one renderer rather than
 * adding a second code path for text-boost layers specifically.
 */
export function textBoostToFillFields(
    style: TextBoostStyle,
    color: string,
    opacity: number,
): Pick<
    Extract<Layer, { type: 'fill' }>,
    'fillKind' | 'color' | 'gradientStartColor' | 'gradientEndColor' | 'gradientAngleDeg'
> {
    const tinted = hexToRgba(color, opacity)
    switch (style) {
        case 'solid':
            return {
                fillKind: 'solid',
                color: tinted,
                gradientStartColor: undefined,
                gradientEndColor: undefined,
                gradientAngleDeg: undefined,
            }
        case 'gradient_bottom':
            // CSS `linear-gradient(Adeg, S, E)` uses the angle as the
            // *direction of progression*: 0deg points to the top (S at bottom,
            // E at top) and 180deg points to the bottom (S at top, E at
            // bottom). For a text-boost whose dark side hugs the bottom of
            // the frame, start=transparent + end=tinted at angle 180° reads
            // transparent at the top, fading down to solid at the bottom —
            // i.e. the copy anchor sits on solid, the photo peeks through
            // the top. The previous angle-0 value produced the mirror image
            // and made the gradient look upside-down.
            return {
                fillKind: 'gradient',
                color: tinted,
                gradientStartColor: 'transparent',
                gradientEndColor: tinted,
                gradientAngleDeg: 180,
            }
        case 'gradient_top':
            // Mirror of gradient_bottom — dark at top, transparent at bottom.
            return {
                fillKind: 'gradient',
                color: tinted,
                gradientStartColor: tinted,
                gradientEndColor: 'transparent',
                gradientAngleDeg: 180,
            }
        case 'radial':
            // The fill renderer only knows linear — emulate a soft vignette by
            // stacking two gradients (top-down + bottom-up) with a diagonal
            // angle. Not a true radial, but reads close enough for copy.
            return {
                fillKind: 'gradient',
                color: tinted,
                gradientStartColor: tinted,
                gradientEndColor: 'transparent',
                gradientAngleDeg: 45,
            }
    }
}

/**
 * Materialize a blueprint list into concrete editor Layers + Groups.
 *
 * Blueprints sharing a `groupKey` emerge as a single Group object, with its
 * `memberIds` collected in blueprint order. The Group's `name` is derived
 * from the groupKey (title-cased) unless a member blueprint sets a custom
 * `defaults.groupName`.
 *
 * Callers that only need layers can keep using {@link blueprintToLayers};
 * anything that needs groups (e.g. applyTemplate building a fresh document)
 * should use this and set `doc.groups = result.groups`.
 */
export function blueprintToLayersAndGroups(
    blueprints: LayerBlueprint[],
    canvasW: number,
    canvasH: number,
    brandPrimaryColor?: string,
): { layers: Layer[]; groups: { id: string; name: string; memberIds: string[]; locked: boolean; collapsed: boolean }[] } {
    // Map groupKey → generated group id so every member of the same key
    // lands in the same Group. New keys get a fresh id on each call, which
    // means re-materializing a template yields fresh group ids (intentional:
    // otherwise undo / redo could collide with pre-existing groups).
    const groupIdByKey = new Map<string, string>()
    const membersByGroupId = new Map<string, string[]>()
    const nameByGroupId = new Map<string, string>()

    const layers = blueprints
        .filter((bp) => bp.enabled !== false)
        .map((bp, i) => {
        const width = Math.round(bp.widthRatio * canvasW)
        const height = Math.round(bp.heightRatio * canvasH)
        // Full-bleed layers (backgrounds etc.) ignore placement — they're meant
        // to fill the canvas regardless of where the user clicked in the picker.
        const isFullBleed = bp.widthRatio >= 0.999 && bp.heightRatio >= 0.999
        const { x, y } = bp.placement && !isFullBleed
            ? placementToXY(bp.placement, width, height, canvasW, canvasH)
            : { x: Math.round(bp.xRatio * canvasW), y: Math.round(bp.yRatio * canvasH) }
        const layerId = generateId()

        // Register the layer with its group (if any) before building `base`
        // so the base includes a groupId.
        let groupId: string | undefined
        if (bp.groupKey) {
            let gid = groupIdByKey.get(bp.groupKey)
            if (!gid) {
                gid = generateId()
                groupIdByKey.set(bp.groupKey, gid)
                membersByGroupId.set(gid, [])
                const customName = typeof bp.defaults?.groupName === 'string'
                    ? (bp.defaults.groupName as string)
                    : bp.groupKey.toUpperCase() === bp.groupKey
                        ? bp.groupKey
                        : bp.groupKey.charAt(0).toUpperCase() + bp.groupKey.slice(1)
                nameByGroupId.set(gid, customName)
            }
            groupId = gid
            membersByGroupId.get(gid)!.push(layerId)
        }

        const syncRole = studioSyncRoleFromBlueprint(bp)
        const base = {
            id: layerId,
            name: bp.name,
            visible: true,
            locked: false,
            z: i + 1,
            ...(groupId ? { groupId } : {}),
            transform: { x, y, width, height },
            ...(syncRole ? { studioSyncRole: syncRole } : {}),
        }

        switch (bp.type) {
            case 'text':
                return {
                    ...base,
                    type: 'text' as const,
                    content: (bp.defaults?.content as string) ?? '',
                    style: {
                        fontSize: (bp.defaults?.fontSize as number) ?? 32,
                        fontWeight: (bp.defaults?.fontWeight as number) ?? 400,
                        color: (bp.defaults?.color as string) ?? '#000000',
                        fontFamily: 'inherit',
                        textAlign: (bp.defaults?.textAlign as 'left' | 'center' | 'right' | undefined) ?? 'center',
                        lineHeight: (bp.defaults?.lineHeight as number | undefined) ?? 1.3,
                        letterSpacing: (bp.defaults?.letterSpacing as number | undefined) ?? 0,
                        verticalAlign: (bp.defaults?.verticalAlign as 'top' | 'middle' | 'bottom' | undefined) ?? 'top',
                        autoFit: false,
                        // Stroke defaults from blueprints — used by the recipe
                        // engine's ghost/filled headline primitive to emit a
                        // proper outlined-text ghost word rather than a faded
                        // fill approximation.
                        strokeWidth: typeof bp.defaults?.strokeWidth === 'number'
                            ? (bp.defaults.strokeWidth as number)
                            : undefined,
                        strokeColor: typeof bp.defaults?.strokeColor === 'string'
                            ? (bp.defaults.strokeColor as string)
                            : undefined,
                    },
                }

            case 'fill': {
                const fillKind = (bp.defaults?.fillKind as 'solid' | 'gradient') ?? 'solid'
                const isCtaFill = bp.role === 'cta_button'
                const explicitColor = bp.defaults?.color as string | undefined
                const color =
                    explicitColor !== undefined && explicitColor !== ''
                        ? explicitColor
                        : isCtaFill
                          ? (brandPrimaryColor ?? '#1f2937')
                          : (brandPrimaryColor ?? '#6366f1')
                // Support both the new (`gradientStartColor` / `gradientEndColor`) and legacy
                // (`gradientTo`) blueprint shapes so older layout styles keep rendering correctly.
                const legacyEnd = (bp.defaults?.gradientTo as string | undefined)
                const gradientStartColor = fillKind === 'gradient'
                    ? ((bp.defaults?.gradientStartColor as string | undefined) ?? 'transparent')
                    : undefined
                const gradientEndColor = fillKind === 'gradient'
                    ? ((bp.defaults?.gradientEndColor as string | undefined) ?? legacyEnd ?? color)
                    : undefined

                // Text-boost blueprints get their rendered gradient/solid derived
                // from brand DNA via inferTextBoostStyle. The blueprint's own
                // `defaults.color` etc. become the starting override the user
                // can tweak in the properties panel. Plain fill blueprints keep
                // the legacy behavior exactly.
                if (bp.role === 'text_boost') {
                    const inferred = inferTextBoostStyle(
                        brandPrimaryColor ? { primary_color: brandPrimaryColor } : null,
                        // BG with role=background|hero_image upstream means the
                        // composition probably has a photo; lean on gradient.
                        // We don't know for sure at blueprint time — safe default
                        // is `true` because most templates layer text_boost over
                        // a BG. Users can flip to solid in the properties panel.
                        { background_is_photo: true },
                    )
                    const derived = textBoostToFillFields(inferred.style, inferred.color, inferred.opacity)
                    return {
                        ...base,
                        type: 'fill' as const,
                        fillKind: derived.fillKind,
                        color: derived.color,
                        gradientStartColor: derived.gradientStartColor,
                        gradientEndColor: derived.gradientEndColor,
                        gradientAngleDeg: derived.gradientAngleDeg,
                        borderRadius: undefined,
                        kind: 'text_boost' as const,
                        textBoostStyle: inferred.style,
                        textBoostColor: inferred.color,
                        textBoostOpacity: inferred.opacity,
                        textBoostSource: 'auto' as const,
                    }
                }

                return {
                    ...base,
                    type: 'fill' as const,
                    fillKind,
                    color,
                    gradientStartColor,
                    gradientEndColor,
                    gradientAngleDeg: (bp.defaults?.gradientAngleDeg as number) ?? 180,
                    borderRadius: (bp.defaults?.borderRadius as number) ?? undefined,
                    ...(isCtaFill ? { fillRole: 'cta_button' as const } : {}),
                    // Border fields — used by the holding-shape primitive to
                    // emit a hollow frame (transparent fill + visible border).
                    borderStrokeWidth: typeof bp.defaults?.borderStrokeWidth === 'number'
                        ? (bp.defaults.borderStrokeWidth as number)
                        : undefined,
                    borderStrokeColor: typeof bp.defaults?.borderStrokeColor === 'string'
                        ? (bp.defaults.borderStrokeColor as string)
                        : undefined,
                }
            }

            case 'generative_image':
                return {
                    ...base,
                    type: 'generative_image' as const,
                    prompt: { scene: '', style: '', palette: '', mood: '', additionalDirections: '' },
                    status: 'idle' as const,
                    src: '',
                    history: [],
                    feedback: [],
                }

            case 'mask': {
                // Templates can include a mask layer to clip a specific slot
                // (e.g. a radial softens the hero image). Defaults live on the
                // blueprint's `defaults` so template authors can tweak shape /
                // feather / target without per-layer code.
                return {
                    ...base,
                    type: 'mask' as const,
                    shape: (bp.defaults?.shape as 'rect' | 'ellipse' | 'rounded_rect' | 'gradient_linear' | 'gradient_radial') ?? 'ellipse',
                    radius: typeof bp.defaults?.radius === 'number' ? (bp.defaults.radius as number) : undefined,
                    featherPx: typeof bp.defaults?.featherPx === 'number' ? (bp.defaults.featherPx as number) : 16,
                    invert: !!bp.defaults?.invert,
                    target: (bp.defaults?.target as 'below_one' | 'below_all' | 'group') ?? 'below_one',
                    gradientAngle: typeof bp.defaults?.gradientAngle === 'number' ? (bp.defaults.gradientAngle as number) : undefined,
                    gradientStops: Array.isArray(bp.defaults?.gradientStops)
                        ? (bp.defaults!.gradientStops as Array<{ offset: number; alpha: number }>)
                        : undefined,
                }
            }

            case 'image':
            default: {
                // Pick up wizard auto-fill (logo / background photo). When present
                // the wizard seeded these via `applyWizardAssetDefaults`; otherwise
                // we leave the slot empty so the user can choose a library asset.
                const assetId = (bp.defaults?.assetId as string | undefined) ?? undefined
                const assetUrl = (bp.defaults?.assetUrl as string | undefined) ?? ''
                const naturalWidth = (bp.defaults?.naturalWidth as number | undefined) ?? undefined
                const naturalHeight = (bp.defaults?.naturalHeight as number | undefined) ?? undefined

                // `contain` for logos and any blueprint flagged as such via
                // `defaults.fit` — ensures resizing the layer reveals the full
                // artwork instead of cropping it. Hero/background slots keep
                // `cover` so they fill their region edge-to-edge.
                const explicitFit = bp.defaults?.fit as 'cover' | 'contain' | undefined
                const fit: 'cover' | 'contain' =
                    explicitFit ?? (bp.role === 'logo' ? 'contain' : 'cover')

                // Resize the LAYER itself to match the asset's aspect ratio —
                // but ONLY for `contain` slots. A 3:1 wordmark dropped into a
                // square 0.15 × 0.15 logo slot ends up letterboxed via
                // `fit: contain`, which looks like a "floating logo inside an
                // empty box". Shrinking the layer to the wordmark's shape
                // removes the letterboxing and preserves the template's
                // composition intent.
                //
                // Cover slots (e.g. the full-bleed BG_LAYER) explicitly SHOULD
                // NOT resize — a 4:3 photo in a 1:1 canvas stays 1:1 and gets
                // cropped edge-to-edge. If we resized cover layers too, a
                // 1080×1080 BG would shrink to 1080×810 and leave a blank
                // canvas strip at the top — visually indistinguishable from a
                // broken template.
                let finalTransform = base.transform
                if (
                    fit === 'contain' &&
                    naturalWidth &&
                    naturalHeight &&
                    naturalWidth > 0 &&
                    naturalHeight > 0 &&
                    base.transform.width > 0 &&
                    base.transform.height > 0
                ) {
                    const maxW = base.transform.width
                    const maxH = base.transform.height
                    const scale = Math.min(maxW / naturalWidth, maxH / naturalHeight)
                    const newW = Math.max(1, Math.round(naturalWidth * scale))
                    const newH = Math.max(1, Math.round(naturalHeight * scale))
                    finalTransform = {
                        ...base.transform,
                        x: base.transform.x + Math.round((maxW - newW) / 2),
                        y: base.transform.y + Math.round((maxH - newH) / 2),
                        width: newW,
                        height: newH,
                    }
                }

                return {
                    ...base,
                    transform: finalTransform,
                    type: 'image' as const,
                    src: assetUrl,
                    assetId,
                    naturalWidth,
                    naturalHeight,
                    fit,
                }
            }
        }
    }) as Layer[]

    const groups = Array.from(groupIdByKey.values()).map((gid) => ({
        id: gid,
        name: nameByGroupId.get(gid) ?? 'Group',
        memberIds: membersByGroupId.get(gid) ?? [],
        locked: false,
        collapsed: false,
    }))
    // Filter out any stray single-member groups — a group of one is a no-op
    // and pollutes the panel. Also strip the groupId off that lone member so
    // it doesn't dangle.
    const singleMemberGroupIds = new Set(groups.filter((g) => g.memberIds.length < 2).map((g) => g.id))
    const finalLayers = singleMemberGroupIds.size === 0
        ? layers
        : layers.map((l) => (l.groupId && singleMemberGroupIds.has(l.groupId) ? { ...l, groupId: undefined } : l))
    const finalGroups = groups.filter((g) => g.memberIds.length >= 2)
    return { layers: finalLayers, groups: finalGroups }
}

/**
 * Legacy materializer — returns only layers. Drops any groupings. Use
 * {@link blueprintToLayersAndGroups} if you need the group metadata.
 */
export function blueprintToLayers(
    blueprints: LayerBlueprint[],
    canvasW: number,
    canvasH: number,
    brandPrimaryColor?: string,
): Layer[] {
    const { layers } = blueprintToLayersAndGroups(blueprints, canvasW, canvasH, brandPrimaryColor)
    // Strip groupId — the legacy callers build DocumentModels without a
    // `groups[]` array, so leaving the reference would orphan it.
    return layers.map((l) => (l.groupId ? { ...l, groupId: undefined } : l))
}

import type { Layer } from './documentModel'
import { generateId } from './documentModel'

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

const BG_LAYER: LayerBlueprint = {
    name: 'Background',
    type: 'generative_image',
    role: 'background',
    widthRatio: 1,
    heightRatio: 1,
    xRatio: 0,
    yRatio: 0,
}

const TEXT_BOOST: LayerBlueprint = {
    name: 'Text Boost',
    type: 'fill',
    role: 'text_boost',
    widthRatio: 1,
    heightRatio: 0.35,
    xRatio: 0,
    yRatio: 0.65,
    // Transparent → dark: keeps text readable over photography. The renderer requires
    // `gradientStartColor` / `gradientEndColor`; plain `gradientTo` is ignored.
    // angle 0 = gradient rises toward the top, so the dark side hugs the bottom (where the copy sits).
    defaults: {
        fillKind: 'gradient',
        color: '#000000cc',
        gradientStartColor: 'transparent',
        gradientEndColor: '#000000cc',
        gradientAngleDeg: 0,
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
    defaults: { fillKind: 'solid', color: '#7c3aed', borderRadius: 8 },
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

export type LayoutStyleId = 'product_focused' | 'brand_focused' | 'lifestyle' | 'special'

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
): LayerBlueprint[] {
    const style = getLayoutStyle(styleId)
    if (!style) return [BG_LAYER]
    const isVertical = height > width * 1.3
    const isBanner = width > height * 2
    return style.buildLayers(isVertical, isBanner)
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

export function blueprintToLayers(
    blueprints: LayerBlueprint[],
    canvasW: number,
    canvasH: number,
    brandPrimaryColor?: string,
): Layer[] {
    return blueprints.map((bp, i) => {
        const base = {
            id: generateId(),
            name: bp.name,
            visible: true,
            locked: false,
            z: i + 1,
            transform: {
                x: Math.round(bp.xRatio * canvasW),
                y: Math.round(bp.yRatio * canvasH),
                width: Math.round(bp.widthRatio * canvasW),
                height: Math.round(bp.heightRatio * canvasH),
            },
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
                        textAlign: 'center' as const,
                        lineHeight: 1.3,
                        letterSpacing: 0,
                        verticalAlign: 'top' as const,
                        autoFit: false,
                    },
                }

            case 'fill': {
                const fillKind = (bp.defaults?.fillKind as 'solid' | 'gradient') ?? 'solid'
                const color = (bp.defaults?.color as string) ?? brandPrimaryColor ?? '#6366f1'
                // Support both the new (`gradientStartColor` / `gradientEndColor`) and legacy
                // (`gradientTo`) blueprint shapes so older layout styles keep rendering correctly.
                const legacyEnd = (bp.defaults?.gradientTo as string | undefined)
                const gradientStartColor = fillKind === 'gradient'
                    ? ((bp.defaults?.gradientStartColor as string | undefined) ?? 'transparent')
                    : undefined
                const gradientEndColor = fillKind === 'gradient'
                    ? ((bp.defaults?.gradientEndColor as string | undefined) ?? legacyEnd ?? color)
                    : undefined
                return {
                    ...base,
                    type: 'fill' as const,
                    fillKind,
                    color,
                    gradientStartColor,
                    gradientEndColor,
                    gradientAngleDeg: (bp.defaults?.gradientAngleDeg as number) ?? 180,
                    borderRadius: (bp.defaults?.borderRadius as number) ?? undefined,
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

            case 'image':
            default:
                return {
                    ...base,
                    type: 'image' as const,
                    src: '',
                    assetId: undefined,
                    objectFit: 'cover' as const,
                    opacity: 1,
                }
        }
    }) as Layer[]
}

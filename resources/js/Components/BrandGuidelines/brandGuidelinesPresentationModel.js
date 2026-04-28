/**
 * Brand Guidelines — presentation override model (AI baseline in DNA + human overrides in presentation_overrides only).
 * Canonical Identity / DNA is never mutated by these fields.
 */

/** Stable ids for logo showcase cards in the Brand Identity section (sec-logo). */
export const IDENTITY_LOGO_BLOCK_IDS = {
    PRIMARY_HERO: 'primary_hero',
    ON_LIGHT: 'on_light',
    REVERSED: 'reversed',
    ON_ACCENT: 'on_accent',
    ON_DARK: 'on_dark',
    REDUCED_OPACITY: 'reduced_opacity',
    MIN_SIZE: 'min_size',
}

/** Human-readable default labels (AI / template baseline). */
export const IDENTITY_LOGO_BLOCK_LABELS = {
    [IDENTITY_LOGO_BLOCK_IDS.PRIMARY_HERO]: 'Primary Brandmark',
    [IDENTITY_LOGO_BLOCK_IDS.ON_LIGHT]: 'On Light Background',
    [IDENTITY_LOGO_BLOCK_IDS.REVERSED]: 'Reversed / On Color',
    [IDENTITY_LOGO_BLOCK_IDS.ON_ACCENT]: 'On Accent',
    [IDENTITY_LOGO_BLOCK_IDS.ON_DARK]: 'On Dark',
    [IDENTITY_LOGO_BLOCK_IDS.REDUCED_OPACITY]: 'Reduced Opacity',
    [IDENTITY_LOGO_BLOCK_IDS.MIN_SIZE]: 'Minimum Size',
}

/**
 * Logo source for guideline presentation only.
 * @typedef {{ type: 'identity', key: string, asset_id?: string | null, label?: string }} GuidelineLogoSource
 */

export const LOGO_SOURCE_PRESETS = [
    { value: 'identity:primary_logo', type: 'identity', key: 'primary_logo', label: 'Primary logo' },
    { value: 'identity:logo_on_light', type: 'identity', key: 'logo_on_light', label: 'Logo on light' },
    { value: 'identity:logo_on_dark', type: 'identity', key: 'logo_on_dark', label: 'Logo on dark' },
    { value: 'identity:horizontal_logo', type: 'identity', key: 'horizontal_logo', label: 'Horizontal logo' },
]

/** @param {string} value from LOGO_SOURCE_PRESETS.value */
export function parseLogoSourceValue(value) {
    if (!value || typeof value !== 'string') return { type: 'identity', key: 'primary_logo' }
    if (value.startsWith('identity:')) {
        return { type: 'identity', key: value.replace('identity:', '') }
    }
    if (value.startsWith('custom_url:')) {
        return { type: 'custom_url', url: value.slice('custom_url:'.length) }
    }
    if (value.startsWith('brand_asset:')) {
        return { type: 'brand_asset', asset_id: value.slice('brand_asset:'.length) }
    }
    return { type: 'identity', key: 'primary_logo' }
}

export function buildAssetUrlMap(logoAssets) {
    const byId = {}
    if (Array.isArray(logoAssets)) {
        logoAssets.forEach((a) => {
            if (a?.id && a?.url) byId[a.id] = a.url
        })
    }
    return { byId }
}

export function logoSourceToSelectValue(source) {
    if (!source) return 'identity:primary_logo'
    if (source.type === 'custom_url' && source.url) return `custom_url:${source.url}`
    if (source.type === 'brand_asset' && source.asset_id) return `brand_asset:${source.asset_id}`
    if (source.type === 'identity' && source.key) return `identity:${source.key}`
    return 'identity:primary_logo'
}

/**
 * @param {GuidelineLogoSource | undefined} source
 * @param {{ logo_url: string, logo_dark_url?: string, logo_on_light_url?: string, logo_horizontal_url?: string }} brand
 * @param {{ byId: Record<string, string> }} [assetUrlMap] asset_id -> url from logoAssets
 */
export function resolveLogoUrlFromSource(source, brand, assetUrlMap = { byId: {} }) {
    const primary = brand?.logo_url || ''
    if (!source || source.type === 'default') return null

    if (source.type === 'custom_url' && source.url) return String(source.url)

    if (source.type === 'brand_asset' && source.asset_id) {
        const u = assetUrlMap.byId?.[source.asset_id]
        if (u) return u
    }

    if (source.type === 'identity') {
        const k = source.key || 'primary_logo'
        if (k === 'primary_logo') return primary
        if (k === 'logo_on_dark') return brand?.logo_dark_url || null
        if (k === 'logo_on_light') return brand?.logo_on_light_url || null
        if (k === 'horizontal_logo') return brand?.logo_horizontal_url || null
    }

    return null
}

/** Default per-block AI baseline (used when no override). */
export function defaultIdentityBlockSource(blockId) {
    switch (blockId) {
        case IDENTITY_LOGO_BLOCK_IDS.ON_DARK:
        case IDENTITY_LOGO_BLOCK_IDS.REVERSED:
            return { type: 'identity', key: 'logo_on_dark' }
        case IDENTITY_LOGO_BLOCK_IDS.ON_LIGHT:
            return { type: 'identity', key: 'primary_logo' }
        default:
            return { type: 'identity', key: 'primary_logo' }
    }
}

const SIZE_TO_MAX_H = {
    sm: { hero: 'max-h-20 md:max-h-24', sm: 'max-h-8', mini: 'max-h-3' },
    md: { hero: 'max-h-28 md:max-h-36', sm: 'max-h-12', mini: 'max-h-4' },
    lg: { hero: 'max-h-32 md:max-h-44', sm: 'max-h-16', mini: 'max-h-6' },
    xl: { hero: 'max-h-40 md:max-h-52', sm: 'max-h-20', mini: 'max-h-8' },
}

/**
 * @param {string} sizePreset
 * @param {'hero'|'sm'|'mini'} slot
 */
export function logoSizeToClassName(sizePreset, slot) {
    const p = SIZE_TO_MAX_H[sizePreset] || SIZE_TO_MAX_H.lg
    return p[slot] || p.lg
}

const ALIGNMENT_TO_FLEX = {
    center: 'items-center justify-center',
    'top-left': 'items-start justify-start',
    'top-right': 'items-start justify-end',
    'bottom-left': 'items-end justify-start',
    'bottom-right': 'items-end justify-end',
}

export function logoAlignmentToContainerClass(align) {
    return ALIGNMENT_TO_FLEX[align] || ALIGNMENT_TO_FLEX.center
}

function hexToRgbaForOverlay(hex, alpha = 1) {
    if (!hex || typeof hex !== 'string' || !hex.startsWith('#')) {
        return `rgba(0,0,0,${alpha})`
    }
    const num = parseInt(hex.slice(1), 16)
    if (Number.isNaN(num)) {
        return `rgba(0,0,0,${alpha})`
    }
    const r = (num >> 16) & 0xff
    const g = (num >> 8) & 0xff
    const b = num & 0xff
    return `rgba(${r},${g},${b},${alpha})`
}

/** @param {object} [globalObj] `presentation_overrides.global` */
export function hasPageThemeOverrides(globalObj) {
    const pageTheme = globalObj?.page_theme
    if (!pageTheme || typeof pageTheme !== 'object') return false
    return Object.keys(pageTheme).length > 0
}

/** For confirmation copy — rough count of user-facing override units in a section. */
export function countSectionOverrideUnits(sections, sectionId) {
    const s = sections?.[sectionId]
    if (!s || typeof s !== 'object') return 0
    let n = 0
    for (const key of Object.keys(s)) {
        if (key === 'content' && s.content && typeof s.content === 'object') {
            const lb = s.content.logo_blocks
            if (lb && typeof lb === 'object') n += Object.keys(lb).length
            n += Object.keys(s.content).filter((k) => k !== 'logo_blocks').length
        } else {
            n += 1
        }
    }
    return n
}

/** @param {Record<string, unknown> | undefined} sections */
export function isSectionOverridden(sections, sectionId) {
    const s = sections?.[sectionId]
    if (!s || typeof s !== 'object') return false
    return Object.keys(s).length > 0
}

/** @param {Record<string, unknown> | undefined} sections */
export function isLogoBlockOverridden(sections, sectionId, blockId) {
    const b = sections?.[sectionId]?.content?.logo_blocks?.[blockId]
    if (!b || typeof b !== 'object') return false
    return Object.keys(b).length > 0
}

/**
 * @param {object | undefined} source logo block `source` field
 * @param {object} [brand] unused; reserved for future
 * @param {Array<{ id: string | number, title?: string }>} [logoAssets] brand library assets
 */
export function getLogoSourceDisplayLabel(source, brand, logoAssets = []) {
    if (!source) {
        return { line: 'Primary logo', sub: 'From Brand Identity' }
    }
    if (source.type === 'custom_url' && source.url) {
        return { line: 'Custom image URL', sub: String(source.url).slice(0, 48) + (String(source.url).length > 48 ? '…' : '') }
    }
    if (source.type === 'brand_asset' && source.asset_id) {
        const asset = logoAssets.find((a) => String(a.id) === String(source.asset_id))
        return { line: 'Brand library', sub: asset?.title || `Asset #${source.asset_id}` }
    }
    if (source.type === 'identity' && source.key) {
        const m = {
            primary_logo: 'Primary logo',
            logo_on_light: 'Logo on light',
            logo_on_dark: 'Logo on dark',
            horizontal_logo: 'Horizontal logo',
        }
        return { line: m[source.key] || 'Identity logo', sub: 'From Brand Identity' }
    }
    return { line: 'Primary logo', sub: 'From Brand Identity' }
}

/**
 * @param {object} globalOverrides presentation_overrides.global
 * @param {{ logoAssets?: Array<{ id: string | number, url?: string }> }} [options]
 * @returns {{ backgroundStyle: React.CSSProperties, overlayStyle: React.CSSProperties, theme: object, hasBackgroundLayer: boolean, hasOverlayLayer: boolean }}
 */
export function pageThemeToLayerStyles(globalOverrides, options = {}) {
    const t = globalOverrides?.page_theme || {}
    const { logoAssets = [] } = options
    const backgroundStyle = {}

    const inferMode = () => {
        if (t.background_mode === 'default') return 'default'
        if (t.background_mode && t.background_mode !== 'default') return t.background_mode
        if (t.background_asset_id) return 'brand_asset'
        if (t.background_image_url && String(t.background_image_url).trim()) return 'image'
        if (t.background_custom_url && String(t.background_custom_url).trim()) return 'custom_url'
        if (t.background_color && String(t.background_color).trim().startsWith('#')) return 'color'
        return 'default'
    }
    const mode = inferMode()

    let imageUrl = ''
    if (mode === 'image' && t.background_image_url) {
        imageUrl = String(t.background_image_url).trim()
    } else if (mode === 'custom_url' && t.background_custom_url) {
        imageUrl = String(t.background_custom_url).trim()
    } else if (mode === 'brand_asset' && t.background_asset_id) {
        const a = logoAssets.find((x) => String(x.id) === String(t.background_asset_id))
        if (a?.url) imageUrl = String(a.url).trim()
    }

    if (imageUrl) {
        backgroundStyle.backgroundImage = `url(${imageUrl})`
        backgroundStyle.backgroundSize = 'cover'
        backgroundStyle.backgroundPosition = 'center'
    } else if (mode === 'color' && t.background_color && String(t.background_color).trim().startsWith('#')) {
        backgroundStyle.backgroundColor = t.background_color
    } else if (!t.background_mode && t.background_color && String(t.background_color).trim().startsWith('#')) {
        /* legacy data: had a color before background_mode existed */
        backgroundStyle.backgroundColor = t.background_color
    }

    const op = typeof t.overlay_opacity === 'number' ? t.overlay_opacity : 0
    const rawHex = t.overlay_color && String(t.overlay_color).trim().startsWith('#') ? String(t.overlay_color).trim() : '#000000'
    const overlayStyle = {}
    if (op > 0.001) {
        overlayStyle.backgroundColor = hexToRgbaForOverlay(rawHex, op)
    }

    return {
        backgroundStyle,
        overlayStyle,
        theme: t,
        hasBackgroundLayer: Object.keys(backgroundStyle).length > 0,
        hasOverlayLayer: op > 0.001,
    }
}

/**
 * @param {object} globalOverrides presentation_overrides.global
 * @param {{ logoAssets?: Array<{ id: string | number, url?: string }> }} [options]
 * @returns {{ style: object, theme: object, backgroundStyle: object, overlayStyle: object, hasBackgroundLayer: boolean, hasOverlayLayer: boolean }}
 */
export function pageThemeToMainStyle(globalOverrides, options) {
    const { backgroundStyle, overlayStyle, theme, hasBackgroundLayer, hasOverlayLayer } = pageThemeToLayerStyles(globalOverrides, options)
    return { style: backgroundStyle, theme, backgroundStyle, overlayStyle, hasBackgroundLayer, hasOverlayLayer }
}

/**
 * Card background for Brand Identity blocks (override or inherit default style object).
 * @param {object|null} block
 * @param {import('react').CSSProperties} defaultStyle
 * @param {{ primary_color?: string, secondary_color?: string, accent_color?: string }} brand
 * @param {Array<{ id: string | number, url?: string }>} [logoAssets] for `brand_asset` mode
 */
export function resolveIdentityCardBackground(block, defaultStyle, brand, logoAssets = []) {
    const b = block?.background
    const m = b?.mode || 'inherit'
    if (m === 'inherit' || !b) {
        return defaultStyle
    }
    if (m === 'transparent') return { backgroundColor: 'transparent' }
    if (m === 'white') return { backgroundColor: '#ffffff' }
    if (m === 'black') return { backgroundColor: '#111827' }
    if (m === 'primary') return { backgroundColor: brand?.primary_color || '#111827' }
    if (m === 'secondary') return { backgroundColor: brand?.secondary_color || '#6b21a8' }
    if (m === 'accent') return { backgroundColor: brand?.accent_color || '#0ea5e9' }
    if (m === 'custom') return { backgroundColor: b?.custom_color || '#ffffff' }
    if (m === 'image' && b?.image_url) {
        const u = String(b.image_url).trim()
        if (u) {
            return {
                backgroundImage: `url(${u})`,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
            }
        }
    }
    if (m === 'brand_asset' && b?.asset_id) {
        const a = logoAssets.find((x) => String(x.id) === String(b.asset_id))
        if (a?.url) {
            return {
                backgroundImage: `url(${String(a.url)})`,
                backgroundSize: 'cover',
                backgroundPosition: 'center',
            }
        }
    }
    return defaultStyle
}

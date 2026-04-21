/**
 * Brand-DNA Ad Recipes — BrandAdStyle derivation.
 *
 * For the MVP we derive a {@link BrandAdStyle} at runtime from whatever brand
 * data the editor already has in the Inertia auth payload, plus the wizard
 * defaults (which carry the resolved primary-logo asset id). No DB table yet;
 * a later phase will let users persist overrides via a `brand_ad_styles` row.
 *
 * The goal here is that every field has a *sensible default* so recipes never
 * have to null-check — even a brand with zero DNA configured still produces a
 * composition when fed through a recipe.
 */

import type { BrandAdStyle } from './types'
import type { WizardDefaults } from '../wizardDefaults'

/**
 * Minimal brand shape we care about for ad-style derivation. Mirrors what
 * `auth?.activeBrand` typically exposes in the editor. All fields optional to
 * survive partial payloads (agencies looking at another tenant's brand, etc.).
 */
export type BrandAdStyleHint = {
    id?: string | number | null
    primary_color?: string | null
    secondary_color?: string | null
    accent_color?: string | null
    /** Optional DNA override from brand guidelines (e.g. 'solid_scrim'). */
    headline_treatment?: string | null
    /** Free-form voice label; we normalize it to our enum. */
    voice?: string | null
    /**
     * Full `settings` JSON from `auth.activeBrand.settings`. We read
     * `settings.ad_style` for user-set overrides that win over inferred values.
     * See {@link AdStyleOverrides}.
     */
    settings?: Record<string, unknown> | null
}

/**
 * Shape of user-authored overrides stored under `brand.settings.ad_style`.
 *
 * Every field is optional: any omitted key falls back to the value inferred
 * from voice/color/DNA. This is intentional — users set *just* the things
 * they care about and let derivation handle the rest. Same shape as the
 * inferred BrandAdStyle minus brand-color fields (those live on `Brand`
 * directly, not on the ad-style record).
 *
 * Lives on `brand.settings.ad_style` (no migration required — `settings` is
 * already a JSON column). A later migration may split this into its own
 * `brand_ad_styles` table when we need versioning + per-archetype overrides.
 */
export type AdStyleOverrides = Partial<
    Pick<
        BrandAdStyle,
        | 'dominantHueStrategy'
        | 'backgroundPreference'
        | 'headlineStyle'
        | 'headlineGhostOpacity'
        | 'headlineGhostStrokePx'
        | 'holdingShapeStyle'
        | 'holdingShapeStrokePx'
        | 'holdingShapeCornerRadius'
        | 'watermarkMode'
        | 'watermarkOpacity'
        | 'photoTreatment'
        | 'footerStyle'
        | 'ctaStyle'
        | 'voiceTone'
    >
>

/**
 * Soft signals aggregated from a brand's reference-ad gallery, computed
 * server-side by `BrandAdReferenceHintsService`. Layered between voice-
 * derived inference and user overrides:
 *
 *   inference → referenceHints → overrides
 *
 * So hints never clobber a user choice, but do nudge raw inference toward
 * what the brand's own reference gallery suggests (dark vs light, muted
 * vs vibrant, minimal vs rich palette, warm vs cool).
 *
 * Fields mirror the PHP aggregator 1:1 (snake_case preserved) so callers
 * can pass the server payload through with no renaming.
 */
export type BrandAdReferenceHints = {
    sample_count: number
    avg_brightness: number | null
    avg_saturation: number | null
    dominant_hue_bucket: 'warm' | 'cool' | 'neutral' | null
    palette_mix: {
        monochrome: number
        duochrome: number
        polychrome: number
    } | null
    suggestions: {
        prefers_dark_backgrounds: boolean
        prefers_light_backgrounds: boolean
        prefers_vibrant: boolean
        prefers_muted: boolean
        prefers_minimal_palette: boolean
        prefers_rich_palette: boolean
    } | null
}

/**
 * Extract + sanitize overrides from `brand.settings.ad_style`. Only keys
 * that match the AdStyleOverrides shape are passed through — unknown keys
 * are dropped so a corrupted settings blob can't break composition.
 */
function readAdStyleOverrides(settings: Record<string, unknown> | null | undefined): AdStyleOverrides {
    const raw = settings && typeof settings === 'object' ? (settings as Record<string, unknown>).ad_style : undefined
    if (!raw || typeof raw !== 'object') return {}
    const src = raw as Record<string, unknown>
    const out: AdStyleOverrides = {}
    const passString = <K extends keyof AdStyleOverrides>(k: K, value: unknown) => {
        if (typeof value === 'string') (out as Record<string, unknown>)[k] = value
    }
    const passNumber = <K extends keyof AdStyleOverrides>(k: K, value: unknown) => {
        if (typeof value === 'number' && Number.isFinite(value)) (out as Record<string, unknown>)[k] = value
    }
    passString('dominantHueStrategy', src.dominantHueStrategy)
    passString('backgroundPreference', src.backgroundPreference)
    passString('headlineStyle', src.headlineStyle)
    passNumber('headlineGhostOpacity', src.headlineGhostOpacity)
    passNumber('headlineGhostStrokePx', src.headlineGhostStrokePx)
    passString('holdingShapeStyle', src.holdingShapeStyle)
    passNumber('holdingShapeStrokePx', src.holdingShapeStrokePx)
    passNumber('holdingShapeCornerRadius', src.holdingShapeCornerRadius)
    passString('watermarkMode', src.watermarkMode)
    passNumber('watermarkOpacity', src.watermarkOpacity)
    passString('photoTreatment', src.photoTreatment)
    passString('footerStyle', src.footerStyle)
    passString('ctaStyle', src.ctaStyle)
    passString('voiceTone', src.voiceTone)
    return out
}

const DEFAULT_PRIMARY = '#1f2937' // slate-800 — neutral enough for any brand
const DEFAULT_SECONDARY = '#6b7280' // slate-500
const DEFAULT_ACCENT = '#6366f1' // indigo-500

function isHex(v: unknown): v is string {
    return typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v)
}

function normalizeVoice(raw: string | null | undefined): BrandAdStyle['voiceTone'] {
    const t = (raw ?? '').toLowerCase()
    if (t.includes('play') || t.includes('fun')) return 'playful'
    if (t.includes('bold') || t.includes('confident')) return 'bold'
    if (t.includes('heritage') || t.includes('classic') || t.includes('craft')) return 'heritage'
    if (t.includes('tech') || t.includes('spec')) return 'technical'
    if (t.includes('minimal') || t.includes('clean')) return 'minimal'
    if (t.includes('celebra') || t.includes('event')) return 'celebratory'
    return 'bold'
}

/**
 * Translate aggregated reference-gallery hints into a partial BrandAdStyle
 * delta. Conservative by design — we only emit a field when the gallery's
 * signal is unambiguous enough that the aggregator flipped a suggestion
 * flag. Everything else stays untouched so pure-inference defaults still
 * apply.
 *
 * Keep this function pure: given the same hints, it must return the same
 * delta. Callers rely on that for memoization in live-preview UIs.
 */
function referenceHintsToDelta(
    hints: BrandAdReferenceHints | null | undefined,
): Partial<BrandAdStyle> {
    if (!hints?.suggestions) return {}
    const s = hints.suggestions
    const delta: Partial<BrandAdStyle> = {}

    // Brightness → background preference. "Dark" nudges toward solid-black
    // canvases; "light" nudges toward paper/solid-white. We don't touch
    // gradient/photo preferences from brightness alone since those are
    // compositional choices the reference can't fully telegraph.
    if (s.prefers_dark_backgrounds) {
        delta.backgroundPreference = 'black'
    } else if (s.prefers_light_backgrounds) {
        delta.backgroundPreference = 'paper'
    }

    // Palette richness → photo treatment proxy. Rich palettes usually
    // signal photography-heavy references; minimal palettes signal graphic
    // / typographic references where photo treatments are irrelevant but
    // holdingShape simplicity matters.
    if (s.prefers_minimal_palette) {
        delta.holdingShapeStyle = 'none'
    }

    // Saturation → ghost-headline intensity + watermark visibility. Muted
    // galleries want quieter treatments; vibrant galleries can sustain
    // louder strokes and watermarks.
    if (s.prefers_muted) {
        delta.headlineGhostOpacity = 0.18
        delta.watermarkOpacity = 0.08
    } else if (s.prefers_vibrant) {
        delta.headlineGhostOpacity = 0.34
        delta.watermarkOpacity = 0.16
    }

    // Dominant hue bucket → dominantHueStrategy. If references lean warm
    // and the brand has an accent color, favor accent-driven heat; if
    // they lean cool, keep brand primary (which is usually the cool
    // anchor). Neutral leaves strategy at inference default.
    if (hints.dominant_hue_bucket === 'warm') {
        delta.dominantHueStrategy = 'accent'
    }

    return delta
}

/**
 * Given a brand hint + optional wizard defaults, return a complete BrandAdStyle.
 * Callers should pass whatever they have — missing fields fall back to
 * neutral defaults that still produce a respectable composition.
 *
 * `referenceHints` (optional) are the aggregated signals from the brand's
 * reference-ad gallery (see {@link BrandAdReferenceHints}). Applied as a
 * middle layer between voice-driven inference and user overrides:
 *
 *   inference  <  referenceHints  <  overrides
 *
 * So a brand that's uploaded 5 dark+muted references will see `black` as
 * their default background preference, unless/until they explicitly set
 * a different backgroundPreference override — which still wins.
 */
export function deriveBrandAdStyle(
    brand: BrandAdStyleHint | null | undefined,
    wizardDefaults: WizardDefaults | null | undefined,
    referenceHints?: BrandAdReferenceHints | null,
): BrandAdStyle {
    const primary = isHex(brand?.primary_color) ? brand!.primary_color! : DEFAULT_PRIMARY
    const secondary = isHex(brand?.secondary_color) ? brand!.secondary_color! : DEFAULT_SECONDARY
    const accent = isHex(brand?.accent_color) ? brand!.accent_color! : DEFAULT_ACCENT

    const voiceTone = normalizeVoice(brand?.voice)

    // Voice-to-style defaults — rough but consistent. Users will be able to
    // override these once the settings surface lands (plan phase 8).
    const headlineStyle: BrandAdStyle['headlineStyle'] =
        voiceTone === 'playful' ? 'ghost_filled_pair'
            : voiceTone === 'heritage' ? 'script_plus_caps'
            : voiceTone === 'technical' ? 'bold_display_stack'
            : voiceTone === 'celebratory' ? 'grunge_stacked'
            : 'ghost_filled_pair'

    const holdingShapeStyle: BrandAdStyle['holdingShapeStyle'] =
        voiceTone === 'heritage' ? 'ornamented'
            : voiceTone === 'minimal' ? 'none'
            : 'hairline_rect'

    const watermarkMode: BrandAdStyle['watermarkMode'] =
        voiceTone === 'minimal' ? 'corner_only'
            : voiceTone === 'technical' ? 'corner_only'
            : 'both'

    const footerStyle: BrandAdStyle['footerStyle'] =
        voiceTone === 'minimal' ? 'logo_centered'
            : voiceTone === 'technical' ? 'none'
            : 'white_bar'

    const ctaStyle: BrandAdStyle['ctaStyle'] =
        voiceTone === 'minimal' ? 'underline'
            : voiceTone === 'heritage' ? 'pill_outlined'
            : 'pill_filled'

    const photoTreatment: BrandAdStyle['photoTreatment'] =
        voiceTone === 'heritage' ? 'duotone_primary'
            : voiceTone === 'technical' ? 'glow'
            : 'natural'

    const backgroundPreference: BrandAdStyle['backgroundPreference'] =
        voiceTone === 'heritage' ? 'paper'
            : voiceTone === 'technical' ? 'gradient_radial'
            : 'solid'

    const inferred: BrandAdStyle = {
        dominantHueStrategy: 'brand_primary',
        backgroundPreference,
        headlineStyle,
        headlineGhostOpacity: 0.28,
        headlineGhostStrokePx: 2,
        holdingShapeStyle,
        holdingShapeStrokePx: 1.5,
        holdingShapeCornerRadius: 4,
        watermarkMode,
        watermarkOpacity: 0.12,
        photoTreatment,
        footerStyle,
        ctaStyle,
        voiceTone,
        primaryColor: primary,
        secondaryColor: secondary,
        accentColor: accent,
        primaryLogoAssetId: wizardDefaults?.logo?.id,
    }

    // Merge layers bottom-up:
    //   1. voice/color inference (above)
    //   2. reference-gallery hints (next) — only fire on unambiguous signals
    //   3. user overrides (last) — always win
    const hintsDelta = referenceHintsToDelta(referenceHints)
    const overrides = readAdStyleOverrides(brand?.settings)
    return { ...inferred, ...hintsDelta, ...overrides }
}

/**
 * Pick the right logo id for a given surface given whatever the wizard has
 * available. MVP-level: we only have the primary logo exposed through
 * wizard-defaults. A later pass will expose dark/light variants and let the
 * recipe pick automatically based on dominant-hue luminance.
 */
export function resolveLogoAssetIdForSurface(
    style: BrandAdStyle,
    surface: 'primary' | 'dark' | 'light' = 'primary',
): string | undefined {
    if (surface === 'dark') return style.darkLogoAssetId ?? style.primaryLogoAssetId
    if (surface === 'light') return style.lightLogoAssetId ?? style.primaryLogoAssetId
    return style.primaryLogoAssetId
}

/**
 * Lightweight luminance calc (0 = black, 1 = white). Used by recipes to pick
 * whether text should be white or dark on a given dominant hue.
 */
export function luminanceOf(hex: string): number {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex)
    if (!m) return 0.5
    const n = parseInt(m[1], 16)
    const r = ((n >> 16) & 0xff) / 255
    const g = ((n >> 8) & 0xff) / 255
    const b = (n & 0xff) / 255
    // Relative luminance (WCAG).
    const srgb = [r, g, b].map((c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)))
    return 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2]
}

/**
 * Pick a readable ink color (white or dark) for text laid on a given BG hex.
 * Simple threshold — works well enough for solid fills. Recipes overlaying text
 * on photography should still use text-boost rather than relying on this.
 */
export function inkOnColor(hex: string): string {
    return luminanceOf(hex) > 0.55 ? '#111827' : '#ffffff'
}

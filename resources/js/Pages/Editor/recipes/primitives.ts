/**
 * Brand-DNA Ad Recipes — reusable primitive blueprint factories.
 *
 * Each factory returns a list of `LayerBlueprint` that the existing Studio
 * materializer can turn into real layers. Recipes compose these primitives
 * rather than building blueprints from scratch, so visual grammar stays
 * consistent across archetypes (same ghost/filled pair in every recipe, same
 * footer bar, same watermark pair, etc.).
 *
 * **Conventions**
 * - Coordinates are in 0..1 ratios so recipes stay format-agnostic; the
 *   materializer turns them into pixel transforms against the canvas size.
 * - Colors are passed through verbatim — callers resolve brand colors before
 *   calling these.
 * - Groups: primitives that emit 2+ related blueprints return them with a
 *   shared `groupKey` so they move/resize as a unit in the editor.
 */

import type { LayerBlueprint } from '../templateConfig'
import type { BrandAdStyle } from './types'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

// ── Shared helpers ────────────────────────────────────────────────

/**
 * Convert a `#rrggbb` to `rgba(r,g,b,a)`. Used everywhere we want to apply a
 * brand color at partial opacity without losing the base hue.
 */
export function hexToRgba(hex: string, alpha: number): string {
    const m = /^#([0-9a-fA-F]{6})$/.exec(hex)
    if (!m) return hex
    const n = parseInt(m[1], 16)
    const r = (n >> 16) & 0xff
    const g = (n >> 8) & 0xff
    const b = n & 0xff
    return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, alpha))})`
}

// ── 1. Toned background ───────────────────────────────────────────

/**
 * Background fill layer in a brand / product hue. No photography.
 * Use for monochromatic product-hero, bold-display-tech, and other
 * recipes where the BG is a solid color rather than an image.
 */
export function createTonedBackground(opts: {
    hue: string
    mode?: 'solid' | 'gradient_radial' | 'gradient_linear' | 'black'
    /** Gradient target hue for linear; ignored for `solid`/`black`. */
    gradientEndHue?: string
    /** Angle for linear gradient, 0 = top→bottom = 180° (default 180). */
    gradientAngleDeg?: number
}): LayerBlueprint {
    const { hue, mode = 'solid', gradientEndHue, gradientAngleDeg } = opts
    if (mode === 'black') {
        return {
            name: 'Background',
            type: 'fill',
            role: 'background',
            widthRatio: 1,
            heightRatio: 1,
            xRatio: 0,
            yRatio: 0,
            defaults: { fillKind: 'solid', color: '#000000' },
        }
    }
    if (mode === 'gradient_radial') {
        // `fill` layers only render linear gradients today — emulate a radial
        // vignette by a diagonal gradient with the dominant hue sitting in
        // the middle. Not perfect; a real radial needs a renderer feature.
        return {
            name: 'Background',
            type: 'fill',
            role: 'background',
            widthRatio: 1,
            heightRatio: 1,
            xRatio: 0,
            yRatio: 0,
            defaults: {
                fillKind: 'gradient',
                color: hue,
                gradientStartColor: gradientEndHue ?? hexToRgba(hue, 0.6),
                gradientEndColor: hue,
                gradientAngleDeg: gradientAngleDeg ?? 135,
            },
        }
    }
    if (mode === 'gradient_linear') {
        return {
            name: 'Background',
            type: 'fill',
            role: 'background',
            widthRatio: 1,
            heightRatio: 1,
            xRatio: 0,
            yRatio: 0,
            defaults: {
                fillKind: 'gradient',
                color: hue,
                gradientStartColor: hue,
                gradientEndColor: gradientEndHue ?? hexToRgba(hue, 0.6),
                gradientAngleDeg: gradientAngleDeg ?? 180,
            },
        }
    }
    return {
        name: 'Background',
        type: 'fill',
        role: 'background',
        widthRatio: 1,
        heightRatio: 1,
        xRatio: 0,
        yRatio: 0,
        defaults: { fillKind: 'solid', color: hue },
    }
}

// ── 2. Photo background ───────────────────────────────────────────

/**
 * Full-bleed photo background slot. If `heroAssetId` is provided we pre-seed
 * the slot so the wizard lands on the composition with a real photo; otherwise
 * the slot stays empty and the editor's "Click to pick a photo" placeholder
 * shows.
 */
export function createPhotoBackground(opts: {
    heroAssetId?: string
    naturalWidth?: number
    naturalHeight?: number
}): LayerBlueprint {
    const defaults: Record<string, unknown> = { fit: 'cover' }
    if (opts.heroAssetId) {
        defaults.assetId = opts.heroAssetId
        defaults.assetUrl = editorBridgeFileUrlForAssetId(opts.heroAssetId)
        if (opts.naturalWidth) defaults.naturalWidth = opts.naturalWidth
        if (opts.naturalHeight) defaults.naturalHeight = opts.naturalHeight
    }
    return {
        name: 'Background',
        type: 'image',
        role: 'background',
        widthRatio: 1,
        heightRatio: 1,
        xRatio: 0,
        yRatio: 0,
        defaults,
    }
}

// ── 3. Ghost + filled headline pair ───────────────────────────────

/**
 * The Shefit signature — an outline ("ghost") word layered with a filled
 * word, stacked vertically. The ghost word renders as transparent-filled
 * text with a visible CSS `-webkit-text-stroke`, driven by the
 * `strokeWidth` + `strokeColor` fields on {@link TextLayer.style}.
 *
 * The two blueprints share a groupKey so they move as a unit.
 */
export function createGhostFilledHeadline(opts: {
    ghost: string
    filled: string
    xRatio: number
    yRatio: number
    widthRatio: number
    heightRatio: number
    fillColor: string
    /** Stroke color for the ghost word. Defaults to the fill color. */
    ghostColor?: string
    /**
     * Legacy opacity hook — ignored by the new stroke renderer. Kept on the
     * signature so callers written against the MVP API keep compiling. Will
     * be removed once downstream callers migrate.
     */
    ghostOpacity?: number
    fontSize?: number
    fontWeight?: number
    groupKey?: string
    /** `'stacked'` (default) = ghost above filled; `'overlap'` = same line, ghost behind. */
    layout?: 'stacked' | 'overlap'
}): LayerBlueprint[] {
    const {
        ghost,
        filled,
        xRatio,
        yRatio,
        widthRatio,
        heightRatio,
        fillColor,
        ghostColor,
        fontSize = 96,
        fontWeight = 800,
        groupKey = 'headline_pair',
        layout = 'stacked',
    } = opts

    // Ghost style: transparent-filled text with a visible stroke. The
    // renderer turns strokeWidth + strokeColor into `-webkit-text-stroke`,
    // which reads as a true outline — the old low-opacity fill approximation
    // is gone. Ghost stroke width scales loosely with font size so it stays
    // proportional on very small / very large canvases.
    const ghostStrokePx = Math.max(1.5, Math.round(fontSize / 48))
    const ghostStrokeColor = ghostColor ?? fillColor

    if (layout === 'overlap') {
        return [
            {
                name: 'Headline (ghost)',
                type: 'text',
                role: 'headline',
                xRatio,
                yRatio,
                widthRatio,
                heightRatio,
                defaults: {
                    content: ghost,
                    fontSize: Math.round(fontSize * 1.05),
                    fontWeight: Math.max(100, fontWeight - 400),
                    // Transparent fill so only the stroke renders.
                    color: 'transparent',
                    strokeWidth: ghostStrokePx,
                    strokeColor: ghostStrokeColor,
                },
                groupKey,
            },
            {
                name: 'Headline (filled)',
                type: 'text',
                role: 'headline',
                xRatio,
                yRatio,
                widthRatio,
                heightRatio,
                defaults: { content: filled, fontSize, fontWeight, color: fillColor },
                groupKey,
            },
        ]
    }

    // Stacked: split the available height in half, ghost on top, filled below.
    const half = heightRatio / 2
    return [
        {
            name: 'Headline (ghost)',
            type: 'text',
            role: 'headline',
            xRatio,
            yRatio,
            widthRatio,
            heightRatio: half,
            defaults: {
                content: ghost,
                fontSize,
                fontWeight: Math.max(100, fontWeight - 400),
                color: 'transparent',
                strokeWidth: ghostStrokePx,
                strokeColor: ghostStrokeColor,
            },
            groupKey,
        },
        {
            name: 'Headline (filled)',
            type: 'text',
            role: 'headline',
            xRatio,
            yRatio: yRatio + half,
            widthRatio,
            heightRatio: half,
            defaults: { content: filled, fontSize, fontWeight, color: fillColor },
            groupKey,
        },
    ]
}

// ── 4. Holding shape ──────────────────────────────────────────────

/**
 * A thin-stroke rectangle that "holds" a product name + optional sub-detail.
 * Shefit uses these almost everywhere to frame "Flex / Sports Bra" etc.
 *
 * Implemented as a hairline fill layer (transparent interior) plus N text
 * layers stacked inside it. The fill + text share a groupKey so the whole
 * thing moves as one.
 *
 * MVP caveat: our fill layer doesn't natively support "border only" — we
 * approximate with a nearly-transparent fill and depend on text being drawn
 * on top. A follow-up will add a real stroke field to FillLayer.
 */
export function createHoldingShape(opts: {
    xRatio: number
    yRatio: number
    widthRatio: number
    heightRatio: number
    strokeColor: string
    /** Currently unused — reserved for when FillLayer supports borderStrokeWidth. */
    strokePx?: number
    cornerRadius?: number
    textLines: Array<{ content: string; fontSize?: number; fontWeight?: number; color?: string }>
    groupKey?: string
}): LayerBlueprint[] {
    const {
        xRatio,
        yRatio,
        widthRatio,
        heightRatio,
        strokeColor,
        cornerRadius = 4,
        textLines,
        groupKey = 'holding',
    } = opts

    const out: LayerBlueprint[] = []

    // The "frame" — transparent interior with a real CSS border. Previously
    // we hacked this by setting an almost-transparent fill so the layer was
    // visible in the editor; now the fill-layer render path honors
    // borderStrokeWidth + borderStrokeColor, so we can go fully transparent
    // and still see the border. That fixes the "empty frame looks invisible"
    // caveat called out in the first MVP pass.
    out.push({
        name: 'Holding frame',
        type: 'fill',
        role: 'overlay',
        xRatio,
        yRatio,
        widthRatio,
        heightRatio,
        defaults: {
            fillKind: 'solid',
            color: 'transparent',
            borderRadius: cornerRadius,
            borderStrokeWidth: opts.strokePx ?? 1.5,
            borderStrokeColor: strokeColor,
        },
        groupKey,
    })

    // Stacked text lines inside the frame.
    if (textLines.length > 0) {
        const padY = heightRatio * 0.15
        const innerY = yRatio + padY
        const innerH = heightRatio - padY * 2
        const slotH = innerH / textLines.length
        textLines.forEach((line, i) => {
            out.push({
                name: `Holding line ${i + 1}`,
                type: 'text',
                role: 'subheadline',
                xRatio,
                yRatio: innerY + i * slotH,
                widthRatio,
                heightRatio: slotH,
                defaults: {
                    content: line.content,
                    fontSize: line.fontSize ?? 18,
                    fontWeight: line.fontWeight ?? 500,
                    color: line.color ?? strokeColor,
                },
                groupKey,
            })
        })
    }

    return out
}

// ── 5. Watermark pair (logo twice: faded BG + corner) ─────────────

/**
 * Stamps the brand mark on the composition, optionally as a faded oversize
 * watermark behind the hero AND as a crisp small mark in a corner. Shefit's
 * crown is the canonical example.
 *
 * `mode` controls which instances are emitted. Both instances share the
 * same asset id and style so updating the brand logo updates both.
 */
export function createWatermarkPair(opts: {
    logoAssetId?: string
    mode: BrandAdStyle['watermarkMode']
    opacity?: number
    /** Which corner the crisp mark goes in. Default top-right (Shefit). */
    corner?: 'tl' | 'tr' | 'bl' | 'br' | 'bc'
    /** Size of the faded watermark as a fraction of canvas. Default 0.55. */
    fadedSize?: number
    /** Size of the corner mark as a fraction of canvas width. Default 0.08. */
    cornerSize?: number
}): LayerBlueprint[] {
    const {
        logoAssetId,
        mode,
        opacity = 0.12,
        corner = 'tr',
        fadedSize = 0.55,
        cornerSize = 0.08,
    } = opts

    if (mode === 'none' || !logoAssetId) return []

    const out: LayerBlueprint[] = []

    const logoUrl = editorBridgeFileUrlForAssetId(logoAssetId)

    if (mode === 'faded_bg' || mode === 'both') {
        out.push({
            name: 'Watermark (faded)',
            type: 'image',
            role: 'overlay',
            xRatio: (1 - fadedSize) / 2,
            yRatio: (1 - fadedSize) / 2,
            widthRatio: fadedSize,
            heightRatio: fadedSize,
            defaults: {
                fit: 'contain',
                assetId: logoAssetId,
                assetUrl: logoUrl,
                opacity,
            },
        })
    }

    if (mode === 'corner_only' || mode === 'both') {
        const margin = 0.03
        let x = margin
        let y = margin
        if (corner === 'tr' || corner === 'br') x = 1 - cornerSize - margin
        if (corner === 'bl' || corner === 'br') y = 1 - cornerSize - margin
        if (corner === 'bc') {
            x = (1 - cornerSize) / 2
            y = 1 - cornerSize - margin
        }
        out.push({
            name: 'Watermark (corner)',
            type: 'image',
            role: 'logo',
            xRatio: x,
            yRatio: y,
            widthRatio: cornerSize,
            heightRatio: cornerSize,
            defaults: {
                fit: 'contain',
                assetId: logoAssetId,
                assetUrl: logoUrl,
            },
        })
    }

    return out
}

// ── 6. Footer bar (white/dark lockup strip) ───────────────────────

/**
 * Horizontal bar across the bottom of the composition holding the brand logo,
 * a wordmark/tagline, and optionally a CTA pill. Matches Lurvey / Shefit /
 * Dunkin footer styles.
 *
 * Emits a group: fill-bar + logo + wordmark text (+ CTA pill if `ctaLabel`).
 */
export function createFooterBar(opts: {
    style: BrandAdStyle['footerStyle']
    logoAssetId?: string
    wordmarkText?: string
    wordmarkColor?: string
    barHeightRatio?: number
    ctaLabel?: string
    ctaStyle?: BrandAdStyle['ctaStyle']
    ctaHue?: string
    groupKey?: string
}): LayerBlueprint[] {
    const {
        style,
        logoAssetId,
        wordmarkText,
        wordmarkColor,
        barHeightRatio = 0.09,
        ctaLabel,
        ctaStyle = 'pill_filled',
        ctaHue,
        groupKey = 'footer',
    } = opts

    if (style === 'none') return []

    const barY = 1 - barHeightRatio
    const isDark = style === 'dark_bar'
    const barColor = style === 'logo_centered' ? 'transparent' : isDark ? '#111827' : '#ffffff'
    const ink = wordmarkColor ?? (isDark ? '#ffffff' : '#111827')

    const out: LayerBlueprint[] = []

    if (style !== 'logo_centered') {
        out.push({
            name: 'Footer bar',
            type: 'fill',
            role: 'overlay',
            xRatio: 0,
            yRatio: barY,
            widthRatio: 1,
            heightRatio: barHeightRatio,
            defaults: { fillKind: 'solid', color: barColor },
            groupKey,
        })
    }

    if (logoAssetId) {
        // Logo at the left third of the bar, constrained to a square area.
        const logoSize = barHeightRatio * 0.7
        out.push({
            name: 'Footer logo',
            type: 'image',
            role: 'logo',
            xRatio: 0.04,
            yRatio: barY + (barHeightRatio - logoSize) / 2,
            widthRatio: logoSize, // using height as width keeps the "icon-like" slot square in ratios
            heightRatio: logoSize,
            defaults: {
                fit: 'contain',
                assetId: logoAssetId,
                assetUrl: editorBridgeFileUrlForAssetId(logoAssetId),
            },
            groupKey,
        })
    }

    if (wordmarkText) {
        // Wordmark text next to the logo.
        out.push({
            name: 'Footer wordmark',
            type: 'text',
            role: 'body',
            xRatio: 0.16,
            yRatio: barY + barHeightRatio * 0.28,
            widthRatio: 0.5,
            heightRatio: barHeightRatio * 0.5,
            defaults: {
                content: wordmarkText,
                fontSize: 28,
                fontWeight: 500,
                color: ink,
            },
            groupKey,
        })
    }

    if (ctaLabel) {
        // CTA pill on the right side of the bar.
        const ctaW = 0.22
        const ctaH = barHeightRatio * 0.58
        const ctaX = 1 - ctaW - 0.04
        const ctaY = barY + (barHeightRatio - ctaH) / 2
        const pillHue = ctaHue ?? (isDark ? '#ffffff' : '#111827')
        const pillInk = isDark ? '#111827' : '#ffffff'
        if (ctaStyle === 'pill_outlined') {
            out.push({
                name: 'CTA pill',
                type: 'fill',
                role: 'cta_button',
                xRatio: ctaX,
                yRatio: ctaY,
                widthRatio: ctaW,
                heightRatio: ctaH,
                defaults: {
                    fillKind: 'solid',
                    color: 'transparent',
                    borderRadius: 999,
                    borderStrokeWidth: 2,
                    borderStrokeColor: pillHue,
                },
                groupKey: 'cta',
            })
            out.push({
                name: 'CTA label',
                type: 'text',
                role: 'cta',
                xRatio: ctaX,
                yRatio: ctaY,
                widthRatio: ctaW,
                heightRatio: ctaH,
                defaults: { content: ctaLabel, fontSize: 20, fontWeight: 600, color: pillHue },
                groupKey: 'cta',
            })
        } else {
            out.push({
                name: 'CTA pill',
                type: 'fill',
                role: 'cta_button',
                xRatio: ctaX,
                yRatio: ctaY,
                widthRatio: ctaW,
                heightRatio: ctaH,
                defaults: { fillKind: 'solid', color: pillHue, borderRadius: 999 },
                groupKey: 'cta',
            })
            out.push({
                name: 'CTA label',
                type: 'text',
                role: 'cta',
                xRatio: ctaX,
                yRatio: ctaY,
                widthRatio: ctaW,
                heightRatio: ctaH,
                defaults: { content: ctaLabel, fontSize: 20, fontWeight: 600, color: pillInk },
                groupKey: 'cta',
            })
        }
    }

    return out
}

// ── 7. CTA pill (standalone) ──────────────────────────────────────

/**
 * Standalone CTA button — pill-shaped. Emits a fill + text sharing groupKey
 * 'cta' (matching the existing convention in LAYOUT_STYLES).
 */
export function createCtaPill(opts: {
    xRatio: number
    yRatio: number
    widthRatio: number
    heightRatio: number
    label: string
    style: BrandAdStyle['ctaStyle']
    hue: string
    ink?: string
}): LayerBlueprint[] {
    const { xRatio, yRatio, widthRatio, heightRatio, label, style, hue, ink } = opts
    if (style === 'none') return []

    if (style === 'underline') {
        // Minimal underlined text CTA — just a text layer with the label.
        return [
            {
                name: 'CTA',
                type: 'text',
                role: 'cta',
                xRatio,
                yRatio,
                widthRatio,
                heightRatio,
                defaults: { content: label, fontSize: 20, fontWeight: 600, color: hue },
            },
        ]
    }

    const isOutlined = style === 'pill_outlined'
    const pillFill = isOutlined ? 'transparent' : hue
    const labelInk = ink ?? (isOutlined ? hue : '#ffffff')

    return [
        {
            name: 'CTA pill',
            type: 'fill',
            role: 'cta_button',
            xRatio,
            yRatio,
            widthRatio,
            heightRatio,
            defaults: isOutlined
                ? {
                    fillKind: 'solid',
                    color: pillFill,
                    borderRadius: 999,
                    borderStrokeWidth: 2,
                    borderStrokeColor: hue,
                }
                : { fillKind: 'solid', color: pillFill, borderRadius: 999 },
            groupKey: 'cta',
        },
        {
            name: 'CTA label',
            type: 'text',
            role: 'cta',
            xRatio,
            yRatio,
            widthRatio,
            heightRatio,
            defaults: { content: label, fontSize: 20, fontWeight: 600, color: labelInk },
            groupKey: 'cta',
        },
    ]
}

// ── 8. Text boost over photo ──────────────────────────────────────

/**
 * Bottom-anchored gradient overlay that boosts text readability on
 * photography. Matches the existing TEXT_BOOST preset but packaged as a
 * primitive so recipes can opt in without repeating configuration.
 */
export function createTextBoost(opts: {
    yRatio?: number
    heightRatio?: number
    direction?: 'bottom_up' | 'top_down'
    hue?: string
    opacity?: number
}): LayerBlueprint {
    const {
        yRatio = 0.65,
        heightRatio = 0.35,
        direction = 'bottom_up',
        hue = '#000000',
        opacity = 0.7,
    } = opts
    const tinted = hexToRgba(hue, opacity)
    const start = direction === 'bottom_up' ? 'transparent' : tinted
    const end = direction === 'bottom_up' ? tinted : 'transparent'
    return {
        name: 'Text Boost',
        type: 'fill',
        role: 'text_boost',
        xRatio: 0,
        yRatio,
        widthRatio: 1,
        heightRatio,
        defaults: {
            fillKind: 'gradient',
            color: tinted,
            gradientStartColor: start,
            gradientEndColor: end,
            gradientAngleDeg: 180,
        },
    }
}

/**
 * RecipePreview
 *
 * Tiny read-only renderer that composes a Studio recipe against a supplied
 * BrandAdStyle + (optional) hero/logo assets and paints the resulting
 * {@link LayerBlueprint} list into a scaled preview box.
 *
 * This is deliberately a *separate* lightweight renderer — not a cut-down
 * version of `AssetEditor.tsx` — because the preview's job is "show me
 * *approximately* what this recipe would produce on this brand", not pixel-
 * perfect parity with the Studio export. Every visual choice in here is
 * in service of that:
 *
 *  - Blueprint ratios (0..1) are scaled to the preview's absolute pixels.
 *  - Typography sizes are scaled from the canonical 1080-px canvas down to
 *    preview size so the visual proportion is preserved regardless of how
 *    small the preview is.
 *  - Fills, gradients, text strokes, and fill-layer borders all match the
 *    same CSS shapes the editor uses (see `AssetEditor.tsx`
 *    text + fill render paths).
 *  - Image layers with a resolvable `assetUrl` render as real `<img>` so
 *    users see the brand logo / hero landing in-place. Empty slots render
 *    as subtle diagonal-stripe placeholders — no "pick a photo" copy
 *    because the preview is read-only.
 *
 * The component is intentionally not memoized on `style` at the top level —
 * the AdStyleCard wants the preview to reflect dropdown flips *immediately*,
 * so we rely on React's default reconciliation. If recipe composition ever
 * becomes expensive (unlikely — it's pure data math) we can add memoization
 * here without changing the API.
 */

import { useMemo } from 'react'
import { composeRecipe, type BrandAdStyle, type RecipeContent, type RecipeKey } from '../../Pages/Editor/recipes'
import type { LayerBlueprint } from '../../Pages/Editor/templateConfig'

/**
 * Canonical canvas width the recipes target. All `fontSize` values in
 * blueprint defaults are authored against this canvas, so we scale them by
 * `previewWidth / 1080` to keep proportion correct at any preview size.
 */
const CANONICAL_WIDTH = 1080

export type RecipePreviewProps = {
    /** Recipe to compose. */
    recipeKey: RecipeKey
    /** Brand-ad-style (from `deriveBrandAdStyle` or your tuned overrides). */
    style: BrandAdStyle
    /** Pixel width of the preview box. */
    width: number
    /** Pixel height of the preview box. */
    height: number
    /**
     * Content slots for the preview. Every recipe has sensible placeholder
     * defaults when slots are empty, but callers who know which hero / logo
     * to show (e.g. the live AdStyleCard) should pass them in so users see
     * their actual brand in the preview.
     */
    content?: RecipeContent
    /** Accessible label — the preview is decorative otherwise. */
    label?: string
    /** Optional className forwarded to the outer wrapper. */
    className?: string
}

export default function RecipePreview({
    recipeKey,
    style,
    width,
    height,
    content,
    label,
    className,
}: RecipePreviewProps) {
    // Compose the recipe against the supplied format + content. `useMemo`
    // keys on the stringified style/content so dropdown flips in the card
    // above reliably trigger a re-composition without us having to wire a
    // manual "key" prop from the parent.
    const blueprints = useMemo<LayerBlueprint[]>(() => {
        try {
            return composeRecipe(recipeKey, {
                style,
                format: { width, height },
                content: content ?? {},
            }).blueprints
        } catch {
            // Guard against a recipe key not being in the registry yet.
            // Returning an empty blueprint list shows an empty canvas, which
            // is the right "nothing to preview" fallback.
            return []
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recipeKey, width, height, JSON.stringify(style), JSON.stringify(content ?? {})])

    const fontScale = width / CANONICAL_WIDTH

    return (
        <div
            className={className}
            role="img"
            aria-label={label ?? `Preview of ${recipeKey}`}
            style={{
                position: 'relative',
                width,
                height,
                overflow: 'hidden',
                borderRadius: 8,
                background: '#0b0d12',
                // Subtle fallback — if a recipe returns zero blueprints we
                // still want a visible box, not a black hole.
                backgroundImage:
                    blueprints.length === 0
                        ? 'repeating-linear-gradient(45deg, #1f2937 0, #1f2937 6px, #111827 6px, #111827 12px)'
                        : undefined,
            }}
        >
            {blueprints.map((bp, i) => renderBlueprint(bp, i, width, height, fontScale))}
        </div>
    )
}

// ── Blueprint → element ─────────────────────────────────────────────

function renderBlueprint(
    bp: LayerBlueprint,
    i: number,
    boxWidth: number,
    boxHeight: number,
    fontScale: number,
) {
    if (bp.enabled === false) return null

    const left = bp.xRatio * boxWidth
    const top = bp.yRatio * boxHeight
    const w = bp.widthRatio * boxWidth
    const h = bp.heightRatio * boxHeight

    const baseStyle: React.CSSProperties = {
        position: 'absolute',
        left,
        top,
        width: w,
        height: h,
        // Later items in the blueprint list paint over earlier items,
        // matching the editor's z-order.
        zIndex: i + 1,
        pointerEvents: 'none',
    }

    switch (bp.type) {
        case 'fill':
            return <FillPreview key={i} bp={bp} style={baseStyle} />
        case 'text':
            return <TextPreview key={i} bp={bp} style={baseStyle} fontScale={fontScale} />
        case 'image':
            return <ImagePreview key={i} bp={bp} style={baseStyle} />
        // Generative-image / other layer types are never emitted by recipes
        // today, so fall back to a subtle placeholder.
        default:
            return <div key={i} style={{ ...baseStyle, background: 'rgba(255,255,255,0.05)' }} />
    }
}

// ── Fill (solid / gradient) ─────────────────────────────────────────

function FillPreview({ bp, style }: { bp: LayerBlueprint; style: React.CSSProperties }) {
    const d = (bp.defaults ?? {}) as Record<string, unknown>
    const fillKind = (d.fillKind as 'solid' | 'gradient') ?? 'solid'
    const color = (d.color as string | undefined) ?? '#000000'
    const borderRadius = typeof d.borderRadius === 'number' ? (d.borderRadius as number) : undefined
    const borderStrokeWidth = typeof d.borderStrokeWidth === 'number' ? (d.borderStrokeWidth as number) : undefined
    const borderStrokeColor = (d.borderStrokeColor as string | undefined) ?? undefined

    let background: string = color
    if (fillKind === 'gradient') {
        const start = (d.gradientStartColor as string | undefined) ?? color
        const end = (d.gradientEndColor as string | undefined) ?? color
        const angle = typeof d.gradientAngleDeg === 'number' ? (d.gradientAngleDeg as number) : 180
        background = `linear-gradient(${angle}deg, ${start}, ${end})`
    }

    return (
        <div
            style={{
                ...style,
                background,
                borderRadius,
                border:
                    borderStrokeWidth && borderStrokeWidth > 0
                        ? `${borderStrokeWidth}px solid ${borderStrokeColor ?? color}`
                        : undefined,
                boxSizing: 'border-box',
            }}
        />
    )
}

// ── Text (with optional outline stroke) ─────────────────────────────

function TextPreview({
    bp,
    style,
    fontScale,
}: {
    bp: LayerBlueprint
    style: React.CSSProperties
    fontScale: number
}) {
    const d = (bp.defaults ?? {}) as Record<string, unknown>
    const content = (d.content as string | undefined) ?? ''
    const fontSize = ((d.fontSize as number | undefined) ?? 32) * fontScale
    const fontWeight = (d.fontWeight as number | undefined) ?? 400
    const color = (d.color as string | undefined) ?? '#ffffff'
    const textAlign = (d.textAlign as 'left' | 'center' | 'right' | undefined) ?? 'left'
    const lineHeight = (d.lineHeight as number | undefined) ?? 1.3
    const letterSpacing = (d.letterSpacing as number | undefined) ?? 0
    const strokeWidth = typeof d.strokeWidth === 'number' ? (d.strokeWidth as number) : 0
    const strokeColor = (d.strokeColor as string | undefined) ?? color
    // Scale stroke with fontScale too so outlines stay proportional at small
    // preview sizes — otherwise a 2px stroke authored for 1080px looks chunky
    // at 150px preview width.
    const scaledStrokeWidth = strokeWidth * fontScale

    const textStyle: React.CSSProperties = {
        ...style,
        display: 'flex',
        alignItems: 'center',
        justifyContent: textAlign === 'center' ? 'center' : textAlign === 'right' ? 'flex-end' : 'flex-start',
        fontSize,
        fontWeight,
        color,
        lineHeight,
        letterSpacing: letterSpacing * fontScale,
        textAlign,
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        overflow: 'hidden',
        WebkitTextStroke:
            scaledStrokeWidth > 0 ? `${scaledStrokeWidth}px ${strokeColor}` : undefined,
    }

    return <div style={textStyle}>{content}</div>
}

// ── Image (asset-url or placeholder) ────────────────────────────────

function ImagePreview({ bp, style }: { bp: LayerBlueprint; style: React.CSSProperties }) {
    const d = (bp.defaults ?? {}) as Record<string, unknown>
    const assetUrl = (d.assetUrl as string | undefined) ?? undefined
    const fit = ((d.fit as 'cover' | 'contain' | undefined) ?? 'cover') as 'cover' | 'contain'
    const opacity = typeof d.opacity === 'number' ? (d.opacity as number) : 1

    if (!assetUrl) {
        // Empty slot — paint a subtle diagonal-stripe placeholder. We don't
        // show "Click to pick a photo" copy because the preview is read-only.
        return (
            <div
                style={{
                    ...style,
                    backgroundImage:
                        'repeating-linear-gradient(45deg, rgba(255,255,255,0.04) 0, rgba(255,255,255,0.04) 4px, rgba(255,255,255,0.08) 4px, rgba(255,255,255,0.08) 8px)',
                    opacity: 0.8,
                }}
            />
        )
    }

    return (
        <img
            src={assetUrl}
            alt=""
            draggable={false}
            style={{
                ...style,
                objectFit: fit,
                opacity,
                userSelect: 'none',
            }}
            onError={(e) => {
                // Fail silently — hide the broken image so the placeholder
                // stripes show through the empty slot.
                ;(e.currentTarget as HTMLImageElement).style.display = 'none'
            }}
        />
    )
}

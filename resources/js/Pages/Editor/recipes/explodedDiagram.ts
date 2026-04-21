/**
 * Recipe: `exploded_diagram`
 *
 * Tech / spec callout pattern — the product sits center stage and numbered
 * callout chips orbit it, each pairing a numeral with a short feature
 * label. Think Apple spec slide, Specialized bike cutaway ad, Tesla
 * "here's what's inside" creative.
 *
 * Reads as "authoritative and technical" — the kind of creative you use
 * when the product's selling point is the engineering / the features, not
 * the lifestyle. Pairs naturally with brand voice = `technical`.
 *
 * Recognizable features:
 *  - Near-black gradient background with brand-hue glow behind the product
 *  - Centered product hero, large
 *  - 3–4 numbered callout chips at fixed anchor points around the product:
 *    numeral in a small circle + short label text to the side
 *  - Tracked-caps tagline at the top
 *  - Thin feature list at the bottom (when provided)
 *  - Corner brand mark
 *
 * Aspect-aware:
 *  - Vertical: product center, 2 callouts left + 2 callouts right
 *  - Square: same, tighter
 *  - Banner: product left-center, callouts stack right (fallback layout —
 *    the diagram layout is designed for portrait/square formats)
 *
 * Content:
 *  - `content.featureList` supplies the callout labels (we use up to 4).
 *    When empty, we render numbered circles with placeholder copy so the
 *    recipe demonstrates the pattern in its empty state.
 *  - `content.heroAssetId` — product cut-out.
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createWatermarkPair,
    hexToRgba,
} from './primitives'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const explodedDiagram: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    const hue = content.dominantHueOverride || style.primaryColor
    const ink = '#ffffff'

    // Callout labels — use provided featureList, else placeholders. Capped
    // at 4 so the composition stays legible.
    const features = (content.featureList && content.featureList.length > 0
        ? content.featureList
        : ['Feature one', 'Feature two', 'Feature three', 'Feature four']
    ).slice(0, 4)

    const blueprints: LayerBlueprint[] = []

    // 1. Near-black gradient background. Uses radial mode so the product
    //    is visually pushed to the center by the lighter hue concentration.
    blueprints.push(
        createTonedBackground({
            hue: '#070a12',
            mode: 'gradient_radial',
            gradientEndHue: hexToRgba(hue, 0.28),
            gradientAngleDeg: 135,
        }),
    )

    // 2. Brand-hue glow behind the product — a faint oversized circle-ish
    //    fill (rendered as a fill with extreme border radius). Makes the
    //    product feel "illuminated" without requiring a real radial
    //    gradient blend mode.
    {
        const glowSize = isBanner ? 0.5 : 0.7
        blueprints.push({
            name: 'Product glow',
            type: 'fill',
            role: 'overlay',
            xRatio: (1 - glowSize) / 2 + (isBanner ? -0.12 : 0),
            yRatio: (1 - glowSize) / 2,
            widthRatio: glowSize,
            heightRatio: glowSize,
            defaults: {
                fillKind: 'gradient',
                color: hexToRgba(hue, 0.45),
                gradientStartColor: hexToRgba(hue, 0.55),
                gradientEndColor: 'transparent',
                gradientAngleDeg: 135,
                borderRadius: 999,
            },
        })
    }

    // 3. Product hero — centered.
    const productBox = isBanner
        ? { x: 0.08, y: 0.12, w: 0.42, h: 0.76 }
        : isVertical
            ? { x: 0.18, y: 0.22, w: 0.64, h: 0.56 }
            : { x: 0.2, y: 0.2, w: 0.6, h: 0.6 }
    {
        const defaults: Record<string, unknown> = { fit: 'contain' }
        if (content.heroAssetId) {
            defaults.assetId = content.heroAssetId
            defaults.assetUrl = editorBridgeFileUrlForAssetId(content.heroAssetId)
        }
        blueprints.push({
            name: 'Product',
            type: 'image',
            role: 'hero_image',
            xRatio: productBox.x,
            yRatio: productBox.y,
            widthRatio: productBox.w,
            heightRatio: productBox.h,
            defaults,
        })
    }

    // 4. Tagline at top.
    if (!isSmall) {
        const tagline = (content.tagline ?? 'SPEC OVERVIEW').toUpperCase()
        blueprints.push({
            name: 'Tagline',
            type: 'text',
            role: 'tagline',
            xRatio: 0.08,
            yRatio: 0.06,
            widthRatio: 0.84,
            heightRatio: 0.05,
            defaults: {
                content: tagline,
                fontSize: 16,
                fontWeight: 700,
                color: hexToRgba(ink, 0.7),
                letterSpacing: 5,
                textAlign: isBanner ? 'right' : 'center',
            },
        })
    }

    // 5. Callout chips — numbered circles + label.
    //    For non-banner: 2 left + 2 right, stacked vertically along the
    //    sides of the product.
    //    For banner: stacked on the right column.
    type Anchor = { cx: number; cy: number; labelX: number; labelAnchor: 'left' | 'right' }
    const anchors: Anchor[] = isBanner
        ? features.map((_, i) => {
              const n = features.length
              const topPad = 0.15
              const rowH = (1 - topPad * 2) / n
              const cy = topPad + rowH * i + rowH / 2
              return { cx: 0.55, cy, labelX: 0.6, labelAnchor: 'left' }
          })
        : (() => {
              // Left side: indices 0 and 2 (top-left, bottom-left)
              // Right side: indices 1 and 3 (top-right, bottom-right)
              const base: Anchor[] = [
                  { cx: 0.08, cy: 0.3, labelX: 0.13, labelAnchor: 'left' },
                  { cx: 0.92, cy: 0.3, labelX: 0.87, labelAnchor: 'right' },
                  { cx: 0.08, cy: 0.62, labelX: 0.13, labelAnchor: 'left' },
                  { cx: 0.92, cy: 0.62, labelX: 0.87, labelAnchor: 'right' },
              ]
              return base.slice(0, features.length)
          })()

    const chipSize = isBanner ? 0.04 : 0.05
    features.forEach((label, i) => {
        const a = anchors[i]
        if (!a) return
        // Chip circle — transparent fill with brand-hue border + number inside.
        blueprints.push({
            name: `Callout ${i + 1} chip`,
            type: 'fill',
            role: 'overlay',
            xRatio: a.cx - chipSize / 2,
            yRatio: a.cy - chipSize / 2,
            widthRatio: chipSize,
            heightRatio: chipSize,
            defaults: {
                fillKind: 'solid',
                color: 'transparent',
                borderRadius: 999,
                borderStrokeWidth: 1.5,
                borderStrokeColor: hue,
            },
            groupKey: `callout_${i}`,
        })
        // Numeral inside the chip.
        blueprints.push({
            name: `Callout ${i + 1} number`,
            type: 'text',
            role: 'overlay',
            xRatio: a.cx - chipSize / 2,
            yRatio: a.cy - chipSize / 2,
            widthRatio: chipSize,
            heightRatio: chipSize,
            defaults: {
                content: String(i + 1),
                fontSize: isBanner ? 18 : 22,
                fontWeight: 700,
                color: hue,
                textAlign: 'center',
            },
            groupKey: `callout_${i}`,
        })
        // Leader rule — short horizontal segment pointing toward the label.
        const ruleLen = isBanner ? 0.04 : 0.05
        const ruleX = a.labelAnchor === 'left' ? a.cx + chipSize / 2 : a.cx - chipSize / 2 - ruleLen
        blueprints.push({
            name: `Callout ${i + 1} leader`,
            type: 'fill',
            role: 'overlay',
            xRatio: ruleX,
            yRatio: a.cy - 0.0025,
            widthRatio: ruleLen,
            heightRatio: 0.005,
            defaults: { fillKind: 'solid', color: hue },
            groupKey: `callout_${i}`,
        })
        // Label text.
        const labelW = isBanner ? 0.35 : 0.18
        const labelX = a.labelAnchor === 'left' ? a.labelX + ruleLen : a.labelX - ruleLen - labelW
        blueprints.push({
            name: `Callout ${i + 1} label`,
            type: 'text',
            role: 'body',
            xRatio: labelX,
            yRatio: a.cy - 0.04,
            widthRatio: labelW,
            heightRatio: 0.08,
            defaults: {
                content: label,
                fontSize: isBanner ? 16 : 15,
                fontWeight: 500,
                color: ink,
                textAlign: a.labelAnchor,
                lineHeight: 1.2,
            },
            groupKey: `callout_${i}`,
        })
    })

    // 6. Product name at the bottom.
    if (!isSmall) {
        const nameBox = isBanner
            ? { x: 0.5, y: 0.88, w: 0.48, h: 0.08 }
            : { x: 0.1, y: 0.85, w: 0.8, h: 0.08 }
        blueprints.push({
            name: 'Product name',
            type: 'text',
            role: 'headline',
            xRatio: nameBox.x,
            yRatio: nameBox.y,
            widthRatio: nameBox.w,
            heightRatio: nameBox.h,
            defaults: {
                content: content.productName ?? content.filledWord ?? 'Model X1',
                fontSize: isBanner ? 32 : 40,
                fontWeight: 200, // thin weight — tech reveal signature
                color: ink,
                textAlign: isBanner ? 'right' : 'center',
                letterSpacing: 2,
            },
        })
    }

    // 7. Corner brand mark.
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: 'corner_only',
            corner: 'tl',
            cornerSize: 0.07,
        }),
    )

    return {
        blueprints,
        notes: [
            `Background: near-black + ${hue} glow`,
            content.heroAssetId ? 'Product: pre-filled' : 'Product: empty slot',
            `Callouts: ${features.length} feature${features.length === 1 ? '' : 's'}${content.featureList ? '' : ' (placeholders)'}`,
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner (stacked right)' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

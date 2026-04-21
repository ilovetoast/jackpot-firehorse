/**
 * Recipe: `heritage_textured_cta`
 *
 * Craft / heritage pattern (Shoals "Good Folks", whiskey labels, craft beer,
 * artisan DTC). Warm textured background, serif/script headline with an
 * all-caps supporting line, an ornamented holding shape around the product
 * name, and an outlined CTA that never overpowers the composition. Reads
 * as "made with care" rather than "reach for the throat".
 *
 * Recognizable features:
 *  - Textured warm background (gradient linear MVP — swaps for a texture
 *    asset when brand provides one)
 *  - Script-weight line 1 + all-caps bold line 2 (ghost/filled primitive
 *    with inverted font-weight emphasis)
 *  - Ornamented holding shape — corner-radius goes to 0 with a thicker
 *    stroke for the "stamped" feel
 *  - Outlined CTA pill — forced outlined (filled pills break the aesthetic)
 *  - Small corner brand mark — usually bottom-center for the "maker's mark"
 *    feel
 *
 * Aspect-aware:
 *  - Vertical: headline top, holding shape center, CTA below, maker's mark
 *    bottom-center
 *  - Square: same, compressed
 *  - Banner: headline left, holding shape center-left, product right, CTA
 *    tucked into the left column
 *
 * Playbook note: this recipe intentionally does NOT use the tech-reveal
 * glow or the monochromatic hue-wash. It is the pure "brand trust" ad —
 * use it for launches, heritage moments, or product line restatements.
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createPhotoBackground,
    createHoldingShape,
    createCtaPill,
    hexToRgba,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const heritageTexturedCta: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    // Dominant hue: user override > product color > brand primary. For
    // heritage voices we darken it slightly so the texture reads.
    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)
    // Slightly darker gradient end for "texture" feel without a real texture
    // asset. If brand ever ships `content.textureAssetId`, we prefer that.
    const deepHue = hexToRgba(hue, 0.85)

    const blueprints: LayerBlueprint[] = []

    // 1. Background — texture asset if supplied, else warm gradient.
    if (content.textureAssetId) {
        blueprints.push({
            ...createPhotoBackground({ heroAssetId: content.textureAssetId }),
            name: 'Texture background',
        })
    } else {
        blueprints.push(
            createTonedBackground({
                hue,
                mode: 'gradient_linear',
                // End hue is the same brand hue at ~85% — gives a subtle
                // darker corner that reads as paper/leather without a real
                // texture. Users can replace with a texture asset in-editor.
                gradientEndHue: deepHue,
                gradientAngleDeg: 160,
            }),
        )
    }

    // 2. Script + caps headline pair. We use the ghost/filled primitive
    //    inverted: ghost-word = the script line (thin stroke at small size),
    //    filled word = the all-caps line (heavy). Layout 'stacked' places
    //    script on top, caps below — matches whiskey-label composition.
    const scriptLine = content.ghostWord ?? content.tagline ?? 'Handmade'
    const capsLine = (content.filledWord ?? content.productName ?? 'Heritage').toUpperCase()

    if (!isSmall) {
        const headlineBox = isBanner
            ? { x: 0.04, y: 0.14, w: 0.42, h: 0.5 }
            : isVertical
                ? { x: 0.08, y: 0.06, w: 0.84, h: 0.3 }
                : { x: 0.08, y: 0.08, w: 0.84, h: 0.28 }

        // Script line — italic-feel via light weight at moderate size.
        blueprints.push({
            name: 'Headline script',
            type: 'text',
            role: 'headline',
            xRatio: headlineBox.x,
            yRatio: headlineBox.y,
            widthRatio: headlineBox.w,
            heightRatio: headlineBox.h * 0.3,
            defaults: {
                content: scriptLine,
                fontSize: isBanner ? 42 : 56,
                fontWeight: 400,
                color: ink,
                textAlign: 'center',
            },
            groupKey: 'headline_heritage',
        })

        // Caps line — the dominant stamp.
        blueprints.push({
            name: 'Headline caps',
            type: 'text',
            role: 'headline',
            xRatio: headlineBox.x,
            yRatio: headlineBox.y + headlineBox.h * 0.32,
            widthRatio: headlineBox.w,
            heightRatio: headlineBox.h * 0.62,
            defaults: {
                content: capsLine,
                fontSize: isBanner ? 72 : 104,
                fontWeight: 900,
                color: ink,
                textAlign: 'center',
                letterSpacing: 2,
            },
            groupKey: 'headline_heritage',
        })
    }

    // 3. Ornamented holding shape around product name + variant. Corner
    //    radius 0 + thicker stroke = stamped / badge feel. Variant line
    //    sits below the main product name.
    const productName = content.productName ?? 'Signature Series'
    const productVariant = content.productVariant ?? (content.subline ?? '— EST. —')

    if (!isSmall) {
        const holdingBox = isBanner
            ? { x: 0.05, y: 0.68, w: 0.4, h: 0.22 }
            : isVertical
                ? { x: 0.18, y: 0.42, w: 0.64, h: 0.14 }
                : { x: 0.2, y: 0.46, w: 0.6, h: 0.16 }
        blueprints.push(
            ...createHoldingShape({
                xRatio: holdingBox.x,
                yRatio: holdingBox.y,
                widthRatio: holdingBox.w,
                heightRatio: holdingBox.h,
                strokeColor: ink,
                strokePx: Math.max(style.holdingShapeStrokePx, 2),
                // Heritage look: squared corners. Users can round in-editor.
                cornerRadius: 0,
                textLines: [
                    { content: productName, fontSize: 26, fontWeight: 700, color: ink },
                    { content: productVariant, fontSize: 14, fontWeight: 500, color: ink },
                ],
            }),
        )
    }

    // 4. Optional hero asset — heritage ads sometimes have a centered
    //    product photo between the headline and the holding shape. Only
    //    emit the slot if caller provided one or the brand prefers photo.
    if (content.heroAssetId) {
        const productBox = isBanner
            ? { x: 0.55, y: 0.08, w: 0.4, h: 0.84 }
            : isVertical
                ? { x: 0.25, y: 0.6, w: 0.5, h: 0.28 }
                : { x: 0.3, y: 0.64, w: 0.4, h: 0.28 }
        blueprints.push({
            name: 'Product',
            type: 'image',
            role: 'hero_image',
            xRatio: productBox.x,
            yRatio: productBox.y,
            widthRatio: productBox.w,
            heightRatio: productBox.h,
            defaults: {
                fit: 'contain',
                assetId: content.heroAssetId,
                assetUrl: editorBridgeFileUrlForAssetId(content.heroAssetId),
            },
        })
    }

    // 5. Maker's-mark brand logo. Bottom-center for the "stamped" feel.
    //    Skipped if brand has no logo or on very tight banner sizes where
    //    the CTA will land there instead.
    if (style.primaryLogoAssetId && !isSmall && !isBanner) {
        const logoSize = 0.1
        blueprints.push({
            name: "Maker's mark",
            type: 'image',
            role: 'logo',
            xRatio: (1 - logoSize) / 2,
            yRatio: 1 - logoSize - 0.04,
            widthRatio: logoSize,
            heightRatio: logoSize,
            defaults: {
                fit: 'contain',
                assetId: style.primaryLogoAssetId,
                assetUrl: editorBridgeFileUrlForAssetId(style.primaryLogoAssetId),
                opacity: 0.9,
            },
        })
    }

    // 6. CTA — forced outlined, heritage voices hate filled pills. Placed
    //    below the holding shape, above the maker's mark. For banners it
    //    slots into the left-column at the bottom.
    const ctaLabel = content.cta
    if (ctaLabel && !isSmall) {
        const ctaBox = isBanner
            ? { x: 0.05, y: 0.88, w: 0.22, h: 0.08 }
            : isVertical
                ? { x: 0.3, y: 0.86, w: 0.4, h: 0.06 }
                : { x: 0.36, y: 0.86, w: 0.28, h: 0.06 }
        blueprints.push(
            ...createCtaPill({
                xRatio: ctaBox.x,
                yRatio: ctaBox.y,
                widthRatio: ctaBox.w,
                heightRatio: ctaBox.h,
                label: ctaLabel,
                // Always outlined for the heritage aesthetic — even if the
                // brand's inferred ctaStyle is `pill_filled`.
                style: 'pill_outlined',
                hue: ink,
                ink,
            }),
        )
    }

    return {
        blueprints,
        notes: [
            content.textureAssetId ? 'Background: texture asset' : `Background: warm gradient (${hue})`,
            `Headline: ${scriptLine} / ${capsLine} (heritage voice)`,
            `Holding shape: squared stamp around "${productName}"`,
            style.primaryLogoAssetId && !isBanner ? "Maker's mark: bottom-center" : "Maker's mark: skipped",
            ctaLabel ? `CTA: ${ctaLabel} (forced outlined)` : 'CTA: skipped',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

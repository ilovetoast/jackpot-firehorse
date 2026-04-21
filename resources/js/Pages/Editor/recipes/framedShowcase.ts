/**
 * Recipe: `framed_showcase`
 *
 * Clean product-frame pattern (DTC luxury, fine goods, "the product is the
 * hero" catalog ads). A light / paper background, a thin-hairline rectangle
 * frame occupying the center of the composition, a centered product hero
 * inside the frame, and minimal typography above and below the frame.
 *
 * Reads as "considered and quiet" — the opposite of the tech glow or
 * monochromatic-hue wash. Use for lookbook cards, premium positioning,
 * editorial product reveals.
 *
 * Recognizable features:
 *  - Light / paper background (warm off-white; can swap to brand primary
 *    on brands that prefer a hue-dominant base)
 *  - Hairline frame with squared or slightly rounded corners
 *  - Product hero centered within the frame (respects aspect ratio)
 *  - Brand name or collection tag above the frame (small, tracked caps)
 *  - Product name + short spec line below the frame (centered)
 *  - Optional tiny brand mark in a corner (usually top-right)
 *
 * Aspect-aware:
 *  - Vertical: frame centered, tag above, copy below
 *  - Square: same, compressed
 *  - Banner: frame on the left side, product inside; tag + copy stack on
 *    the right column
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createHoldingShape,
    hexToRgba,
} from './primitives'
import { inkOnColor, luminanceOf } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const framedShowcase: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    // Pick an "editorial" paper tone: if the brand's primary is very dark,
    // the paper hue stays warm off-white; if it's bright, we lean the paper
    // slightly toward the brand hue for a subtle tint. The result is a
    // background that feels considered, not stock.
    const brandLum = luminanceOf(style.primaryColor)
    const paperHue = brandLum < 0.35
        ? '#f5f1ea'
        : hexToRgba(style.primaryColor, 0.08)
    // `inkOnColor` on the paper will basically always pick dark ink.
    const ink = inkOnColor(paperHue)

    const blueprints: LayerBlueprint[] = []

    // 1. Paper background.
    blueprints.push(createTonedBackground({ hue: paperHue, mode: 'solid' }))

    // 2. Frame — hairline holding shape. We intentionally don't put text
    //    inside it here (unlike the heritage recipe); the product is the
    //    content, the frame is just visual rhythm.
    const frameBox = isBanner
        ? { x: 0.05, y: 0.12, w: 0.42, h: 0.76 }
        : isVertical
            ? { x: 0.1, y: 0.2, w: 0.8, h: 0.55 }
            : { x: 0.15, y: 0.2, w: 0.7, h: 0.6 }

    blueprints.push(
        ...createHoldingShape({
            xRatio: frameBox.x,
            yRatio: frameBox.y,
            widthRatio: frameBox.w,
            heightRatio: frameBox.h,
            strokeColor: ink,
            strokePx: style.holdingShapeStrokePx,
            cornerRadius: style.holdingShapeCornerRadius,
            // No embedded text — the holding shape primitive handles the
            // empty-frame case now that borderStrokeWidth is live.
            textLines: [],
            groupKey: 'frame',
        }),
    )

    // 3. Product hero inside the frame. Pads ~8% inside the frame so the
    //    product never kisses the border.
    const padX = frameBox.w * 0.1
    const padY = frameBox.h * 0.1
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
            xRatio: frameBox.x + padX,
            yRatio: frameBox.y + padY,
            widthRatio: frameBox.w - padX * 2,
            heightRatio: frameBox.h - padY * 2,
            defaults,
        })
    }

    // 4. Top-of-frame tag — tracked caps. Use content.tagline or fall back
    //    to a generic editorial label.
    const topTag = (content.tagline ?? content.productVariant ?? 'Collection').toUpperCase()
    if (!isSmall) {
        const tagBox = isBanner
            ? { x: 0.52, y: 0.15, w: 0.44, h: 0.06 }
            : isVertical
                ? { x: frameBox.x, y: 0.08, w: frameBox.w, h: 0.06 }
                : { x: frameBox.x, y: 0.08, w: frameBox.w, h: 0.06 }
        blueprints.push({
            name: 'Collection tag',
            type: 'text',
            role: 'tagline',
            xRatio: tagBox.x,
            yRatio: tagBox.y,
            widthRatio: tagBox.w,
            heightRatio: tagBox.h,
            defaults: {
                content: topTag,
                fontSize: isBanner ? 16 : 18,
                fontWeight: 600,
                color: ink,
                textAlign: isBanner ? 'left' : 'center',
                letterSpacing: 3,
            },
        })
    }

    // 5. Below-frame product name + subline.
    const productName = content.productName ?? content.filledWord ?? 'Your Product'
    const subline = content.subline ?? content.body ?? ''
    if (!isSmall) {
        const nameBox = isBanner
            ? { x: 0.52, y: 0.32, w: 0.44, h: 0.14 }
            : isVertical
                ? { x: frameBox.x, y: 0.78, w: frameBox.w, h: 0.08 }
                : { x: frameBox.x, y: 0.82, w: frameBox.w, h: 0.08 }
        blueprints.push({
            name: 'Product name',
            type: 'text',
            role: 'headline',
            xRatio: nameBox.x,
            yRatio: nameBox.y,
            widthRatio: nameBox.w,
            heightRatio: nameBox.h,
            defaults: {
                content: productName,
                fontSize: isBanner ? 36 : 42,
                fontWeight: 600,
                color: ink,
                textAlign: isBanner ? 'left' : 'center',
            },
        })

        if (subline) {
            const subBox = isBanner
                ? { x: 0.52, y: 0.48, w: 0.44, h: 0.28 }
                : isVertical
                    ? { x: frameBox.x, y: 0.87, w: frameBox.w, h: 0.05 }
                    : { x: frameBox.x, y: 0.91, w: frameBox.w, h: 0.05 }
            blueprints.push({
                name: 'Subline',
                type: 'text',
                role: 'body',
                xRatio: subBox.x,
                yRatio: subBox.y,
                widthRatio: subBox.w,
                heightRatio: subBox.h,
                defaults: {
                    content: subline,
                    fontSize: isBanner ? 18 : 16,
                    fontWeight: 400,
                    color: hexToRgba(ink, 0.7),
                    textAlign: isBanner ? 'left' : 'center',
                    lineHeight: 1.4,
                },
            })
        }
    }

    // 6. Corner brand mark — small, top-right. Skip for banner where the
    //    right column already has the copy, or for very small sizes.
    if (style.primaryLogoAssetId && !isBanner && !isSmall) {
        const logoSize = 0.07
        const margin = 0.04
        blueprints.push({
            name: 'Corner logo',
            type: 'image',
            role: 'logo',
            xRatio: 1 - logoSize - margin,
            yRatio: margin,
            widthRatio: logoSize,
            heightRatio: logoSize,
            defaults: {
                fit: 'contain',
                assetId: style.primaryLogoAssetId,
                assetUrl: editorBridgeFileUrlForAssetId(style.primaryLogoAssetId),
                opacity: 0.85,
            },
        })
    }

    // 7. Optional CTA — tucked into the bottom-right on banner, skipped
    //    elsewhere (framed showcase is deliberately quiet; users who want
    //    a CTA should switch recipes).
    const ctaLabel = content.cta
    if (ctaLabel && isBanner && !isSmall) {
        blueprints.push({
            name: 'CTA',
            type: 'text',
            role: 'cta',
            xRatio: 0.52,
            yRatio: 0.82,
            widthRatio: 0.44,
            heightRatio: 0.08,
            defaults: {
                content: ctaLabel + ' \u2192',
                fontSize: 18,
                fontWeight: 600,
                color: style.primaryColor,
                textAlign: 'left',
                letterSpacing: 1,
            },
        })
    }

    return {
        blueprints,
        notes: [
            `Background: paper (${paperHue}) derived from brand luminance`,
            'Frame: hairline rectangle (editorial)',
            content.heroAssetId ? 'Product: pre-filled from wizard pick' : 'Product: empty slot — user will pick',
            `Typography: tag "${topTag}" · name "${productName}"${subline ? ' · subline present' : ''}`,
            style.primaryLogoAssetId && !isBanner ? 'Logo: small top-right' : 'Logo: skipped (banner or no asset)',
            ctaLabel && isBanner ? 'CTA: inline arrow (banner only)' : 'CTA: skipped (by design)',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

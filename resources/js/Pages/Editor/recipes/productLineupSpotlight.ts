/**
 * Recipe: `product_lineup_spotlight`
 *
 * Product-line reveal pattern (SKUs fanned out, one hero'd up front). Thinks
 * of it as "new flavors", "now in five variants", or a retail launch with
 * the lead product center stage and supporting SKUs flanking it.
 *
 * Reads as "variety shown at a glance" — useful for launches, seasonal
 * refreshes, and "pick your favorite" campaigns.
 *
 * Recognizable features:
 *  - Toned brand-hue background (solid or gradient)
 *  - Centered headline at the top
 *  - Primary product slot in the center, scaled up
 *  - 2–4 supporting product slots arranged symmetrically at smaller scale
 *  - Holding shape or tracked-caps tag beneath the lineup calling out the
 *    collection name ("New colors · Fall 2026")
 *  - Optional CTA pill at the bottom
 *  - Watermark always corner-only — faded BG would be unreadable with
 *    multiple product cut-outs competing
 *
 * Aspect-aware:
 *  - Vertical: headline top, row of products center, collection tag +
 *    CTA bottom
 *  - Square: same, compressed spacing
 *  - Banner: headline left, lineup right (5 SKUs in a row)
 *
 * Content fallback: we only render supporting SKU slots when caller
 * provides `sponsorLogoAssetIds` (reused as "related hero asset ids" —
 * we'll add a proper slot in a later content-type pass). If none provided,
 * we still show the primary hero slot + empty-placeholder stripes for the
 * other positions so users can drag in SKUs from their library.
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createWatermarkPair,
    createCtaPill,
    createHoldingShape,
    hexToRgba,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const productLineupSpotlight: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)

    const headline = content.filledWord ?? content.productName ?? 'Meet the lineup'
    const tagline = (content.tagline ?? 'FIVE NEW FLAVORS').toUpperCase()
    const ctaLabel = content.cta

    // Support assets — repurpose `sponsorLogoAssetIds` as "lineup SKUs" for
    // the MVP so users can test the recipe today. A later pass will add a
    // dedicated `lineupAssetIds` slot on RecipeContent.
    const supportIds = (content.sponsorLogoAssetIds ?? []).slice(0, 4)

    const blueprints: LayerBlueprint[] = []

    // 1. Background — toned brand hue, gradient when brand prefers it.
    const bgMode: Parameters<typeof createTonedBackground>[0]['mode'] =
        style.backgroundPreference === 'gradient_linear' || style.backgroundPreference === 'gradient_radial'
            ? 'gradient_radial'
            : 'solid'
    blueprints.push(
        createTonedBackground({
            hue,
            mode: bgMode,
            gradientEndHue: hexToRgba('#000000', 0.25),
            gradientAngleDeg: 135,
        }),
    )

    // 2. Headline across the top (or left column on banner).
    if (!isSmall) {
        const headBox = isBanner
            ? { x: 0.04, y: 0.18, w: 0.4, h: 0.3 }
            : { x: 0.08, y: 0.08, w: 0.84, h: isVertical ? 0.14 : 0.18 }
        blueprints.push({
            name: 'Headline',
            type: 'text',
            role: 'headline',
            xRatio: headBox.x,
            yRatio: headBox.y,
            widthRatio: headBox.w,
            heightRatio: headBox.h,
            defaults: {
                content: headline,
                fontSize: isBanner ? 64 : 72,
                fontWeight: 800,
                color: ink,
                textAlign: isBanner ? 'left' : 'center',
                lineHeight: 1.02,
            },
        })

        // Tagline — small tracked caps above or below headline.
        blueprints.push({
            name: 'Tagline',
            type: 'text',
            role: 'tagline',
            xRatio: headBox.x,
            yRatio: isBanner ? headBox.y + headBox.h + 0.02 : headBox.y + headBox.h + 0.01,
            widthRatio: headBox.w,
            heightRatio: 0.04,
            defaults: {
                content: tagline,
                fontSize: 16,
                fontWeight: 600,
                color: hexToRgba(ink, 0.8),
                letterSpacing: 4,
                textAlign: isBanner ? 'left' : 'center',
            },
        })
    }

    // 3. Lineup row geometry. Primary slot center; support slots flanking.
    //    We always emit a primary slot (even if empty) so the composition
    //    never ships with zero product slots.
    const lineupBox = isBanner
        ? { x: 0.46, y: 0.12, w: 0.52, h: 0.76 }
        : isVertical
            ? { x: 0.05, y: 0.28, w: 0.9, h: 0.42 }
            : { x: 0.05, y: 0.3, w: 0.9, h: 0.46 }

    // Slot layout: primary in the center (larger), supports equal-spaced
    // on either side at ~75% scale. Total slot count = min(5, 1 + support).
    const slotCount = Math.min(5, 1 + supportIds.length)
    const primaryW = lineupBox.w / (slotCount === 1 ? 2 : slotCount) * 1.3
    const supportW = lineupBox.w / (slotCount === 1 ? 2 : slotCount) * 0.85

    // Compute x positions. Center the primary; flank with supports.
    const centerX = lineupBox.x + lineupBox.w / 2
    const gap = 0.01
    const positions: Array<{ x: number; w: number; h: number; isPrimary: boolean }> = []

    if (slotCount === 1) {
        positions.push({ x: centerX - primaryW / 2, w: primaryW, h: lineupBox.h, isPrimary: true })
    } else {
        // Support count (slots minus primary). Split in half for symmetry.
        const supports = slotCount - 1
        const left = Math.floor(supports / 2)
        const right = supports - left
        // Build left cluster: support, support, ... , primary.
        let cursor = centerX - primaryW / 2
        for (let i = 0; i < left; i += 1) {
            cursor -= supportW + gap
        }
        for (let i = 0; i < left; i += 1) {
            positions.push({ x: cursor, w: supportW, h: lineupBox.h * 0.82, isPrimary: false })
            cursor += supportW + gap
        }
        positions.push({ x: cursor, w: primaryW, h: lineupBox.h, isPrimary: true })
        cursor += primaryW + gap
        for (let i = 0; i < right; i += 1) {
            positions.push({ x: cursor, w: supportW, h: lineupBox.h * 0.82, isPrimary: false })
            cursor += supportW + gap
        }
    }

    let supportCursor = 0
    positions.forEach((pos, idx) => {
        // Primary uses content.heroAssetId; supports consume supportIds in order.
        let assetId: string | undefined
        if (pos.isPrimary) {
            assetId = content.heroAssetId
        } else {
            assetId = supportIds[supportCursor]
            supportCursor += 1
        }
        const defaults: Record<string, unknown> = { fit: 'contain' }
        if (assetId) {
            defaults.assetId = assetId
            defaults.assetUrl = editorBridgeFileUrlForAssetId(assetId)
        }
        blueprints.push({
            name: pos.isPrimary ? 'Primary product' : `SKU ${idx + 1}`,
            type: 'image',
            role: 'hero_image',
            xRatio: pos.x,
            yRatio: lineupBox.y + (lineupBox.h - pos.h) / 2,
            widthRatio: pos.w,
            heightRatio: pos.h,
            defaults,
        })
    })

    // 4. Collection tag holding shape under the lineup.
    const collectionLabel = content.productVariant ?? content.body ?? 'Tap to explore'
    if (!isSmall) {
        const tagBox = isBanner
            ? { x: 0.04, y: 0.72, w: 0.4, h: 0.12 }
            : isVertical
                ? { x: 0.25, y: 0.76, w: 0.5, h: 0.07 }
                : { x: 0.3, y: 0.82, w: 0.4, h: 0.08 }
        blueprints.push(
            ...createHoldingShape({
                xRatio: tagBox.x,
                yRatio: tagBox.y,
                widthRatio: tagBox.w,
                heightRatio: tagBox.h,
                strokeColor: ink,
                strokePx: style.holdingShapeStrokePx,
                cornerRadius: style.holdingShapeCornerRadius,
                textLines: [{ content: collectionLabel, fontSize: 18, fontWeight: 600, color: ink }],
                groupKey: 'collection_tag',
            }),
        )
    }

    // 5. Optional CTA pill bottom-center.
    if (ctaLabel && !isSmall) {
        const cta = isBanner
            ? { x: 0.04, y: 0.88, w: 0.24, h: 0.08 }
            : isVertical
                ? { x: 0.3, y: 0.88, w: 0.4, h: 0.06 }
                : { x: 0.35, y: 0.92, w: 0.3, h: 0.06 }
        blueprints.push(
            ...createCtaPill({
                xRatio: cta.x,
                yRatio: cta.y,
                widthRatio: cta.w,
                heightRatio: cta.h,
                label: ctaLabel,
                style: style.ctaStyle === 'none' ? 'pill_filled' : style.ctaStyle,
                hue: ink,
                ink: hue,
            }),
        )
    }

    // 6. Corner watermark — never faded.
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: 'corner_only',
            corner: 'tr',
            cornerSize: 0.07,
        }),
    )

    return {
        blueprints,
        notes: [
            `Dominant hue: ${hue}`,
            `Lineup: ${slotCount} slot${slotCount === 1 ? '' : 's'} (1 primary + ${slotCount - 1} support)`,
            content.heroAssetId ? 'Primary: pre-filled' : 'Primary: empty',
            supportIds.length > 0 ? `Support SKUs: ${supportIds.length} asset id(s) provided` : 'Support SKUs: empty slots',
            ctaLabel ? `CTA: ${ctaLabel}` : 'CTA: skipped',
            'Watermark: corner-only (forced — lineup competes with faded watermark)',
        ],
    }
}

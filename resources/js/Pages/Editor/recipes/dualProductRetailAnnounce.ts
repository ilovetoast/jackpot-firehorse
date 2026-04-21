/**
 * Recipe: `dual_product_retail_announce`
 *
 * Retail announcement pattern — two hero products side by side (or stacked),
 * a bold "NOW AT" announcement headline, and a retailer logo strip across
 * the bottom. The kind of ad brands run when they land at Target, Whole
 * Foods, Best Buy, etc.
 *
 * Reads as "we're on the shelf now" — big, confident, with clear retailer
 * attribution.
 *
 * Recognizable features:
 *  - Toned brand-hue background with a horizontal or diagonal accent band
 *  - Announcement headline ("NOW AT TARGET", "AVAILABLE IN STORE")
 *  - Two hero product slots — primary + secondary, roughly equal weight
 *  - Retailer logo strip anchored at the bottom (sponsor row reused)
 *  - Small copy line between products ("& more" / "Plus new flavors")
 *  - Corner brand mark
 *
 * Aspect-aware:
 *  - Vertical: headline top, two products stacked center (P1 upper, P2 lower),
 *    retailer strip bottom
 *  - Square: headline top, two products side-by-side, retailer strip bottom
 *  - Banner: headline left, products right (P1 + P2 horizontally),
 *    retailer strip below headline
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createWatermarkPair,
    hexToRgba,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const dualProductRetailAnnounce: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)

    const announceLine1 = (content.ghostWord ?? 'NOW').toUpperCase()
    const announceLine2 = (content.filledWord ?? 'AT RETAIL').toUpperCase()
    const between = content.subline ?? '& more'
    const tagline = (content.tagline ?? 'LIMITED DROP').toUpperCase()

    const blueprints: LayerBlueprint[] = []

    // 1. Toned background.
    blueprints.push(createTonedBackground({ hue, mode: 'solid' }))

    // 2. Accent band — horizontal strip behind the products, slightly
    //    lighter than the base hue. Gives the composition a "shelf" feel.
    if (!isSmall) {
        const bandY = isBanner ? 0.2 : isVertical ? 0.22 : 0.25
        const bandH = isBanner ? 0.6 : isVertical ? 0.56 : 0.5
        blueprints.push({
            name: 'Accent band',
            type: 'fill',
            role: 'overlay',
            xRatio: 0,
            yRatio: bandY,
            widthRatio: 1,
            heightRatio: bandH,
            defaults: {
                fillKind: 'gradient',
                color: hexToRgba(ink, 0.08),
                gradientStartColor: hexToRgba(ink, 0.12),
                gradientEndColor: 'transparent',
                gradientAngleDeg: 180,
            },
        })
    }

    // 3. Announcement headline.
    if (!isSmall) {
        // Tagline above.
        const tagBox = isBanner
            ? { x: 0.04, y: 0.1, w: 0.42, h: 0.05 }
            : { x: 0.08, y: 0.06, w: 0.84, h: 0.05 }
        blueprints.push({
            name: 'Announce tagline',
            type: 'text',
            role: 'tagline',
            xRatio: tagBox.x,
            yRatio: tagBox.y,
            widthRatio: tagBox.w,
            heightRatio: tagBox.h,
            defaults: {
                content: tagline,
                fontSize: 16,
                fontWeight: 700,
                color: hexToRgba(ink, 0.8),
                letterSpacing: 4,
                textAlign: isBanner ? 'left' : 'center',
            },
        })

        // Headline pair — two lines, tightly leaded.
        const headBox = isBanner
            ? { x: 0.04, y: 0.18, w: 0.42, h: 0.34 }
            : { x: 0.08, y: 0.12, w: 0.84, h: isVertical ? 0.14 : 0.16 }
        const halfH = headBox.h / 2
        blueprints.push({
            name: 'Announce line 1',
            type: 'text',
            role: 'headline',
            xRatio: headBox.x,
            yRatio: headBox.y,
            widthRatio: headBox.w,
            heightRatio: halfH,
            defaults: {
                content: announceLine1,
                fontSize: isBanner ? 64 : 80,
                fontWeight: 900,
                color: ink,
                textAlign: isBanner ? 'left' : 'center',
                lineHeight: 0.95,
                letterSpacing: -1,
            },
            groupKey: 'announce',
        })
        blueprints.push({
            name: 'Announce line 2',
            type: 'text',
            role: 'headline',
            xRatio: headBox.x,
            yRatio: headBox.y + halfH,
            widthRatio: headBox.w,
            heightRatio: halfH,
            defaults: {
                content: announceLine2,
                fontSize: isBanner ? 64 : 80,
                fontWeight: 900,
                color: ink,
                textAlign: isBanner ? 'left' : 'center',
                lineHeight: 0.95,
                letterSpacing: -1,
            },
            groupKey: 'announce',
        })
    }

    // 4. Product pair. Geometry depends on format.
    type Box = { x: number; y: number; w: number; h: number }
    let primaryBox: Box
    let secondaryBox: Box
    let betweenBox: Box | null = null
    if (isBanner) {
        // Right column side-by-side.
        primaryBox = { x: 0.5, y: 0.14, w: 0.22, h: 0.72 }
        secondaryBox = { x: 0.74, y: 0.14, w: 0.22, h: 0.72 }
        betweenBox = { x: 0.5, y: 0.86, w: 0.46, h: 0.04 }
    } else if (isVertical) {
        // Stacked vertically.
        primaryBox = { x: 0.15, y: 0.28, w: 0.7, h: 0.3 }
        secondaryBox = { x: 0.15, y: 0.6, w: 0.7, h: 0.28 }
        betweenBox = { x: 0.3, y: 0.58, w: 0.4, h: 0.03 }
    } else {
        // Square — side-by-side.
        primaryBox = { x: 0.06, y: 0.32, w: 0.42, h: 0.48 }
        secondaryBox = { x: 0.52, y: 0.32, w: 0.42, h: 0.48 }
        betweenBox = { x: 0.3, y: 0.84, w: 0.4, h: 0.04 }
    }

    const buildProductSlot = (name: string, box: Box, assetId: string | undefined) => {
        const defaults: Record<string, unknown> = { fit: 'contain' }
        if (assetId) {
            defaults.assetId = assetId
            defaults.assetUrl = editorBridgeFileUrlForAssetId(assetId)
        }
        return {
            name,
            type: 'image' as const,
            role: 'hero_image' as const,
            xRatio: box.x,
            yRatio: box.y,
            widthRatio: box.w,
            heightRatio: box.h,
            defaults,
        }
    }
    blueprints.push(buildProductSlot('Primary product', primaryBox, content.heroAssetId))
    blueprints.push(buildProductSlot('Secondary product', secondaryBox, content.secondaryHeroAssetId))

    // "& more" line between / below the products.
    if (betweenBox && !isSmall) {
        blueprints.push({
            name: 'Between copy',
            type: 'text',
            role: 'body',
            xRatio: betweenBox.x,
            yRatio: betweenBox.y,
            widthRatio: betweenBox.w,
            heightRatio: betweenBox.h,
            defaults: {
                content: between,
                fontSize: isBanner ? 18 : 22,
                fontWeight: 600,
                color: hexToRgba(ink, 0.85),
                letterSpacing: 3,
                textAlign: 'center',
            },
        })
    }

    // 5. Retailer logo strip — reuse content.sponsorLogoAssetIds (semantic
    //    fit: "retailer" ≈ "sponsor" here). Fallback: brand logo only.
    const retailerIds = content.sponsorLogoAssetIds && content.sponsorLogoAssetIds.length > 0
        ? content.sponsorLogoAssetIds
        : style.primaryLogoAssetId
            ? [style.primaryLogoAssetId]
            : []
    if (retailerIds.length > 0 && !isSmall) {
        const rowH = 0.08
        const rowY = isBanner ? 0.12 : 0.9
        const rowW = isBanner ? 0.42 : 0.8
        const rowX = isBanner ? 0.04 : (1 - rowW) / 2
        const slotW = rowW / Math.min(retailerIds.length, 5)
        retailerIds.slice(0, 5).forEach((id, i) => {
            blueprints.push({
                name: `Retailer ${i + 1}`,
                type: 'image',
                role: 'logo',
                xRatio: rowX + i * slotW,
                yRatio: rowY,
                widthRatio: slotW * 0.88,
                heightRatio: rowH,
                defaults: {
                    fit: 'contain',
                    assetId: id,
                    assetUrl: editorBridgeFileUrlForAssetId(id),
                    opacity: 0.92,
                },
                groupKey: 'retailer_row',
            })
        })
    }

    // 6. Corner brand mark — opposite corner from the retailer strip.
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: 'corner_only',
            corner: isBanner ? 'br' : 'tr',
            cornerSize: 0.07,
        }),
    )

    return {
        blueprints,
        notes: [
            `Dominant hue: ${hue}`,
            `Announcement: "${announceLine1} / ${announceLine2}"`,
            content.heroAssetId ? 'Primary product: filled' : 'Primary product: empty',
            content.secondaryHeroAssetId ? 'Secondary product: filled' : 'Secondary product: empty',
            retailerIds.length > 0 ? `Retailer strip: ${retailerIds.length} logo${retailerIds.length === 1 ? '' : 's'}` : 'Retailer strip: skipped',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner (headline left, products right)' : isVertical ? 'vertical stacked' : 'square side-by-side'}`,
        ],
    }
}

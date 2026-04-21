/**
 * Recipe: `monochromatic_product_hero`
 *
 * Shefit "SUBLIME LIME" / "FEEL THE TEAL" pattern — a brand-hue dominant
 * background, a faded + corner watermark pair, a stacked ghost/filled
 * headline pair, a thin holding rectangle with the product name + variant,
 * and a hero product photo centered in the composition.
 *
 * Aspect-aware:
 *  - Vertical (story): headline at top, product centered, holding rect near bottom
 *  - Square (feed post): product centered, headline top, holding rect bottom
 *  - Banner: headline left, product right, holding rect bottom-left
 *
 * Intended to be the first recipe we ship — simplest content slots, highest
 * visual impact per unit of work, proves the recipe engine end-to-end.
 */

import type { Recipe } from './types'
import {
    createTonedBackground,
    createWatermarkPair,
    createGhostFilledHeadline,
    createHoldingShape,
    createTextBoost,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'
import type { LayerBlueprint } from '../templateConfig'

export const monochromaticProductHero: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2

    // Dominant hue: user override > product color (future) > brand primary.
    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)

    const ghostWord = content.ghostWord ?? 'MAKE IT'
    const filledWord = content.filledWord ?? 'POP'
    const productName = content.productName ?? 'Your Product'
    const productVariant = content.productVariant ?? (content.tagline ?? 'NEW EDITION')

    const blueprints: LayerBlueprint[] = []

    // 1. Toned brand-color background.
    blueprints.push(createTonedBackground({ hue, mode: 'solid' }))

    // 2. Watermark pair — faded center + corner mark (Shefit crown).
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: style.watermarkMode,
            opacity: style.watermarkOpacity,
            corner: 'tr',
            fadedSize: 0.6,
            cornerSize: 0.08,
        }),
    )

    // 3. Product hero photo slot. If an explicit heroAssetId is provided
    //    (wizard auto-pick or user-chosen), we seed it; otherwise an empty
    //    image slot shows the "Click to pick a photo" placeholder.
    let productX = 0.15
    let productY = 0.22
    let productW = 0.7
    let productH = 0.5
    if (isBanner) {
        productX = 0.5
        productY = 0.1
        productW = 0.45
        productH = 0.8
    } else if (isVertical) {
        productX = 0.15
        productY = 0.25
        productW = 0.7
        productH = 0.5
    }
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
            xRatio: productX,
            yRatio: productY,
            widthRatio: productW,
            heightRatio: productH,
            defaults,
        })
    }

    // 4. Ghost / filled headline pair. For banners we drop it to the left
    //    column; otherwise it sits at the top.
    const headlineX = isBanner ? 0.05 : 0.1
    const headlineY = isBanner ? 0.15 : 0.04
    const headlineW = isBanner ? 0.4 : 0.8
    const headlineH = isBanner ? 0.5 : 0.18
    blueprints.push(
        ...createGhostFilledHeadline({
            ghost: ghostWord,
            filled: filledWord,
            xRatio: headlineX,
            yRatio: headlineY,
            widthRatio: headlineW,
            heightRatio: headlineH,
            fillColor: ink,
            ghostOpacity: style.headlineGhostOpacity,
            fontSize: isBanner ? 72 : 96,
            fontWeight: 800,
            layout: 'stacked',
        }),
    )

    // 5. Holding shape with product name + variant line.
    const holdingX = isBanner ? 0.05 : 0.2
    const holdingY = isBanner ? 0.75 : 0.76
    const holdingW = isBanner ? 0.4 : 0.6
    const holdingH = isBanner ? 0.18 : 0.12
    blueprints.push(
        ...createHoldingShape({
            xRatio: holdingX,
            yRatio: holdingY,
            widthRatio: holdingW,
            heightRatio: holdingH,
            strokeColor: ink,
            cornerRadius: style.holdingShapeCornerRadius,
            textLines: [
                { content: productName, fontSize: 22, fontWeight: 600, color: ink },
                { content: productVariant, fontSize: 14, fontWeight: 500, color: ink },
            ],
        }),
    )

    // 6. Optional subtle text boost under the headline when we're over a photo
    //    (lifestyle variant). For the MVP we skip it since monochromatic
    //    product hero is over a solid BG — but if users swap to a photo BG,
    //    a text boost can be toggled from the properties panel.
    if (style.backgroundPreference === 'photo') {
        blueprints.push(createTextBoost({ hue: '#000000', opacity: 0.5, yRatio: 0.75, heightRatio: 0.25 }))
    }

    return {
        blueprints,
        notes: [
            `Dominant hue: ${hue}${content.dominantHueOverride ? ' (user override)' : ' (brand primary)'}`,
            `Ink: ${ink} (auto-picked for contrast)`,
            style.primaryLogoAssetId ? 'Watermark: logo pair (faded + corner)' : 'Watermark: skipped — brand has no logo asset',
            content.heroAssetId ? 'Hero: pre-filled from wizard pick' : 'Hero: empty slot — user will pick',
        ],
    }
}

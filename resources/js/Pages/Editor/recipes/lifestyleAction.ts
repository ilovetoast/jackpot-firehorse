/**
 * Recipe: `lifestyle_action`
 *
 * Event / action-sports pattern (Augusta, Ironman, concert poster). A full-
 * bleed lifestyle photo carries the emotion; an italic / heritage display
 * headline sits on a text-boost gradient; a date block + sponsor strip anchor
 * the bottom. Heavy on *feeling*, light on product detail — use for launch,
 * event announcement, sponsorship, or brand-moment work.
 *
 * Recognizable features:
 *  - Full-bleed action photo (athlete, event crowd, lifestyle shot)
 *  - Italic / script heritage headline, often stacked (two words)
 *  - Optional date block — big numeral under a short month label
 *  - Sponsor / partner logos in a tight row at the very bottom
 *  - Optional CTA pill, outlined — never overwhelms the photo
 *
 * Aspect-aware:
 *  - Vertical (story / 9:16): photo fills; headline top-left; date block
 *    center-left; sponsor strip anchored to bottom edge
 *  - Square (feed / 1:1): photo fills; headline top-left; date block bottom-
 *    right; sponsor strip above the bottom safety margin
 *  - Banner (wide / 3:1+): photo covers right 60%, headline stacked on the
 *    left column, date + sponsors below headline
 *
 * Content fallbacks mirror Augusta-style event ads so the empty state still
 * looks deliberate.
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createPhotoBackground,
    createTonedBackground,
    createTextBoost,
    createHoldingShape,
    createCtaPill,
} from './primitives'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const lifestyleAction: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    const blueprints: LayerBlueprint[] = []

    // 1. Background — lifestyle photo if provided; otherwise an empty image
    //    slot so user can drop one in, with a toned fallback if the slot
    //    stays empty at export time the renderer will show our "pick a photo"
    //    placeholder.
    if (content.heroAssetId) {
        blueprints.push(createPhotoBackground({ heroAssetId: content.heroAssetId }))
    } else if (style.backgroundPreference === 'photo') {
        blueprints.push(createPhotoBackground({}))
    } else {
        // Safe fallback: if the brand strongly prefers photo but none is
        // picked, still drop a toned BG so composition never exports empty.
        blueprints.push(
            createTonedBackground({ hue: style.primaryColor, mode: 'gradient_linear', gradientEndHue: '#000000', gradientAngleDeg: 180 }),
        )
    }

    // 2. Text boost — bottom-up for vertical / square (headline near top
    //    reads fine without a boost), and left-to-right for banner layouts.
    //    Heritage voice gets a warmer / darker boost; tech voice stays neutral.
    const boostHue = style.voiceTone === 'heritage' ? '#1a0f05' : '#000000'
    if (!isSmall) {
        if (isBanner) {
            blueprints.push({
                name: 'Text Boost',
                type: 'fill' as const,
                role: 'text_boost' as const,
                xRatio: 0,
                yRatio: 0,
                widthRatio: 0.55,
                heightRatio: 1,
                defaults: {
                    fillKind: 'gradient' as const,
                    color: boostHue,
                    gradientStartColor: boostHue + 'cc',
                    gradientEndColor: 'transparent',
                    gradientAngleDeg: 90,
                },
            })
        } else {
            blueprints.push(createTextBoost({ yRatio: 0.55, heightRatio: 0.45, direction: 'bottom_up', hue: boostHue, opacity: 0.72 }))
        }
    }

    // 3. Heritage italic headline — stacked (two-word layout like "Masters /
    //    Tournament" or "Opening / Night"). We do NOT use ghost+filled here
    //    because the voice for this recipe is heritage, not Shefit-bold.
    //    Italic is rendered via fontWeight + the display font; the Studio
    //    font system resolves heritage voices to serif italic.
    const lineOne = content.ghostWord ?? content.filledWord ?? 'Opening'
    const lineTwo = content.filledWord ?? content.productName ?? 'Night'
    const inkHeadline = '#ffffff'

    if (!isSmall) {
        const headlineBox = isBanner
            ? { x: 0.04, y: 0.12, w: 0.48, h: 0.5 }
            : isVertical
                ? { x: 0.06, y: 0.04, w: 0.88, h: 0.22 }
                : { x: 0.06, y: 0.06, w: 0.7, h: 0.28 }
        const halfH = headlineBox.h / 2
        // Line 1 (italic / lighter weight — heritage script feel)
        blueprints.push({
            name: 'Headline line 1',
            type: 'text',
            role: 'headline',
            xRatio: headlineBox.x,
            yRatio: headlineBox.y,
            widthRatio: headlineBox.w,
            heightRatio: halfH,
            defaults: {
                content: lineOne,
                fontSize: isBanner ? 56 : 72,
                fontWeight: 400,
                color: inkHeadline,
            },
            groupKey: 'headline_heritage',
        })
        // Line 2 (bolder to emphasize the event)
        blueprints.push({
            name: 'Headline line 2',
            type: 'text',
            role: 'headline',
            xRatio: headlineBox.x,
            yRatio: headlineBox.y + halfH,
            widthRatio: headlineBox.w,
            heightRatio: halfH,
            defaults: {
                content: lineTwo,
                fontSize: isBanner ? 72 : 96,
                fontWeight: 800,
                color: inkHeadline,
            },
            groupKey: 'headline_heritage',
        })
    }

    // 4. Date block(s) — one-to-three holding shapes with a month / numeral.
    //    Falls back to the current year's placeholder if `content.dates` is
    //    empty so the composition never ships with bare scaffolding.
    const dates = (content.dates && content.dates.length > 0)
        ? content.dates.slice(0, 3)
        : (isSmall ? [] : [{ label: 'APR', numeral: '20', detail: '2026' }])

    if (dates.length > 0) {
        const blockW = isBanner ? 0.14 : (isVertical ? 0.22 : 0.18)
        const blockH = isBanner ? 0.3 : (isVertical ? 0.12 : 0.15)
        const gap = 0.01
        const totalW = dates.length * blockW + (dates.length - 1) * gap
        const startX = isBanner ? 0.04 : (1 - totalW) / 2
        const yRatio = isBanner ? 0.65 : (isVertical ? 0.72 : 0.66)
        dates.forEach((d, i) => {
            const x = startX + i * (blockW + gap)
            // Holding shape with month / numeral stacked.
            blueprints.push(
                ...createHoldingShape({
                    xRatio: x,
                    yRatio,
                    widthRatio: blockW,
                    heightRatio: blockH,
                    strokeColor: '#ffffff',
                    strokePx: style.holdingShapeStrokePx,
                    cornerRadius: style.holdingShapeCornerRadius,
                    textLines: [
                        { content: d.label.toUpperCase(), fontSize: 14, fontWeight: 700, color: '#ffffff' },
                        { content: d.numeral, fontSize: 48, fontWeight: 700, color: '#ffffff' },
                        ...(d.detail ? [{ content: d.detail, fontSize: 12, fontWeight: 500, color: '#ffffff' }] : []),
                    ],
                    groupKey: `date_${i}`,
                }),
            )
        })
    }

    // 5. Sponsor / partner logos — anchored across the very bottom as a
    //    compact row. Falls back to the brand's primary logo if no sponsors
    //    were supplied (so there's at least a brand mark on the poster).
    const sponsorIds = (content.sponsorLogoAssetIds && content.sponsorLogoAssetIds.length > 0)
        ? content.sponsorLogoAssetIds
        : (style.primaryLogoAssetId ? [style.primaryLogoAssetId] : [])

    if (sponsorIds.length > 0 && !isSmall) {
        const rowH = isBanner ? 0.14 : 0.08
        const rowY = 1 - rowH - 0.03
        const rowW = 0.88
        const rowX = (1 - rowW) / 2
        const slotW = rowW / sponsorIds.length
        const logoMaxH = rowH * 0.9
        sponsorIds.slice(0, 6).forEach((id, i) => {
            blueprints.push({
                name: `Sponsor ${i + 1}`,
                type: 'image',
                role: 'logo',
                xRatio: rowX + i * slotW,
                yRatio: rowY + (rowH - logoMaxH) / 2,
                widthRatio: slotW * 0.85,
                heightRatio: logoMaxH,
                defaults: {
                    fit: 'contain',
                    assetId: id,
                    assetUrl: editorBridgeFileUrlForAssetId(id),
                    opacity: 0.95,
                },
                groupKey: 'sponsor_row',
            })
        })
    }

    // 6. Optional CTA pill — outlined on photo to keep the visual hierarchy
    //    weighted on the headline + date block. Skipped on small banners
    //    where every pixel is already fighting for space.
    const ctaLabel = content.cta
    if (ctaLabel && !isSmall && !isBanner) {
        blueprints.push(
            ...createCtaPill({
                xRatio: isVertical ? 0.3 : 0.38,
                yRatio: isVertical ? 0.86 : 0.86,
                widthRatio: isVertical ? 0.4 : 0.24,
                heightRatio: 0.06,
                label: ctaLabel,
                // Force outlined on this recipe — filled pills compete with
                // the photo. Users can override in the properties panel.
                style: 'pill_outlined',
                hue: '#ffffff',
                ink: '#ffffff',
            }),
        )
    }

    return {
        blueprints,
        notes: [
            content.heroAssetId ? 'Background: wizard lifestyle photo' : 'Background: empty slot — user will pick photography',
            `Headline: ${lineOne} / ${lineTwo} (heritage voice)`,
            dates.length > 0 ? `Dates: ${dates.length} block${dates.length === 1 ? '' : 's'}` : 'Dates: none',
            sponsorIds.length > 0
                ? `Sponsors: ${sponsorIds.length} logo${sponsorIds.length === 1 ? '' : 's'}${content.sponsorLogoAssetIds ? '' : ' (brand logo fallback)'}`
                : 'Sponsors: skipped (no logo assets)',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

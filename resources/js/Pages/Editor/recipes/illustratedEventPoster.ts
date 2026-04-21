/**
 * Recipe: `illustrated_event_poster`
 *
 * Vintage event-poster pattern (golf tournament, charity gala, concert
 * one-sheet). Where {@link lifestyleAction} uses a lifestyle photo, this
 * recipe treats the background as a paper / texture field and relies on
 * typography + holding shapes to carry the poster feel.
 *
 * Reads as "ticketed event" — heritage voice, centered type, big date block
 * front-and-center, sponsor row across the bottom. Intentionally formal.
 *
 * Recognizable features:
 *  - Paper or texture background (no photo)
 *  - Ghost-and-filled or script+caps headline (pulled from brand headline style)
 *  - Oversized date block centered in the composition
 *  - Holding shape around secondary detail ("Location / Time")
 *  - Sponsor / partner logo row at the bottom
 *  - Hairline ornamental border inset from the edge (signature heritage move)
 *
 * Aspect-aware:
 *  - Vertical (playbill): full-stack composition, type and dates centered
 *  - Square: same, compressed; sponsor row optional
 *  - Banner: two-column — type left, date block + details right
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createGhostFilledHeadline,
    createHoldingShape,
    hexToRgba,
} from './primitives'
import { inkOnColor, luminanceOf } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const illustratedEventPoster: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    // Paper vs brand-tint background. If brand primary is too dark for
    // legible black ink, we pull a warmer off-white; otherwise we tint
    // slightly toward the brand hue for a poster-y feel.
    const brandLum = luminanceOf(style.primaryColor)
    const paperHue = brandLum < 0.35 ? '#f3ead9' : hexToRgba(style.primaryColor, 0.1)
    const ink = inkOnColor(paperHue)

    const blueprints: LayerBlueprint[] = []

    // 1. Paper background.
    blueprints.push(createTonedBackground({ hue: paperHue, mode: 'solid' }))

    // 2. Ornamental hairline border inset 4% from every edge — the
    //    "engraved invitation" move. Emitted as a fill layer with a
    //    transparent interior + border-stroke, courtesy of the new
    //    borderStrokeWidth support on fill layers.
    if (!isSmall) {
        blueprints.push({
            name: 'Poster border',
            type: 'fill',
            role: 'overlay',
            xRatio: 0.04,
            yRatio: 0.04,
            widthRatio: 0.92,
            heightRatio: 0.92,
            defaults: {
                fillKind: 'solid',
                color: 'transparent',
                borderRadius: 2,
                borderStrokeWidth: 1.5,
                borderStrokeColor: hexToRgba(ink, 0.6),
            },
        })
    }

    // 3. Top tagline — tracked caps at the top.
    const topTag = (content.tagline ?? 'EST. 2026 · PRESENTED BY').toUpperCase()
    if (!isSmall) {
        const tagBox = isBanner
            ? { x: 0.06, y: 0.08, w: 0.44, h: 0.06 }
            : { x: 0.08, y: 0.08, w: 0.84, h: 0.05 }
        blueprints.push({
            name: 'Event tag',
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
                letterSpacing: 4,
                textAlign: isBanner ? 'left' : 'center',
            },
        })
    }

    // 4. Headline — ghost + filled pair for the event name. On banners we
    //    stack it on the left; everywhere else we center it at the top.
    const ghostWord = content.ghostWord ?? 'ANNUAL'
    const filledWord = content.filledWord ?? 'CLASSIC'
    if (!isSmall) {
        const headBox = isBanner
            ? { x: 0.06, y: 0.18, w: 0.44, h: 0.4 }
            : isVertical
                ? { x: 0.08, y: 0.16, w: 0.84, h: 0.22 }
                : { x: 0.08, y: 0.18, w: 0.84, h: 0.26 }
        blueprints.push(
            ...createGhostFilledHeadline({
                ghost: ghostWord,
                filled: filledWord,
                xRatio: headBox.x,
                yRatio: headBox.y,
                widthRatio: headBox.w,
                heightRatio: headBox.h,
                fillColor: ink,
                fontSize: isBanner ? 80 : 108,
                fontWeight: 900,
                layout: 'stacked',
                groupKey: 'event_headline',
            }),
        )
    }

    // 5. Oversized centered date — if content.dates is provided, we use its
    //    first block; otherwise a sensible placeholder so empty state looks
    //    deliberate ("OCT · 12 · 2026").
    const firstDate = content.dates?.[0] ?? { label: 'OCT', numeral: '12', detail: '2026' }
    if (!isSmall) {
        const dateBox = isBanner
            ? { x: 0.55, y: 0.18, w: 0.4, h: 0.45 }
            : isVertical
                ? { x: 0.2, y: 0.42, w: 0.6, h: 0.26 }
                : { x: 0.3, y: 0.46, w: 0.4, h: 0.3 }
        // Month label.
        blueprints.push({
            name: 'Date month',
            type: 'text',
            role: 'subheadline',
            xRatio: dateBox.x,
            yRatio: dateBox.y,
            widthRatio: dateBox.w,
            heightRatio: dateBox.h * 0.2,
            defaults: {
                content: firstDate.label.toUpperCase(),
                fontSize: isBanner ? 22 : 28,
                fontWeight: 700,
                color: ink,
                letterSpacing: 6,
                textAlign: 'center',
            },
            groupKey: 'event_date',
        })
        // Big numeral.
        blueprints.push({
            name: 'Date numeral',
            type: 'text',
            role: 'subheadline',
            xRatio: dateBox.x,
            yRatio: dateBox.y + dateBox.h * 0.2,
            widthRatio: dateBox.w,
            heightRatio: dateBox.h * 0.6,
            defaults: {
                content: firstDate.numeral,
                fontSize: isBanner ? 160 : 220,
                fontWeight: 900,
                color: ink,
                lineHeight: 1,
                textAlign: 'center',
            },
            groupKey: 'event_date',
        })
        if (firstDate.detail) {
            blueprints.push({
                name: 'Date detail',
                type: 'text',
                role: 'body',
                xRatio: dateBox.x,
                yRatio: dateBox.y + dateBox.h * 0.82,
                widthRatio: dateBox.w,
                heightRatio: dateBox.h * 0.15,
                defaults: {
                    content: firstDate.detail,
                    fontSize: isBanner ? 16 : 20,
                    fontWeight: 500,
                    color: hexToRgba(ink, 0.75),
                    letterSpacing: 3,
                    textAlign: 'center',
                },
                groupKey: 'event_date',
            })
        }
    }

    // 6. Location / time holding shape — heritage "venue line" under the
    //    date. Uses content.subline if provided; otherwise a placeholder.
    const venueText = content.subline ?? content.body ?? 'Location · Time'
    if (!isSmall) {
        const holdBox = isBanner
            ? { x: 0.55, y: 0.68, w: 0.4, h: 0.1 }
            : isVertical
                ? { x: 0.15, y: 0.74, w: 0.7, h: 0.08 }
                : { x: 0.2, y: 0.8, w: 0.6, h: 0.08 }
        blueprints.push(
            ...createHoldingShape({
                xRatio: holdBox.x,
                yRatio: holdBox.y,
                widthRatio: holdBox.w,
                heightRatio: holdBox.h,
                strokeColor: ink,
                strokePx: style.holdingShapeStrokePx,
                cornerRadius: style.holdingShapeCornerRadius,
                textLines: [{ content: venueText, fontSize: 20, fontWeight: 600, color: ink }],
                groupKey: 'venue_line',
            }),
        )
    }

    // 7. Sponsor / partner logo row across the bottom inside the border.
    const sponsorIds = content.sponsorLogoAssetIds && content.sponsorLogoAssetIds.length > 0
        ? content.sponsorLogoAssetIds
        : style.primaryLogoAssetId
            ? [style.primaryLogoAssetId]
            : []

    if (sponsorIds.length > 0 && !isSmall) {
        const rowH = 0.08
        const rowY = 0.88
        const rowW = 0.72
        const rowX = (1 - rowW) / 2
        const slotW = rowW / sponsorIds.length
        sponsorIds.slice(0, 5).forEach((id, i) => {
            blueprints.push({
                name: `Partner ${i + 1}`,
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
                    opacity: 0.9,
                },
                groupKey: 'sponsor_row',
            })
        })
    }

    return {
        blueprints,
        notes: [
            `Background: paper (${paperHue}) — heritage event poster`,
            `Headline: "${ghostWord} / ${filledWord}"`,
            `Date: ${firstDate.label} ${firstDate.numeral}${firstDate.detail ? ` ${firstDate.detail}` : ''}`,
            sponsorIds.length > 0 ? `Sponsors: ${sponsorIds.length}` : 'Sponsors: skipped (no assets)',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

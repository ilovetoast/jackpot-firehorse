/**
 * Recipe: `split_panel_copy_photo`
 *
 * Classic editorial split — the canvas is divided into two equal panels, one
 * a brand-hue solid and the other a full-bleed photo. The copy panel carries
 * a tagline + headline + optional CTA; the photo panel carries lifestyle or
 * product imagery.
 *
 * Reads as "catalog / lookbook ad" — intentional, balanced, easy to scan.
 * The split auto-flips based on aspect so the photo panel is always the
 * longer side (so the photo has room to breathe).
 *
 * Recognizable features:
 *  - 50/50 split between a solid brand-hue panel and a photo panel
 *  - Copy centered vertically in the solid panel (tagline + headline + CTA)
 *  - Photo fills the other panel, `cover` fit
 *  - Small corner brand mark inside the copy panel
 *  - No watermark overlay on the photo (would compete with the imagery)
 *
 * Aspect-aware:
 *  - Vertical: top half photo, bottom half copy (copy reads after the photo
 *    catches the eye on mobile feeds)
 *  - Banner / landscape: left half copy, right half photo
 *  - Square: left half photo, right half copy (match reading order)
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createCtaPill,
    hexToRgba,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const splitPanelCopyPhoto: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 1.8
    const isSmall = format.width < 360 || format.height < 220

    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)

    const tagline = (content.tagline ?? content.productVariant ?? 'NEW COLLECTION').toUpperCase()
    const headline = content.filledWord ?? content.productName ?? 'Made to last'
    const subline = content.subline ?? ''
    const ctaLabel = content.cta

    const blueprints: LayerBlueprint[] = []

    // Decide panel orientation. Vertical = photo top, copy bottom. Horizontal
    // (banner / square / landscape) = photo right, copy left (reading order).
    type Panel = { x: number; y: number; w: number; h: number }
    let copyPanel: Panel
    let photoPanel: Panel
    if (isVertical) {
        photoPanel = { x: 0, y: 0, w: 1, h: 0.55 }
        copyPanel = { x: 0, y: 0.55, w: 1, h: 0.45 }
    } else {
        // Horizontal.
        const copyW = isBanner ? 0.45 : 0.5
        copyPanel = { x: 0, y: 0, w: copyW, h: 1 }
        photoPanel = { x: copyW, y: 0, w: 1 - copyW, h: 1 }
    }

    // 1. Copy panel — solid fill in brand hue. A subtle gradient variant
    //    when the brand strongly prefers gradient BGs, otherwise solid.
    const copyPanelIsGradient = style.backgroundPreference === 'gradient_linear' || style.backgroundPreference === 'gradient_radial'
    blueprints.push({
        name: 'Copy panel',
        type: 'fill',
        role: 'background',
        xRatio: copyPanel.x,
        yRatio: copyPanel.y,
        widthRatio: copyPanel.w,
        heightRatio: copyPanel.h,
        defaults: copyPanelIsGradient
            ? {
                  fillKind: 'gradient',
                  color: hue,
                  gradientStartColor: hue,
                  gradientEndColor: hexToRgba(hue, 0.72),
                  gradientAngleDeg: isVertical ? 180 : 135,
              }
            : { fillKind: 'solid', color: hue },
    })

    // 2. Photo panel — empty image slot that the user can drop a photo
    //    into, or pre-filled with content.heroAssetId.
    {
        const defaults: Record<string, unknown> = { fit: 'cover' }
        if (content.heroAssetId) {
            defaults.assetId = content.heroAssetId
            defaults.assetUrl = editorBridgeFileUrlForAssetId(content.heroAssetId)
        }
        blueprints.push({
            name: 'Photo',
            type: 'image',
            role: 'hero_image',
            xRatio: photoPanel.x,
            yRatio: photoPanel.y,
            widthRatio: photoPanel.w,
            heightRatio: photoPanel.h,
            defaults,
        })
    }

    // 3. Copy content — tagline + headline + subline + CTA, vertically
    //    stacked inside the copy panel with generous padding.
    if (!isSmall) {
        const padX = 0.05
        const innerX = copyPanel.x + padX
        const innerW = copyPanel.w - padX * 2

        // Vertical center the block inside the copy panel.
        const blockH = 0.52 // fraction of copy panel height used by the copy block
        const blockStart = copyPanel.y + (copyPanel.h - copyPanel.h * blockH) / 2
        const step = (copyPanel.h * blockH) / 4

        blueprints.push({
            name: 'Tagline',
            type: 'text',
            role: 'tagline',
            xRatio: innerX,
            yRatio: blockStart,
            widthRatio: innerW,
            heightRatio: step * 0.6,
            defaults: {
                content: tagline,
                fontSize: 18,
                fontWeight: 700,
                color: ink,
                letterSpacing: 4,
                textAlign: 'left',
            },
        })

        blueprints.push({
            name: 'Headline',
            type: 'text',
            role: 'headline',
            xRatio: innerX,
            yRatio: blockStart + step * 0.7,
            widthRatio: innerW,
            heightRatio: step * 1.6,
            defaults: {
                content: headline,
                fontSize: isBanner ? 60 : 72,
                fontWeight: 800,
                color: ink,
                lineHeight: 1.02,
                textAlign: 'left',
            },
        })

        if (subline) {
            blueprints.push({
                name: 'Subline',
                type: 'text',
                role: 'body',
                xRatio: innerX,
                yRatio: blockStart + step * 2.5,
                widthRatio: innerW,
                heightRatio: step * 1.1,
                defaults: {
                    content: subline,
                    fontSize: 20,
                    fontWeight: 400,
                    color: hexToRgba(ink, 0.82),
                    lineHeight: 1.35,
                    textAlign: 'left',
                },
            })
        }

        if (ctaLabel) {
            blueprints.push(
                ...createCtaPill({
                    xRatio: innerX,
                    yRatio: blockStart + step * 3.6,
                    widthRatio: Math.min(0.35, innerW),
                    heightRatio: step * 0.8,
                    label: ctaLabel,
                    style: 'pill_filled',
                    hue: ink,
                    ink: hue,
                }),
            )
        }
    }

    // 4. Small corner brand mark in the top corner of the copy panel.
    if (style.primaryLogoAssetId && !isSmall) {
        const logoSize = 0.06
        const margin = 0.03
        // Top of copy panel — different corner depending on orientation.
        const markX = isVertical
            ? copyPanel.x + margin
            : copyPanel.x + margin
        const markY = isVertical
            ? copyPanel.y + margin
            : margin
        blueprints.push({
            name: 'Brand mark',
            type: 'image',
            role: 'logo',
            xRatio: markX,
            yRatio: markY,
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

    return {
        blueprints,
        notes: [
            `Split orientation: ${isVertical ? 'photo top / copy bottom' : 'copy left / photo right'}`,
            `Copy panel: ${copyPanelIsGradient ? 'gradient' : 'solid'} ${hue}`,
            content.heroAssetId ? 'Photo: pre-filled from wizard pick' : 'Photo: empty slot — user will pick',
            ctaLabel ? `CTA: filled pill "${ctaLabel}"` : 'CTA: skipped',
            style.primaryLogoAssetId ? 'Brand mark: corner of copy panel' : 'Brand mark: skipped (no logo asset)',
        ],
    }
}

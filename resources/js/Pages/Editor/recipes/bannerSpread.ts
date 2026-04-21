/**
 * Recipe: `banner_spread`
 *
 * Wide-format "banner" pattern (Google display, LinkedIn banner, web header).
 * Designed for formats where width >> height (3:1 through 10:1): stacked
 * copy on the left column, product hero on the right column, brand-hue
 * dominant background tying them together.
 *
 * Reads as "quick read" — someone scans it in half a second while scrolling,
 * so hierarchy is everything: headline loud, product big, CTA inline with
 * the headline. Watermark sits small in a corner; footer bars are omitted
 * because the banner *is* the footer in its usual placement.
 *
 * Recognizable features:
 *  - Solid brand-hue (or linear gradient) background
 *  - Stacked headline left (tagline + big word), inline CTA below
 *  - Product hero right-aligned, fitting the full banner height
 *  - Small corner brand mark (skip if logo missing)
 *  - NO footer bar — banners are meant to be hot-linked creative, not
 *    long-form ads with lockups
 *
 * Aspect-aware:
 *  - Super-wide (>4:1): copy left 40%, product right 55%, small margins
 *  - Moderate-wide (2–4:1): copy left 45%, product right 50%
 *  - Near-square fallback: stacks copy top, product bottom (so the recipe
 *    still produces something sane if misused on a square format)
 *
 * Recipe-level forced choices (by design):
 *  - CTA is always rendered inline next to the headline, filled pill
 *  - Watermark mode is pinned to `corner_only` regardless of brand setting
 *    because a faded-BG watermark competes with the product in a banner
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createWatermarkPair,
    createCtaPill,
    hexToRgba,
} from './primitives'
import { inkOnColor } from './brandAdStyle'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const bannerSpread: Recipe = ({ style, format, content }) => {
    const ratio = format.width / format.height
    const isSuperWide = ratio >= 4
    const isNearSquare = ratio < 1.6
    const isSmall = format.width < 300 || format.height < 80

    const hue = content.dominantHueOverride || style.primaryColor
    const ink = inkOnColor(hue)

    const headline = content.filledWord ?? content.productName ?? 'Your headline'
    const tagline = (content.tagline ?? content.ghostWord ?? 'NEW ARRIVAL').toUpperCase()
    const ctaLabel = content.cta ?? 'Shop now'

    const blueprints: LayerBlueprint[] = []

    // 1. Background — gradient-linear when brand prefers it, solid otherwise.
    //    A subtle gradient helps the composition feel less flat at banner
    //    aspect ratios where solid reads as "boxy".
    const bgMode: Parameters<typeof createTonedBackground>[0]['mode'] =
        style.backgroundPreference === 'gradient_linear' || style.backgroundPreference === 'gradient_radial'
            ? 'gradient_linear'
            : 'solid'
    blueprints.push(
        createTonedBackground({
            hue,
            mode: bgMode,
            gradientEndHue: hexToRgba(hue, 0.75),
            gradientAngleDeg: 90,
        }),
    )

    // 2. Corner watermark — never faded-BG for banners.
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: 'corner_only',
            corner: 'tr',
            cornerSize: isSuperWide ? 0.06 : 0.1,
        }),
    )

    // 3. Layout — copy column vs product column. For near-square formats
    //    we stack (copy on top, product below) so the recipe still produces
    //    something usable when it's applied to, say, an Instagram feed post.
    if (isNearSquare) {
        // Stacked fallback.
        const copyH = 0.42
        if (!isSmall) {
            blueprints.push({
                name: 'Tagline',
                type: 'text',
                role: 'tagline',
                xRatio: 0.08,
                yRatio: 0.08,
                widthRatio: 0.84,
                heightRatio: 0.07,
                defaults: {
                    content: tagline,
                    fontSize: 18,
                    fontWeight: 700,
                    color: ink,
                    letterSpacing: 3,
                    textAlign: 'left',
                },
            })
            blueprints.push({
                name: 'Headline',
                type: 'text',
                role: 'headline',
                xRatio: 0.08,
                yRatio: 0.17,
                widthRatio: 0.84,
                heightRatio: copyH - 0.12,
                defaults: {
                    content: headline,
                    fontSize: 72,
                    fontWeight: 800,
                    color: ink,
                    textAlign: 'left',
                    lineHeight: 1.05,
                },
            })
        }
        // Product lower half.
        const productDefaults: Record<string, unknown> = { fit: 'contain' }
        if (content.heroAssetId) {
            productDefaults.assetId = content.heroAssetId
            productDefaults.assetUrl = editorBridgeFileUrlForAssetId(content.heroAssetId)
        }
        blueprints.push({
            name: 'Product',
            type: 'image',
            role: 'hero_image',
            xRatio: 0.1,
            yRatio: copyH + 0.08,
            widthRatio: 0.8,
            heightRatio: 1 - copyH - 0.18,
            defaults: productDefaults,
        })
        if (ctaLabel && !isSmall) {
            blueprints.push(
                ...createCtaPill({
                    xRatio: 0.08,
                    yRatio: 0.9,
                    widthRatio: 0.4,
                    heightRatio: 0.08,
                    label: ctaLabel,
                    style: 'pill_filled',
                    hue: ink,
                    ink: hue,
                }),
            )
        }
    } else {
        // Standard banner layout: copy left, product right.
        const copyX = 0.04
        const copyW = isSuperWide ? 0.42 : 0.48
        const productX = copyX + copyW + 0.02
        const productW = 1 - productX - 0.04

        if (!isSmall) {
            // Tagline — small, tracked, above the headline.
            blueprints.push({
                name: 'Tagline',
                type: 'text',
                role: 'tagline',
                xRatio: copyX,
                yRatio: 0.12,
                widthRatio: copyW,
                heightRatio: 0.14,
                defaults: {
                    content: tagline,
                    fontSize: isSuperWide ? 18 : 22,
                    fontWeight: 700,
                    color: ink,
                    letterSpacing: 3,
                    textAlign: 'left',
                },
            })
            // Headline — dominant, left-aligned.
            blueprints.push({
                name: 'Headline',
                type: 'text',
                role: 'headline',
                xRatio: copyX,
                yRatio: 0.28,
                widthRatio: copyW,
                heightRatio: 0.4,
                defaults: {
                    content: headline,
                    fontSize: isSuperWide ? 56 : 72,
                    fontWeight: 800,
                    color: ink,
                    textAlign: 'left',
                    lineHeight: 1.02,
                },
                groupKey: 'banner_headline',
            })
            // Inline CTA — pill, sits below the headline.
            if (ctaLabel) {
                blueprints.push(
                    ...createCtaPill({
                        xRatio: copyX,
                        yRatio: 0.72,
                        widthRatio: isSuperWide ? 0.2 : 0.26,
                        heightRatio: 0.16,
                        label: ctaLabel,
                        style: 'pill_filled',
                        hue: ink,
                        ink: hue,
                    }),
                )
            }
        }

        // Product hero — right column, full height, contain fit.
        const productDefaults: Record<string, unknown> = { fit: 'contain' }
        if (content.heroAssetId) {
            productDefaults.assetId = content.heroAssetId
            productDefaults.assetUrl = editorBridgeFileUrlForAssetId(content.heroAssetId)
        }
        blueprints.push({
            name: 'Product',
            type: 'image',
            role: 'hero_image',
            xRatio: productX,
            yRatio: 0.08,
            widthRatio: productW,
            heightRatio: 0.84,
            defaults: productDefaults,
        })
    }

    return {
        blueprints,
        notes: [
            `Dominant hue: ${hue}`,
            `Layout: ${isNearSquare ? 'stacked (near-square fallback)' : isSuperWide ? 'super-wide banner' : 'moderate banner'}`,
            content.heroAssetId ? 'Product: pre-filled from wizard pick' : 'Product: empty slot — user will pick',
            ctaLabel && !isSmall ? `CTA: filled pill "${ctaLabel}"` : 'CTA: skipped',
            'Watermark: forced to corner-only (faded BG competes with product on banners)',
        ],
    }
}

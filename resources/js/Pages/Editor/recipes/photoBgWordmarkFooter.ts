/**
 * Recipe: `photo_bg_wordmark_footer`
 *
 * Lurvey "Spring Blooms — Explore Now" pattern — a full-bleed photography
 * background, a ghost/filled stacked display headline anchored at the top,
 * and a white footer bar holding the brand logo + wordmark + a CTA pill.
 *
 * This recipe is a deliberate choice for the MVP because:
 *  - It exercises every core primitive except the framed-showcase / diagram ones.
 *  - It's the natural seed for the Format Pack subsystem: one creative, many
 *    sizes. The reflow rules in `formatPack.ts` target this recipe first.
 *  - It cleanly separates background (photo) from the brand lockup (footer),
 *    making the visual grammar obvious to users.
 *
 * Aspect-aware:
 *  - Vertical: headline stacked vertically at the top; footer bar at the bottom
 *  - Square: headline stacked at the top; footer bar at the bottom
 *  - Banner: headline on the left column; footer strip along the bottom
 *  - Sub-250px-tall extremes: footer collapses to a logo-only lockup
 *    (handled by the format-pack reflow, not this recipe directly)
 */

import type { Recipe } from './types'
import {
    createPhotoBackground,
    createTonedBackground,
    createGhostFilledHeadline,
    createTextBoost,
    createFooterBar,
} from './primitives'
import { inkOnColor } from './brandAdStyle'

export const photoBgWordmarkFooter: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 400 || format.height < 250

    const blueprints = []

    // 1. Background — photo if available, otherwise brand-hue solid so the
    //    composition never shows an empty canvas.
    if (content.heroAssetId) {
        blueprints.push(createPhotoBackground({ heroAssetId: content.heroAssetId }))
    } else {
        // Empty photo slot so user can drop a photo in from the library.
        blueprints.push(createPhotoBackground({}))
    }

    // 2. Text boost so the white headline reads on a busy photo. Direction
    //    matches which side the headline sits — top for vertical / square,
    //    left for banner.
    if (!isSmall) {
        if (isBanner) {
            // Full-height gradient from left→fade so the copy reads.
            blueprints.push({
                name: 'Text Boost',
                type: 'fill' as const,
                role: 'text_boost' as const,
                xRatio: 0,
                yRatio: 0,
                widthRatio: 0.6,
                heightRatio: 1,
                defaults: {
                    fillKind: 'gradient' as const,
                    color: '#000000cc',
                    gradientStartColor: '#000000aa',
                    gradientEndColor: 'transparent',
                    gradientAngleDeg: 90,
                },
            })
        } else {
            blueprints.push(createTextBoost({ yRatio: 0, heightRatio: 0.45, direction: 'top_down', opacity: 0.55 }))
        }
    }

    // 3. Ghost/filled stacked headline. White ink on dark text-boost.
    const ghostWord = content.ghostWord ?? 'BUILT TO'
    const filledWord = content.filledWord ?? 'BLOOM'
    const inkForHeadline = '#ffffff'

    if (!isSmall) {
        if (isBanner) {
            blueprints.push(
                ...createGhostFilledHeadline({
                    ghost: ghostWord,
                    filled: filledWord,
                    xRatio: 0.05,
                    yRatio: 0.18,
                    widthRatio: 0.45,
                    heightRatio: 0.45,
                    fillColor: inkForHeadline,
                    ghostOpacity: 0.32,
                    fontSize: 72,
                    fontWeight: 800,
                    layout: 'stacked',
                }),
            )
        } else if (isVertical) {
            blueprints.push(
                ...createGhostFilledHeadline({
                    ghost: ghostWord,
                    filled: filledWord,
                    xRatio: 0.08,
                    yRatio: 0.04,
                    widthRatio: 0.84,
                    heightRatio: 0.22,
                    fillColor: inkForHeadline,
                    ghostOpacity: 0.32,
                    fontSize: 88,
                    fontWeight: 800,
                    layout: 'stacked',
                }),
            )
        } else {
            blueprints.push(
                ...createGhostFilledHeadline({
                    ghost: ghostWord,
                    filled: filledWord,
                    xRatio: 0.08,
                    yRatio: 0.05,
                    widthRatio: 0.84,
                    heightRatio: 0.26,
                    fillColor: inkForHeadline,
                    ghostOpacity: 0.32,
                    fontSize: 72,
                    fontWeight: 800,
                    layout: 'stacked',
                }),
            )
        }
    } else {
        // Tiny ad sizes (320×50 etc.): just put the filled word, inline with
        // the logo. The footer bar will carry it.
    }

    // 4. Footer bar — white bar with brand logo + wordmark + optional CTA pill.
    //    For tiny sizes we collapse to logo-only. For banners we lean on the
    //    footer strip being along the bottom regardless.
    const resolvedWordmark = content.productName ?? content.subline ?? undefined
    const ctaLabel = content.cta ?? 'Explore Now'
    const ctaHue = style.primaryColor

    // Small sizes: skip the footer bar fill (too chunky) — just put a tiny
    // corner logo. Everything else: full footer bar.
    if (isSmall) {
        if (style.primaryLogoAssetId) {
            blueprints.push({
                name: 'Corner logo',
                type: 'image' as const,
                role: 'logo' as const,
                xRatio: 0.02,
                yRatio: 0.7,
                widthRatio: 0.22,
                heightRatio: 0.28,
                defaults: {
                    fit: 'contain' as const,
                    assetId: style.primaryLogoAssetId,
                },
            })
        }
        if (ctaLabel) {
            blueprints.push({
                name: 'CTA',
                type: 'text' as const,
                role: 'cta' as const,
                xRatio: 0.45,
                yRatio: 0.7,
                widthRatio: 0.52,
                heightRatio: 0.28,
                defaults: {
                    content: ctaLabel,
                    fontSize: 16,
                    fontWeight: 700,
                    color: inkOnColor(ctaHue) === '#ffffff' ? '#ffffff' : ctaHue,
                },
            })
        }
    } else {
        blueprints.push(
            ...createFooterBar({
                style: style.footerStyle === 'none' ? 'white_bar' : style.footerStyle,
                logoAssetId: style.primaryLogoAssetId,
                wordmarkText: resolvedWordmark,
                barHeightRatio: isBanner ? 0.28 : 0.13,
                ctaLabel,
                ctaStyle: style.ctaStyle,
                ctaHue,
            }),
        )
    }

    return {
        blueprints,
        notes: [
            content.heroAssetId ? 'Background: wizard photo pick' : 'Background: empty slot — user will pick photography',
            style.primaryLogoAssetId ? 'Footer: brand logo + wordmark' : 'Footer: wordmark only (no logo asset)',
            `CTA: ${ctaLabel} (${style.ctaStyle})`,
            isSmall ? 'Size: compact layout (headline dropped, logo + CTA only)' : `Layout: ${isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

/**
 * Fallback composition when neither photography nor logo is available.
 * Returns a simple solid-hue card so the recipe never renders an empty canvas.
 * Currently unused — the main recipe already handles the empty-photo case —
 * but kept here for an eventual "safe mode" behavior if we detect a brand
 * with no assets at all.
 */
export function photoBgWordmarkFooterSafeMode(style: { primaryColor: string }): ReturnType<Recipe> {
    return {
        blueprints: [createTonedBackground({ hue: style.primaryColor, mode: 'solid' })],
        notes: ['Safe mode: brand has no photography or logo — rendering a solid hue card.'],
    }
}

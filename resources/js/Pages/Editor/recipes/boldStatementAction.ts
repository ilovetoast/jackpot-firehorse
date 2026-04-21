/**
 * Recipe: `bold_statement_action`
 *
 * "JUST DO IT" / "RUN THE DAY" pattern — a full-bleed action or lifestyle
 * photo with a single commanding statement stacked on a text-boost gradient.
 * The closest cousin to {@link lifestyleAction}, but tuned for *single-idea*
 * creative (manifesto, campaign tagline, rally cry) rather than event /
 * sponsor announce work.
 *
 * Where `lifestyle_action` uses a heritage italic headline + date block +
 * sponsor strip, this recipe goes the other way: one bold filled statement,
 * one short subline, one optional CTA, and a corner brand mark. Nothing
 * else.
 *
 * Recognizable features:
 *  - Full-bleed action / lifestyle photo (or gradient fallback)
 *  - Text-boost gradient heavy at the bottom / top-left edge
 *  - Single bold statement headline (two-word stacked OR one line)
 *  - Optional subline (short — "for the ones who don't quit.")
 *  - Corner brand mark, never a footer bar
 *  - Optional outlined CTA (filled competes with the photo)
 *
 * Aspect-aware:
 *  - Vertical: headline centered-lower, subline below, CTA at bottom
 *  - Square: headline bottom-left, subline beneath, CTA bottom-right
 *  - Banner: headline left column, subline + CTA stacked under
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createPhotoBackground,
    createTonedBackground,
    createTextBoost,
    createWatermarkPair,
    createCtaPill,
} from './primitives'

export const boldStatementAction: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    // Stacked vs single — prefer stacked (two words) when we have both
    // ghost + filled content slots, otherwise fall back to single-line.
    const hasPair = !!(content.ghostWord && content.filledWord)
    const lineOne = content.ghostWord ?? 'RUN'
    const lineTwo = content.filledWord ?? 'THE DAY'
    const single = content.filledWord ?? content.ghostWord ?? 'Just go.'
    const subline = content.subline ?? ''
    const ctaLabel = content.cta

    const blueprints: LayerBlueprint[] = []

    // 1. Background — action photo if present; otherwise dark gradient so
    //    the "statement on photo" visual grammar still reads even without
    //    photography. We intentionally skip the toned brand-hue fallback
    //    here because that would convert the recipe into something closer
    //    to `monochromatic_product_hero` on empty brands.
    if (content.heroAssetId) {
        blueprints.push(createPhotoBackground({ heroAssetId: content.heroAssetId }))
    } else {
        blueprints.push(
            createTonedBackground({
                hue: '#000000',
                mode: 'gradient_linear',
                gradientEndHue: style.primaryColor,
                gradientAngleDeg: 135,
            }),
        )
    }

    // 2. Text-boost gradient — heavy, anchored to where the statement will
    //    sit. Vertical / square: bottom-up; banner: left-to-right so the
    //    copy column is legible even against busy photography.
    if (!isSmall) {
        if (isBanner) {
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
                    color: '#000000',
                    gradientStartColor: 'rgba(0,0,0,0.82)',
                    gradientEndColor: 'transparent',
                    gradientAngleDeg: 90,
                },
            })
        } else {
            blueprints.push(createTextBoost({ yRatio: 0.45, heightRatio: 0.55, direction: 'bottom_up', hue: '#000000', opacity: 0.78 }))
        }
    }

    // 3. Statement headline. Two layouts:
    //    - Stacked (two lines, different weights) when we have a ghost/filled pair
    //    - Single line centered-bottom when we don't (still reads as a "statement")
    const ink = '#ffffff'
    if (!isSmall) {
        if (hasPair) {
            const box = isBanner
                ? { x: 0.04, y: 0.22, w: 0.55, h: 0.5 }
                : isVertical
                    ? { x: 0.06, y: 0.48, w: 0.88, h: 0.28 }
                    : { x: 0.06, y: 0.45, w: 0.7, h: 0.32 }
            const halfH = box.h / 2
            blueprints.push({
                name: 'Statement line 1',
                type: 'text',
                role: 'headline',
                xRatio: box.x,
                yRatio: box.y,
                widthRatio: box.w,
                heightRatio: halfH,
                defaults: {
                    content: lineOne.toUpperCase(),
                    fontSize: isBanner ? 88 : 112,
                    fontWeight: 800,
                    color: ink,
                    letterSpacing: -1,
                    lineHeight: 0.95,
                    textAlign: 'left',
                },
                groupKey: 'statement',
            })
            blueprints.push({
                name: 'Statement line 2',
                type: 'text',
                role: 'headline',
                xRatio: box.x,
                yRatio: box.y + halfH,
                widthRatio: box.w,
                heightRatio: halfH,
                defaults: {
                    content: lineTwo.toUpperCase(),
                    fontSize: isBanner ? 88 : 128,
                    fontWeight: 900,
                    color: ink,
                    letterSpacing: -1,
                    lineHeight: 0.95,
                    textAlign: 'left',
                },
                groupKey: 'statement',
            })
        } else {
            const box = isBanner
                ? { x: 0.04, y: 0.3, w: 0.55, h: 0.35 }
                : isVertical
                    ? { x: 0.06, y: 0.58, w: 0.88, h: 0.2 }
                    : { x: 0.06, y: 0.55, w: 0.7, h: 0.25 }
            blueprints.push({
                name: 'Statement',
                type: 'text',
                role: 'headline',
                xRatio: box.x,
                yRatio: box.y,
                widthRatio: box.w,
                heightRatio: box.h,
                defaults: {
                    content: single,
                    fontSize: isBanner ? 84 : 112,
                    fontWeight: 800,
                    color: ink,
                    letterSpacing: -1,
                    lineHeight: 1,
                    textAlign: 'left',
                },
            })
        }
    }

    // 4. Subline — short body under the statement.
    if (subline && !isSmall) {
        const subBox = isBanner
            ? { x: 0.04, y: 0.74, w: 0.55, h: 0.08 }
            : isVertical
                ? { x: 0.06, y: 0.78, w: 0.88, h: 0.06 }
                : { x: 0.06, y: 0.8, w: 0.7, h: 0.06 }
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
                fontSize: isBanner ? 20 : 22,
                fontWeight: 500,
                color: ink,
                letterSpacing: 1,
                textAlign: 'left',
                lineHeight: 1.3,
            },
        })
    }

    // 5. CTA — outlined on photo. Skipped on very small banners where the
    //    statement + photo already fill every pixel.
    if (ctaLabel && !isSmall) {
        const cta = isBanner
            ? { x: 0.04, y: 0.84, w: 0.2, h: 0.1 }
            : isVertical
                ? { x: 0.3, y: 0.88, w: 0.4, h: 0.06 }
                : { x: 0.6, y: 0.85, w: 0.32, h: 0.08 }
        blueprints.push(
            ...createCtaPill({
                xRatio: cta.x,
                yRatio: cta.y,
                widthRatio: cta.w,
                heightRatio: cta.h,
                label: ctaLabel,
                // Force outlined — filled pill competes with the photo.
                style: 'pill_outlined',
                hue: '#ffffff',
                ink: '#ffffff',
            }),
        )
    }

    // 6. Corner brand mark.
    blueprints.push(
        ...createWatermarkPair({
            logoAssetId: style.primaryLogoAssetId,
            mode: 'corner_only',
            corner: 'tr',
            cornerSize: 0.08,
        }),
    )

    return {
        blueprints,
        notes: [
            content.heroAssetId ? 'Background: action photo' : 'Background: dark gradient fallback',
            hasPair ? `Statement: stacked "${lineOne} / ${lineTwo}"` : `Statement: single line "${single}"`,
            subline ? 'Subline present' : 'Subline skipped',
            ctaLabel ? 'CTA: outlined pill (forced — filled competes with photo)' : 'CTA: skipped',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

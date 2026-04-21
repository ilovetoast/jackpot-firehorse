/**
 * Recipe: `bold_display_tech`
 *
 * Tech-reveal / product-hero-on-dark pattern (Lurvey "unique product reveal",
 * premium DTC electronics, spec-forward product ads). A near-black gradient
 * background carries a glow treatment behind a centered product photo; a
 * large, thin-weight display headline anchors the top; optional feature
 * bullets run along the bottom edge like a spec strip; a small brand mark
 * sits in a corner so it never competes with the product.
 *
 * Recognizable features:
 *  - Near-black background with a radial / diagonal glow in the brand hue
 *  - Product hero photo centered with a wider-than-tall glow halo
 *  - Large, thin-weight display headline ("INTRODUCING…", "NEW.", etc.)
 *    with a secondary sub-headline line
 *  - Optional spec strip: 2-4 short feature bullets across the bottom
 *  - Small corner brand mark (top-right by default)
 *  - CTA pill — filled in brand color when present; outlined fallback
 *
 * Aspect-aware:
 *  - Vertical (9:16): headline top; product center; spec strip bottom
 *  - Square (1:1): headline top-left; product center; spec strip bottom
 *  - Banner (wide): headline + specs left; product right
 *
 * This recipe is the natural counterpart to `monochromatic_product_hero`
 * — that one is brand-hue-dominant, this one is ink-dominant. They share
 * the same primitives so swapping between them is a one-click change.
 */

import type { Recipe } from './types'
import type { LayerBlueprint } from '../templateConfig'
import {
    createTonedBackground,
    createCtaPill,
    hexToRgba,
} from './primitives'
import { editorBridgeFileUrlForAssetId } from '../documentModel'

export const boldDisplayTech: Recipe = ({ style, format, content }) => {
    const isVertical = format.height > format.width * 1.3
    const isBanner = format.width > format.height * 2
    const isSmall = format.width < 360 || format.height < 220

    // Dark canvas — near-black with a subtle brand-hue tint so the hue still
    // feels present without competing with the product. `inkOnColor` isn't
    // useful here because we always use white text on ink.
    const tintedDark = hexToRgba(style.primaryColor, 0.12)

    const blueprints: LayerBlueprint[] = []

    // 1. Background — true-black base.
    blueprints.push(createTonedBackground({ mode: 'black', hue: '#000000' }))

    // 2. Glow — a diagonal gradient sitting behind the product. The gradient
    //    fades from a near-full brand hue at the upper-left to transparent,
    //    giving a "light hitting the product" feel without needing a real
    //    radial renderer. Keeping it as its own layer makes it easy for
    //    users to tweak or disable.
    if (!isSmall) {
        const glow = isBanner
            ? { x: 0.35, y: 0.05, w: 0.6, h: 0.9 }
            : isVertical
                ? { x: 0.1, y: 0.2, w: 0.8, h: 0.55 }
                : { x: 0.12, y: 0.15, w: 0.76, h: 0.65 }
        blueprints.push({
            name: 'Glow',
            type: 'fill',
            role: 'overlay',
            xRatio: glow.x,
            yRatio: glow.y,
            widthRatio: glow.w,
            heightRatio: glow.h,
            defaults: {
                fillKind: 'gradient',
                color: tintedDark,
                gradientStartColor: hexToRgba(style.primaryColor, 0.55),
                gradientEndColor: 'transparent',
                // Diagonal from top-left → bottom-right feels like stage light
                gradientAngleDeg: 135,
            },
        })
    }

    // 3. Product hero — center. If no hero asset, empty image slot shows
    //    the "Click to pick a photo" placeholder.
    const product = isBanner
        ? { x: 0.5, y: 0.1, w: 0.48, h: 0.8 }
        : isVertical
            ? { x: 0.12, y: 0.3, w: 0.76, h: 0.4 }
            : { x: 0.15, y: 0.22, w: 0.7, h: 0.55 }
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
            xRatio: product.x,
            yRatio: product.y,
            widthRatio: product.w,
            heightRatio: product.h,
            defaults,
        })
    }

    // 4. Display headline — thin-weight, large, top-anchored. Uses a single
    //    headline line with an optional subline below. We deliberately do
    //    not use the ghost/filled pair here — tech voice reads cleaner with
    //    a single confident line.
    const headline = content.filledWord ?? content.ghostWord ?? content.productName ?? 'Introducing'
    const subline = content.subline ?? content.tagline ?? ''

    if (!isSmall) {
        const headlineBox = isBanner
            ? { x: 0.04, y: 0.12, w: 0.44, h: 0.34 }
            : isVertical
                ? { x: 0.06, y: 0.04, w: 0.88, h: 0.22 }
                : { x: 0.06, y: 0.04, w: 0.88, h: 0.18 }
        blueprints.push({
            name: 'Display headline',
            type: 'text',
            role: 'headline',
            xRatio: headlineBox.x,
            yRatio: headlineBox.y,
            widthRatio: headlineBox.w,
            heightRatio: subline ? headlineBox.h * 0.62 : headlineBox.h,
            defaults: {
                content: headline,
                fontSize: isBanner ? 72 : 96,
                // Thin weight — the signature tech look. Users can override.
                fontWeight: 200,
                color: '#ffffff',
                letterSpacing: -1.5,
            },
        })
        if (subline) {
            blueprints.push({
                name: 'Subline',
                type: 'text',
                role: 'subheadline',
                xRatio: headlineBox.x,
                yRatio: headlineBox.y + headlineBox.h * 0.66,
                widthRatio: headlineBox.w,
                heightRatio: headlineBox.h * 0.3,
                defaults: {
                    content: subline,
                    fontSize: 22,
                    fontWeight: 400,
                    color: hexToRgba('#ffffff', 0.7),
                },
            })
        }
    }

    // 5. Feature bullets — optional spec strip across the bottom. Falls back
    //    to derived bullets from `body` if `featureList` wasn't supplied.
    //    Banner layout puts specs under the headline on the left column
    //    instead of the bottom so they don't overlap the product.
    const features: string[] = (content.featureList && content.featureList.length > 0)
        ? content.featureList.slice(0, 4)
        : (content.body ? content.body.split(/\s*(?:\||·|—)\s*/).slice(0, 4) : [])

    if (features.length > 0 && !isSmall) {
        const stripY = isBanner ? 0.5 : (isVertical ? 0.78 : 0.82)
        const stripH = isBanner ? 0.28 : 0.08
        const stripX = isBanner ? 0.04 : 0.06
        const stripW = isBanner ? 0.44 : 0.88
        const slotW = stripW / features.length
        features.forEach((feat, i) => {
            const x = isBanner ? stripX : stripX + i * slotW
            const y = isBanner ? stripY + (i * (stripH / features.length)) : stripY
            const w = isBanner ? stripW : slotW
            const h = isBanner ? stripH / features.length : stripH
            // A thin gradient under the bullet gives it the "spec chip" feel
            // without a hard border.
            blueprints.push({
                name: `Feature ${i + 1}`,
                type: 'text',
                role: 'body',
                xRatio: x,
                yRatio: y,
                widthRatio: w,
                heightRatio: h,
                defaults: {
                    content: feat,
                    fontSize: 14,
                    fontWeight: 500,
                    color: hexToRgba('#ffffff', 0.85),
                    letterSpacing: 1,
                    textAlign: isBanner ? 'left' : 'center',
                },
                groupKey: 'feature_strip',
            })
        })
    }

    // 6. Corner brand mark — small, top-right by default. Non-essential;
    //    skipped when the brand has no logo or on very small ad sizes.
    if (style.primaryLogoAssetId && !isSmall) {
        const logoSize = isBanner ? 0.1 : 0.08
        const margin = 0.03
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
                // Slight dim so the logo doesn't compete with the headline
                opacity: 0.9,
            },
        })
    }

    // 7. CTA pill — filled in brand hue for bold voice; outlined when the
    //    brand voice is minimal / technical (matches tech-product ads that
    //    prefer "Learn more" over "Buy now").
    const ctaLabel = content.cta
    if (ctaLabel && !isSmall) {
        const ctaStyleForRecipe = style.voiceTone === 'minimal' || style.voiceTone === 'technical'
            ? 'pill_outlined'
            : style.ctaStyle
        const ctaBox = isBanner
            ? { x: 0.04, y: 0.82, w: 0.22, h: 0.1 }
            : isVertical
                ? { x: 0.3, y: 0.88, w: 0.4, h: 0.06 }
                : { x: 0.38, y: 0.9, w: 0.24, h: 0.07 }
        blueprints.push(
            ...createCtaPill({
                xRatio: ctaBox.x,
                yRatio: ctaBox.y,
                widthRatio: ctaBox.w,
                heightRatio: ctaBox.h,
                label: ctaLabel,
                style: ctaStyleForRecipe,
                hue: style.primaryColor,
                ink: '#ffffff',
            }),
        )
    }

    return {
        blueprints,
        notes: [
            'Background: near-black with brand-hue glow',
            content.heroAssetId ? 'Product: pre-filled from wizard pick' : 'Product: empty slot — user will pick',
            `Headline: ${headline} (thin display, tech voice)`,
            subline ? `Subline: ${subline}` : 'Subline: skipped',
            features.length > 0 ? `Features: ${features.length} bullet${features.length === 1 ? '' : 's'}` : 'Features: skipped',
            style.primaryLogoAssetId ? 'Brand mark: corner' : 'Brand mark: skipped — no logo asset',
            `Layout: ${isSmall ? 'compact' : isBanner ? 'banner' : isVertical ? 'vertical' : 'square'}`,
        ],
    }
}

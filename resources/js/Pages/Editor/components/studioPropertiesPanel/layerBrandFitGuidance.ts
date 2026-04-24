/**
 * Lightweight, non-blocking brand-fit hints for the Studio Properties panel.
 * Uses only cheap client-side signals — no extra AI round-trips.
 */
import type { BrandContext, CopyScore, Layer, TextLayer } from '../../documentModel'
import { estimateBrandScore, isGenerativeImageLayer, isImageLayer, isTextLayer, labeledBrandPalette } from '../../documentModel'

export type BrandFitTone = 'aligned' | 'improve' | 'review' | 'unknown'

export type LayerBrandFitGuidance = {
    tone: BrandFitTone
    headline: string
    detail?: string
    suggestions: string[]
}

function normalizeHex(hex: string): string {
    const s = hex.trim().toLowerCase()
    if (s.startsWith('#') && s.length === 4 && /^#[0-9a-f]{4}$/.test(s)) {
        return `#${s[1]}${s[1]}${s[2]}${s[2]}${s[3]}${s[3]}`
    }
    return s
}

function colorsNear(a: string, b: string): boolean {
    return normalizeHex(a) === normalizeHex(b)
}

function mapScoreToTone(score: number): BrandFitTone {
    if (score >= 72) return 'aligned'
    if (score >= 52) return 'improve'
    return 'review'
}

function headlineFor(tone: BrandFitTone): string {
    switch (tone) {
        case 'aligned':
            return 'Brand fit: Looks aligned'
        case 'improve':
            return 'Brand fit: Could improve'
        case 'review':
            return 'Brand fit: Needs review'
        default:
            return 'Brand fit: Not enough evidence yet'
    }
}

export function getLayerBrandFitGuidance(
    layer: Layer,
    brand: BrandContext | null | undefined,
    opts?: {
        /** When Copy Assist last returned a score — optional instant signal for text. */
        copyAssistScore?: CopyScore | null
    }
): LayerBrandFitGuidance {
    const suggestions: string[] = []
    if (!brand) {
        return {
            tone: 'unknown',
            headline: headlineFor('unknown'),
            detail: 'Add brand colors or logo for better hints.',
            suggestions,
        }
    }

    if (isGenerativeImageLayer(layer)) {
        const est = estimateBrandScore(layer.prompt, brand)
        const tone = mapScoreToTone(est.score)
        if (est.feedback.length) {
            suggestions.push(...est.feedback.slice(0, 2))
        }
        if (tone === 'review' && suggestions.length === 0) {
            suggestions.push('Try mentioning brand tone or palette in your scene.')
        }
        return {
            tone,
            headline: headlineFor(tone),
            detail: 'Heuristic preview only.',
            suggestions: suggestions.slice(0, 3),
        }
    }

    if (isTextLayer(layer)) {
        const cs = opts?.copyAssistScore
        if (cs) {
            const tone = mapScoreToTone(cs.score)
            return {
                tone,
                headline: headlineFor(tone),
                detail: 'From last Copy Assist check.',
                suggestions: cs.feedback.slice(0, 2),
            }
        }
        const labeled = labeledBrandPalette(brand)
        const ink = (layer as TextLayer).style.color
        const hit = labeled.some(({ color }) => colorsNear(color, ink))
        if (hit) {
            return {
                tone: 'aligned',
                headline: headlineFor('aligned'),
                detail: 'Text color matches a brand swatch.',
                suggestions: [],
            }
        }
        return {
            tone: 'improve',
            headline: headlineFor('improve'),
            detail: 'Try a brand preset or swatch for color.',
            suggestions: [],
        }
    }

    if (isImageLayer(layer) && layer.assetId) {
        return {
            tone: 'aligned',
            headline: headlineFor('aligned'),
            detail: 'Library image — keep replacements on-brand.',
            suggestions: [],
        }
    }

    return {
        tone: 'unknown',
        headline: headlineFor('unknown'),
        detail: 'Add brand colors or logo for better hints.',
        suggestions,
    }
}

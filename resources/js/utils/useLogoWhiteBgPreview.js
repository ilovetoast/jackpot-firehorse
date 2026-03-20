import { useState, useEffect } from 'react'
import { analyzeLogoLightOnWhiteRisk } from './imageUtils'

/**
 * Pick the best raster to show on white/light guideline cards and detect when the primary
 * logo has large light areas (e.g. white text) that disappear on white.
 *
 * @param {string|null} primarySrc - Primary logo URL
 * @param {string|null} onLightVariantSrc - Optional logo_on_light asset URL (always preferred for light previews)
 */
export default function useLogoWhiteBgPreview(primarySrc, onLightVariantSrc) {
    const [analysis, setAnalysis] = useState(null)

    useEffect(() => {
        if (!primarySrc) {
            setAnalysis({ ok: true, skipped: true })
            return
        }
        if (onLightVariantSrc) {
            setAnalysis({ ok: true, usingOnLightVariant: true })
            return
        }
        let cancelled = false
        setAnalysis(null)
        analyzeLogoLightOnWhiteRisk(primarySrc)
            .then((r) => {
                if (!cancelled) setAnalysis(r)
            })
            .catch(() => {
                if (!cancelled) setAnalysis({ ok: true, skipped: true, reason: 'error' })
            })
        return () => {
            cancelled = true
        }
    }, [primarySrc, onLightVariantSrc])

    const whiteBgSrc = onLightVariantSrc || primarySrc
    const showRiskBanner =
        Boolean(primarySrc) && !onLightVariantSrc && analysis && analysis.ok === false && !analysis.skipped
    const outlineWhiteBg = showRiskBanner
    const loadingAnalysis = Boolean(primarySrc) && !onLightVariantSrc && analysis === null

    return {
        whiteBgSrc,
        showRiskBanner,
        outlineWhiteBg,
        loadingAnalysis,
        analysis,
    }
}

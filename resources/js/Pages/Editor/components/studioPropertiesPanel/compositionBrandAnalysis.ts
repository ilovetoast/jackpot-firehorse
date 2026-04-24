import type { BrandContext, DocumentModel } from '../../documentModel'

export type CompositionBrandAnalysisLevel = 'strong' | 'review' | 'concerns'

export type CompositionBrandAnalysisResult = {
    level: CompositionBrandAnalysisLevel
    summary: string
    suggestions: string[]
    /** Reserved for future server-backed analysis job ids. */
    jobId?: string
}

/**
 * Hook point for full-composition brand analysis before publish/export.
 * Stub: returns soft guidance without blocking callers.
 */
export async function analyzeCompositionForBrandBeforeExport(
    _document: DocumentModel,
    _brand: BrandContext | null | undefined,
    _opts?: { signal?: AbortSignal }
): Promise<CompositionBrandAnalysisResult> {
    return {
        level: 'review',
        summary:
            'Composition-level brand alignment is evaluated before you publish (logos, palette, and headline treatments).',
        suggestions: [
            'Use approved brand assets for logos and primary copy.',
            'Confirm colors in the preview match your brand palette.',
        ],
    }
}

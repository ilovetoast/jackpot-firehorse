import type { DocumentModel } from '../../../Pages/Editor/documentModel'
import { parseDocumentFromApi } from '../../../Pages/Editor/documentModel'
import type { CompositionRenderPayloadV1 } from '../../../Pages/StudioExport/compositionRenderContract'

/**
 * Convert signed export payload → editor {@link DocumentModel} for shared {@link CompositionScene}.
 * Uses the same normalization path as API-loaded compositions.
 */
export function documentFromRenderPayloadV1(p: CompositionRenderPayloadV1): DocumentModel {
    return parseDocumentFromApi({
        id: `export-${p.composition_id}`,
        width: p.width,
        height: p.height,
        layers: p.layers as unknown[],
        studio_timeline: { duration_ms: p.duration_ms },
    })
}

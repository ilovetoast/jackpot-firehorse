function csrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export type LayerExtractionCandidateDto = {
    id: string
    label?: string | null
    confidence?: number | null
    bbox: { x: number; y: number; width: number; height: number }
    selected?: boolean
    notes?: string | null
    preview_url?: string | null
    /** Provider-specific (e.g. local_floodfill, area_ratio). */
    metadata?: Record<string, unknown> | null
}

export type LayerExtractionProviderCapabilities = {
    supports_multiple_masks: boolean
    supports_background_fill: boolean
    supports_labels: boolean
    supports_confidence: boolean
    supports_point_pick?: boolean
    supports_point_refine?: boolean
    supports_box_pick?: boolean
    /** True when Fal (or a future Replicate) remote driver is actually configured. */
    uses_ai_segmentation?: boolean
}

export type ExtractionMethodOption = {
    key: 'local' | 'ai'
    label: string
    description?: string
    billable: boolean
    available: boolean
    unavailable_reason?: string | null
    credit_key?: string
    estimated_credits?: number
    estimated_provider_cost_usd?: number | null
    provider_cost_source?: string
}

/** Set on 502 and failed-session payloads when `code === 'local_source_too_large'`. */
export type LocalSourceTooLargePayload = {
    code: 'local_source_too_large'
    method: 'local'
    can_try_ai: boolean
    ai_available: boolean
    ai_unavailable_reason: string | null
}

export type PostExtractLayersError = Error & Partial<LocalSourceTooLargePayload>

export type PostExtractLayersResponse =
    | {
          status: 'ready'
          extraction_session_id: string
          queued: false
          candidates: LayerExtractionCandidateDto[]
          extraction_method?: 'local' | 'ai'
          default_extraction_method?: 'local' | 'ai'
          available_methods?: ExtractionMethodOption[]
          provider_capabilities?: LayerExtractionProviderCapabilities
      }
    | {
          status: 'pending'
          extraction_session_id: string
          queued: true
          extraction_method?: 'local' | 'ai'
          default_extraction_method?: 'local' | 'ai'
          available_methods?: ExtractionMethodOption[]
          provider_capabilities?: LayerExtractionProviderCapabilities
      }

export type LayerExtractionSessionResponse = {
    status: string
    extraction_session_id: string
    error_message?: string | null
    candidates?: LayerExtractionCandidateDto[]
    extraction_method?: 'local' | 'ai' | null
    default_extraction_method?: 'local' | 'ai'
    available_methods?: ExtractionMethodOption[]
    provider_capabilities?: LayerExtractionProviderCapabilities
} & Partial<LocalSourceTooLargePayload>

export type LayerExtractionPickResponse = {
    status: string
    extraction_session_id: string
    candidates: LayerExtractionCandidateDto[]
    new_candidate: LayerExtractionCandidateDto | null
    warning: string | null
    provider_capabilities?: LayerExtractionProviderCapabilities
}

export type LayerExtractionRefineResponse = {
    status: string
    extraction_session_id: string
    candidates: LayerExtractionCandidateDto[]
    updated_candidate: LayerExtractionCandidateDto | null
    warning: string | null
    provider_capabilities?: LayerExtractionProviderCapabilities
}

export type LayerExtractionBoxResponse = {
    status: string
    extraction_session_id: string
    candidates: LayerExtractionCandidateDto[]
    new_candidate: LayerExtractionCandidateDto | null
    warning: string | null
    provider_capabilities?: LayerExtractionProviderCapabilities
}

function formatApiError(data: unknown, fallback: string): string {
    if (!data || typeof data !== 'object') {
        return fallback
    }
    const o = data as { message?: string }
    return typeof o.message === 'string' && o.message.trim() !== '' ? o.message : fallback
}

export type ExtractLayerOptionsResponse = {
    default_extraction_method: 'local' | 'ai'
    available_methods: ExtractionMethodOption[]
}

export async function fetchExtractLayerOptions(
    compositionId: string,
    layerId: string
): Promise<ExtractLayerOptionsResponse> {
    const res = await fetch(
        `/app/studio/documents/${encodeURIComponent(compositionId)}/layers/${encodeURIComponent(layerId)}/extract-layers/options`,
        {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Options failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Options failed (${res.status})`))
    }
    return data as ExtractLayerOptionsResponse
}

export async function postExtractLayers(
    compositionId: string,
    layerId: string,
    body: { method?: 'local' | 'ai' } = {}
): Promise<PostExtractLayersResponse> {
    const res = await fetch(
        `/app/studio/documents/${encodeURIComponent(compositionId)}/layers/${encodeURIComponent(layerId)}/extract-layers`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Extract layers failed')
    }
    if (!res.ok) {
        const o = (data && typeof data === 'object' ? (data as Record<string, unknown>) : {}) as Record<string, unknown>
        const e = new Error(
            formatApiError(data, text || `Extract layers failed (${res.status})`)
        ) as PostExtractLayersError
        if (o.code === 'local_source_too_large') {
            e.code = 'local_source_too_large'
            e.method = o.method as 'local'
            e.can_try_ai = o.can_try_ai === true
            e.ai_available = o.ai_available === true
            e.ai_unavailable_reason = typeof o.ai_unavailable_reason === 'string' ? o.ai_unavailable_reason : null
        }
        throw e
    }
    return data as PostExtractLayersResponse
}

export async function fetchLayerExtractionSession(sessionId: string): Promise<LayerExtractionSessionResponse> {
    const res = await fetch(`/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Session fetch failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Session fetch failed (${res.status})`))
    }
    return data as LayerExtractionSessionResponse
}

export async function postConfirmExtractLayers(
    compositionId: string,
    layerId: string,
    body: {
        extraction_session_id: string
        candidate_ids?: string[]
        selected_candidate_ids?: string[]
        keep_original_visible: boolean
        create_filled_background?: boolean
        hide_original_after_extraction?: boolean
        layer_names?: (string | null)[] | Record<string, string | null>
    }
): Promise<{ document: Record<string, unknown>; new_layer_ids?: string[] }> {
    const res = await fetch(
        `/app/studio/documents/${encodeURIComponent(compositionId)}/layers/${encodeURIComponent(layerId)}/extract-layers/confirm`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Confirm extraction failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Confirm extraction failed (${res.status})`))
    }
    return data as { document: Record<string, unknown>; new_layer_ids?: string[] }
}

export async function postLayerExtractionPick(
    sessionId: string,
    body: { x: number; y: number }
): Promise<LayerExtractionPickResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/pick`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Pick failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Pick failed (${res.status})`))
    }
    return data as LayerExtractionPickResponse
}

export async function deleteLayerExtractionCandidate(
    sessionId: string,
    candidateId: string
): Promise<LayerExtractionSessionResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/candidates/${encodeURIComponent(candidateId)}`,
        {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Remove candidate failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Remove failed (${res.status})`))
    }
    return data as LayerExtractionSessionResponse
}

/** One of `negative_point` (exclude) or `positive_point` (include more area). */
export type LayerExtractionRefineRequestBody =
    | { negative_point: { x: number; y: number }; positive_point?: never }
    | { positive_point: { x: number; y: number }; negative_point?: never }

export async function postLayerExtractionRefine(
    sessionId: string,
    candidateId: string,
    body: LayerExtractionRefineRequestBody
): Promise<LayerExtractionRefineResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/candidates/${encodeURIComponent(
            candidateId
        )}/refine`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Refine failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Refine failed (${res.status})`))
    }
    return data as LayerExtractionRefineResponse
}

export async function postLayerExtractionResetRefine(
    sessionId: string,
    candidateId: string
): Promise<LayerExtractionRefineResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/candidates/${encodeURIComponent(
            candidateId
        )}/reset-refine`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Reset refine failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Reset refine failed (${res.status})`))
    }
    return data as LayerExtractionRefineResponse
}

export async function postLayerExtractionBox(
    sessionId: string,
    body: { box: { x: number; y: number; width: number; height: number }; mode: 'object' | 'text_graphic' }
): Promise<LayerExtractionBoxResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/box`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Box pick failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Box pick failed (${res.status})`))
    }
    return data as LayerExtractionBoxResponse
}

export async function postClearLayerExtractionPicks(sessionId: string): Promise<LayerExtractionSessionResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/clear-picks`,
        {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Clear picks failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Clear failed (${res.status})`))
    }
    return data as LayerExtractionSessionResponse
}

export async function postClearLayerExtractionManualCandidates(
    sessionId: string
): Promise<LayerExtractionSessionResponse> {
    const res = await fetch(
        `/app/studio/layer-extraction-sessions/${encodeURIComponent(sessionId)}/clear-manual-candidates`,
        {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        }
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Clear manual failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Clear failed (${res.status})`))
    }
    return data as LayerExtractionSessionResponse
}

import type { BrandContext } from './documentModel'

export type EditImagePayload = {
    /** Prefer when the layer is a DAM asset — server loads the original file (never a thumbnail WebP). */
    assetId?: string
    /** Required when assetId is omitted (e.g. pasted or generated image without a library id). */
    imageUrl?: string
    instruction: string
    /** Config ai.models registry key (e.g. gpt-image-1, gemini-2.5-flash-image). */
    modelKey?: string
    brandContext?: BrandContext | null
    compositionId?: string
    assetId?: string
    brandId?: number
}

export type EditImageResponse = {
    image_url: string
    message?: string
}

export async function editImage(
    payload: EditImagePayload,
    options?: { signal?: AbortSignal }
): Promise<EditImageResponse> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const ctrl = new AbortController()
    const outerSignal = options?.signal
    if (outerSignal) {
        if (outerSignal.aborted) {
            throw new DOMException('Aborted', 'AbortError')
        }
        outerSignal.addEventListener('abort', () => ctrl.abort(), { once: true })
    }
    const timeout = window.setTimeout(() => ctrl.abort(), 120_000)
    try {
        const hasAsset = payload.assetId != null && String(payload.assetId).trim() !== ''
        if (!hasAsset && (payload.imageUrl == null || String(payload.imageUrl).trim() === '')) {
            throw new Error('editImage requires assetId or imageUrl')
        }
        const body: Record<string, unknown> = {
            instruction: payload.instruction,
        }
        if (hasAsset) {
            body.asset_id = payload.assetId
        } else {
            body.image_url = payload.imageUrl
        }
        if (payload.brandContext != null) {
            body.brand_context = payload.brandContext
        }
        if (payload.compositionId != null) {
            body.composition_id = Number(payload.compositionId)
        }
        if (payload.brandId != null) {
            body.brand_id = payload.brandId
        }
        if (payload.modelKey != null && payload.modelKey !== '') {
            body.model_key = payload.modelKey
        }

        const res = await fetch('/app/api/edit-image', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: ctrl.signal,
        })
        const text = await res.text()
        let data: unknown
        try {
            data = JSON.parse(text)
        } catch {
            throw new Error(text || 'Edit failed')
        }
        if (!res.ok) {
            const msg = (data as { message?: string })?.message || text
            throw new Error(msg)
        }
        return data as EditImageResponse
    } finally {
        window.clearTimeout(timeout)
    }
}

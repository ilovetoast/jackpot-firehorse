import type { BrandContext, CopyScore, EditorVisualContext } from './documentModel'

export type GenerateCopyOperation =
    | 'generate'
    | 'improve'
    | 'shorten'
    | 'premium'
    | 'align_tone'

export type CopySuggestionVariant = {
    label: string
    text: string
}

export type GenerateCopyPayload = {
    input?: string
    intent: 'headline' | 'body' | 'caption'
    operation: GenerateCopyOperation
    brand_context?: ReturnType<typeof serializeBrandForCopy>
    visual_context?: EditorVisualContext
    tone_override?: string
    /** Text layer frame width (px) — line-length awareness. */
    text_box_width?: number
    /** Persisted composition id when the canvas is saved — attributes the agent run for per-comp spend. */
    composition_id?: string
}

export type GenerateCopyResponse = {
    text: string
    /** Diverse alternates (bold / minimal / emotional). */
    suggestions: CopySuggestionVariant[]
    copy_score: CopyScore
}

/** Trim for transport; server also enforces max length. */
const MAX_INPUT = 8000

export function serializeBrandForCopy(brand: BrandContext | null | undefined) {
    if (!brand) {
        return undefined
    }
    const voice = [brand.visual_style, brand.archetype].filter(Boolean).join(', ')
    return {
        tone: brand.tone,
        archetype: brand.archetype,
        visual_style: brand.visual_style,
        voice: voice || undefined,
    }
}

export async function postGenerateCopy(
    payload: GenerateCopyPayload,
    signal?: AbortSignal
): Promise<GenerateCopyResponse> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const body = {
        ...payload,
        input: payload.input ? payload.input.slice(0, MAX_INPUT) : undefined,
    }
    const res = await fetch('/app/api/generate-copy', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        signal,
    })
    if (!res.ok) {
        const err = (await res.json().catch(() => ({}))) as { message?: string }
        throw new Error(err.message ?? `Copy generation failed (${res.status})`)
    }
    const data = (await res.json()) as GenerateCopyResponse & {
        suggestions?: Array<string | CopySuggestionVariant>
    }
    const normalized = normalizeSuggestionsResponse(data)
    return { ...data, suggestions: normalized }
}

/** Backward compat: string[] → labeled variants. */
function normalizeSuggestionsResponse(data: {
    text: string
    suggestions?: Array<string | CopySuggestionVariant>
    copy_score: CopyScore
}): CopySuggestionVariant[] {
    const raw = data.suggestions ?? []
    const labels = ['Bold / punchy', 'Minimal / clean', 'Emotional / narrative']
    return raw
        .map((item, i) => {
            if (typeof item === 'string') {
                return { label: labels[i] ?? `Option ${i + 1}`, text: item }
            }
            return {
                label: item.label || labels[i] || `Option ${i + 1}`,
                text: item.text,
            }
        })
        .filter((s) => s.text.trim() !== '')
}

import type { BrandContext, GenerativePrompt } from './documentModel'

export type GenerateImageUsage = {
    remaining: number
    limit: number
    /** Plan slug: free, starter, pro, premium, enterprise */
    plan: string
    /** Human-readable plan name from config (e.g. "Premium") */
    plan_name?: string
}

/** Resolved provider + model — never tie UI labels to raw API ids in call sites. */
export type ResolvedModelConfig = {
    provider: string
    model: string
}

export const GENERATIVE_UI_MODEL_VALUES = ['default', 'high_quality', 'fast'] as const
export type GenerativeUiModelKey = (typeof GENERATIVE_UI_MODEL_VALUES)[number]

export const MODEL_MAP: Record<GenerativeUiModelKey, ResolvedModelConfig> = {
    default: { provider: 'openai', model: 'gpt-image-1' },
    high_quality: { provider: 'gemini', model: 'gemini-3-pro-image-preview' },
    fast: { provider: 'gemini', model: 'gemini-2.5-flash-image' },
}

export function resolveModelConfig(uiKey: string | undefined): ResolvedModelConfig {
    const k = (uiKey ?? 'default') as GenerativeUiModelKey
    return MODEL_MAP[k] ?? MODEL_MAP.default
}

export type GenerateImagePayload = {
    /** Structured JSON (source of truth in the editor). */
    prompt: GenerativePrompt
    /** Flattened string for providers that expect a single prompt (see buildPromptString). */
    prompt_string: string
    negative_prompt: string[]
    /** Which UI preset was used (analytics / logging). */
    model_key: GenerativeUiModelKey
    /** Explicit provider routing — not the raw dropdown label. */
    model: ResolvedModelConfig
    size: string
    /** Brand DNA snapshot for logging / future conditioning (optional). */
    brand_context?: BrandContext | null
    /** DAM asset ids for reference-based generation (optional). */
    references?: string[]
}

export type GenerateImageResponse = {
    image_url: string
}

export const GENERATIVE_MODEL_OPTIONS: ReadonlyArray<{ label: string; value: GenerativeUiModelKey }> = [
    { label: 'Default', value: 'default' },
    { label: 'High Quality', value: 'high_quality' },
    { label: 'Fast', value: 'fast' },
]

export function canGenerateFromUsage(usage: GenerateImageUsage | null): boolean {
    if (!usage) {
        return false
    }
    if (usage.limit < 0) {
        return true
    }
    return usage.remaining > 0
}

export async function fetchGenerateImageUsage(): Promise<GenerateImageUsage> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const res = await fetch('/app/api/generate-image/usage', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        throw new Error(`Usage unavailable (${res.status})`)
    }
    return res.json()
}

export async function generateEditorImage(
    payload: GenerateImagePayload,
    options?: { signal?: AbortSignal }
): Promise<GenerateImageResponse> {
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
        const res = await fetch('/app/api/generate-image', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
            signal: ctrl.signal,
        })
        const text = await res.text()
        let data: unknown
        try {
            data = JSON.parse(text)
        } catch {
            throw new Error(text || 'Generation failed')
        }
        if (!res.ok) {
            const msg = (data as { message?: string })?.message || text
            throw new Error(msg)
        }
        return data as GenerateImageResponse
    } finally {
        window.clearTimeout(timeout)
    }
}

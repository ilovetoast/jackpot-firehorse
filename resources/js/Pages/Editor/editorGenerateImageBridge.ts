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
    /** Server registry key override (advanced); must match allowlist in config. */
    model_override?: string
    /** Saved composition id (bigint from API, sent as string) for attribution (optional). */
    composition_id?: string
    /** DAM asset UUID when editing in asset context (optional). */
    asset_id?: string
    /** Active brand id for attribution (optional). */
    brand_id?: number
}

export type GenerateImageResponse = {
    image_url: string
    /** Present when server persisted to DAM (stable /app/api/assets/{id}/file URL). */
    asset_id?: string
    resolved_model_key?: string
    model_display_name?: string
    agent_run_id?: number
}

export const GENERATIVE_MODEL_OPTIONS: ReadonlyArray<{ label: string; value: GenerativeUiModelKey }> = [
    { label: 'Default', value: 'default' },
    { label: 'High Quality', value: 'high_quality' },
    { label: 'Fast', value: 'fast' },
]

/**
 * Registry keys aligned with `config/ai.php` generative_editor.allowlist — advanced override only.
 *
 * Google markets Gemini native image generation as “Nano Banana” (AI Studio / docs); API model ids are
 * still `gemini-*-flash-image` / `gemini-*-pro-image-preview`. Labels show both so users recognize
 * Google’s UI vs our registry keys.
 */
export const GENERATIVE_ADVANCED_MODEL_OPTIONS: ReadonlyArray<{ label: string; value: string }> = [
    { label: 'GPT Image 1 (OpenAI)', value: 'gpt-image-1' },
    { label: 'Nano Banana Pro · Gemini 3 Pro (preview)', value: 'gemini-3-pro-image-preview' },
    { label: 'Nano Banana 2 · Gemini 3.1 Flash (preview)', value: 'gemini-3.1-flash-image-preview' },
    { label: 'Nano Banana · Gemini 2.5 Flash Image', value: 'gemini-2.5-flash-image' },
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

/** Retired Nano Banana id — maps to {@link MODEL_MAP.fast} API id (see config/ai.php). */
const LEGACY_GEMINI_FLASH_IMAGE = 'gemini-1.5-flash-image'
const CURRENT_GEMINI_FLASH_IMAGE = 'gemini-2.5-flash-image'

/** Align with server {@see GenerativeEditorModelNormalizer} so saved editor state still generates. */
export function normalizeGenerativeModelPayload(payload: GenerateImagePayload): GenerateImagePayload {
    let model = payload.model
    if (model.provider === 'gemini' && model.model === LEGACY_GEMINI_FLASH_IMAGE) {
        model = { ...model, model: CURRENT_GEMINI_FLASH_IMAGE }
    }
    let model_override = payload.model_override
    if (model_override === LEGACY_GEMINI_FLASH_IMAGE) {
        model_override = CURRENT_GEMINI_FLASH_IMAGE
    }
    if (model === payload.model && model_override === payload.model_override) {
        return payload
    }
    return { ...payload, model, model_override }
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
        const body = normalizeGenerativeModelPayload(payload)
        const res = await fetch('/app/api/generate-image', {
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

/**
 * Shared reliability helpers for the generative editor (no new architecture).
 */

/** Max concurrent in-flight AI requests (image + copy) across the editor. */
export const MAX_CONCURRENT_AI_REQUESTS = 3

let aiActive = 0
const aiWaitQueue: Array<() => void> = []

function acquireAIConcurrencySlot(): Promise<void> {
    return new Promise((resolve) => {
        if (aiActive < MAX_CONCURRENT_AI_REQUESTS) {
            aiActive++
            resolve()
        } else {
            aiWaitQueue.push(() => {
                aiActive++
                resolve()
            })
        }
    })
}

function releaseAIConcurrencySlot(): void {
    aiActive--
    const next = aiWaitQueue.shift()
    if (next) {
        next()
    }
}

/**
 * Limits parallel AI calls; excess work waits in a FIFO queue (no throw on saturation).
 */
export async function withAIConcurrency<T>(fn: () => Promise<T>): Promise<T> {
    await acquireAIConcurrencySlot()
    try {
        return await fn()
    } finally {
        releaseAIConcurrencySlot()
    }
}

/** Normalize any thrown value into a user-safe string for inline UI. */
export function handleAIError(error: unknown): string {
    if (error instanceof DOMException && error.name === 'AbortError') {
        return 'Request cancelled.'
    }
    if (error instanceof Error && error.message) {
        const m = error.message
        // Keep specific copy for long-running studio video export polling / queue issues.
        if (/video export timed out|video export is still queued|timed out while reading video export status/i.test(m)) {
            return m
        }
        if (/abort|timed?\s*out|timeout/i.test(m)) {
            return 'Request timed out or was cancelled. Please try again.'
        }
        return m
    }
    return 'Something went wrong. Please try again.'
}

/**
 * Lightweight analytics hook — no UI. Optional global handler for product analytics.
 */
export function trackEvent(
    name: 'generate_image' | 'generate_copy' | 'save_composition',
    payload?: Record<string, unknown>
): void {
    if (typeof window === 'undefined') {
        return
    }
    const w = window as unknown as {
        jackpotEditorTrack?: (event: string, data?: Record<string, unknown>) => void
    }
    if (typeof w.jackpotEditorTrack === 'function') {
        w.jackpotEditorTrack(name, payload)
    }
}

/**
 * Pass to html-to-image `toPng` / `toJpeg` so inlined fetches to same-origin asset URLs
 * (e.g. `/app/api/assets/{id}/file`) send session cookies. Without this, raster export can
 * fail with an image `error` event / SecurityError while the on-screen img still loads.
 */
export const editorHtmlToImageFetchRequestInit: RequestInit = {
    credentials: 'include',
}

/**
 * Wait for images inside a root (e.g. stage) to load or fail before raster export.
 * Reduces blank or partial PNGs when assets are still loading.
 */
export function waitForImagesToLoad(root: HTMLElement, timeoutMs = 12_000): Promise<void> {
    const imgs = Array.from(root.querySelectorAll('img'))
    const pending = imgs.filter((img) => !img.complete)
    if (pending.length === 0) {
        return Promise.resolve()
    }
    const each = (img: HTMLImageElement) =>
        new Promise<void>((resolve) => {
            img.addEventListener('load', () => resolve(), { once: true })
            img.addEventListener('error', () => resolve(), { once: true })
        })
    return Promise.race([
        Promise.all(pending.map(each)).then(() => undefined),
        new Promise<void>((resolve) => {
            window.setTimeout(resolve, timeoutMs)
        }),
    ])
}

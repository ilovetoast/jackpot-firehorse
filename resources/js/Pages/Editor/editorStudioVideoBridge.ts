/**
 * Compositions: insert a video layer (DAM asset) and export a baked MP4.
 * Legacy FFmpeg export composites image / generative_image overlays only; text and related effects use canvas runtime when enabled.
 */

/** Single status GET can block behind PHP-FPM / DB load; keep well above client poll interval. */
const VIDEO_EXPORT_STATUS_FETCH_TIMEOUT_MS = 300_000

/** Max wall time for publish UI to poll (align with `STUDIO_VIDEO_EXPORT_JOB_TIMEOUT_SECONDS` + buffer). */
const VIDEO_EXPORT_POLL_WALL_CLOCK_MS = 14_400_000

function csrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

/** Avoid hung tabs when a status poll never completes (stalled connection, proxy, or server). */
async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number): Promise<Response> {
    const controller = new AbortController()
    const tid = window.setTimeout(() => controller.abort(), timeoutMs)
    try {
        return await fetch(url, { ...init, signal: controller.signal })
    } finally {
        window.clearTimeout(tid)
    }
}

function formatApiError(data: unknown, fallback: string): string {
    if (!data || typeof data !== 'object') {
        return fallback
    }
    const o = data as { message?: string; errors?: Record<string, string[] | string> }
    if (o.errors && typeof o.errors === 'object') {
        const parts: string[] = []
        for (const [key, val] of Object.entries(o.errors)) {
            const msgs = Array.isArray(val) ? val : [String(val)]
            for (const m of msgs) {
                parts.push(`${key}: ${m}`)
            }
        }
        if (parts.length > 0) {
            return parts.join(' ')
        }
    }
    return typeof o.message === 'string' && o.message.trim() !== '' ? o.message : fallback
}

export type PostStoreVideoLayerBody = {
    asset_id: string
    file_url: string
    name?: string
    start_ms?: number
    end_ms?: number
    muted?: boolean
    /** Default `add` — new layer. `replace_layer` swaps an existing image/gen/video in place. */
    insert_mode?: 'add' | 'replace_layer'
    replace_layer_id?: string
    /** `back` = video layer behind existing layers (default for AI clips). `front` = on top. Ignored when replacing. */
    stacking?: 'front' | 'back'
    /** Stored on the new layer as `studioProvenance` in the document JSON. */
    provenance?: Record<string, string | number | boolean | null | undefined>
}

export type PostStoreVideoLayerResponse = {
    composition_id: string
    document_json: unknown
    new_layer_id: string
}

export async function postStoreVideoLayer(
    compositionId: string,
    body: PostStoreVideoLayerBody
): Promise<PostStoreVideoLayerResponse> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(compositionId)}/studio/video-layer`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    })
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Add video layer failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Add video layer failed (${res.status})`))
    }
    return data as PostStoreVideoLayerResponse
}

export type PostVideoExportResponse = { id: string; status: string; render_mode?: string }

export type PostRequestVideoExportBody = {
    /** `legacy_bitmap` = current FFmpeg-only overlays. `canvas_runtime` = headless browser scene (feature-flagged). */
    render_mode?: 'legacy_bitmap' | 'canvas_runtime'
    include_audio?: boolean
    editor_publish?: {
        name?: string
        description?: string
        category_id?: number
        field_metadata?: Record<string, unknown>
        collection_ids?: number[]
        editor_provenance?: Record<string, unknown>
    }
}

export async function postRequestVideoExport(
    compositionId: string,
    body: PostRequestVideoExportBody = {}
): Promise<PostVideoExportResponse> {
    const res = await fetchWithTimeout(
        `/app/api/compositions/${encodeURIComponent(compositionId)}/studio/video-export`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body ?? {}),
        },
        300_000,
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Video export request failed')
    }
    if (!res.ok) {
        throw new Error(formatApiError(data, text || `Video export request failed (${res.status})`))
    }
    const parsed = data as { id?: unknown; status?: unknown; render_mode?: unknown }
    const id = parsed.id != null && String(parsed.id).trim() !== '' ? String(parsed.id) : ''
    if (!id) {
        throw new Error('Video export response did not include a job id.')
    }
    return {
        id,
        status: typeof parsed.status === 'string' ? parsed.status : String(parsed.status ?? ''),
        render_mode: typeof parsed.render_mode === 'string' ? parsed.render_mode : undefined,
    }
}

export type VideoExportStatusResponse = {
    id: string
    status: string
    render_mode?: string
    output_asset_id: string | null
    error: unknown
    meta: unknown
}

export type VideoExportJobListItem = {
    id: string
    status: string
    render_mode: string | null
    output_asset_id: string | null
    duration_ms: number | null
    created_at: string | null
    updated_at: string | null
}

export async function getListVideoExportJobs(compositionId: string): Promise<{ jobs: VideoExportJobListItem[] }> {
    const res = await fetchWithTimeout(
        `/app/api/compositions/${encodeURIComponent(compositionId)}/studio/video-export`,
        {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        },
        60_000,
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Video export list failed')
    }
    if (!res.ok) {
        throw new Error((data as { message?: string })?.message || text || 'Video export list failed')
    }
    const jobs = Array.isArray((data as { jobs?: unknown }).jobs)
        ? ((data as { jobs: VideoExportJobListItem[] }).jobs as VideoExportJobListItem[])
        : []
    return { jobs }
}

export async function getVideoExportStatus(
    compositionId: string,
    exportJobId: string
): Promise<VideoExportStatusResponse> {
    const jobId = String(exportJobId)
    const res = await fetchWithTimeout(
        `/app/api/compositions/${encodeURIComponent(compositionId)}/studio/video-export/${encodeURIComponent(jobId)}`,
        {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
        },
        VIDEO_EXPORT_STATUS_FETCH_TIMEOUT_MS,
    )
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Video export status failed')
    }
    if (!res.ok) {
        throw new Error((data as { message?: string })?.message || text || 'Video export status failed')
    }
    return data as VideoExportStatusResponse
}

export async function pollVideoExportUntilTerminal(
    compositionId: string,
    exportJobId: string,
    opts?: {
        intervalMs?: number
        timeoutMs?: number
        /**
         * If the job never leaves `queued`, the export worker is probably not running.
         * Fail fast with a clear message instead of waiting for {@link timeoutMs}.
         */
        queuedStallMs?: number
        onStatus?: (s: VideoExportStatusResponse) => void
    }
): Promise<VideoExportStatusResponse> {
    const intervalMs = opts?.intervalMs ?? 2000
    const timeoutMs = opts?.timeoutMs ?? VIDEO_EXPORT_POLL_WALL_CLOCK_MS
    const queuedStallMs = opts?.queuedStallMs ?? 120_000
    const start = Date.now()
    let queuedSince: number | null = null
    const jobId = String(exportJobId)
    for (;;) {
        // Yield so the UI can paint (progress text, timers) before each status read.
        await new Promise<void>((r) => setTimeout(r, 0))
        let s: VideoExportStatusResponse
        try {
            s = await getVideoExportStatus(compositionId, jobId)
        } catch (e) {
            const name = e instanceof DOMException ? e.name : ''
            const msg = e instanceof Error ? e.message : ''
            if (name === 'AbortError' || msg.includes('aborted')) {
                throw new Error(
                    'Timed out while reading video export status (no response in time). Check the Network tab for GET /studio/video-export/{id}, proxy limits, or run a queue worker if the job stays queued.',
                )
            }
            throw e
        }
        opts?.onStatus?.(s)
        if (s.status === 'complete' || s.status === 'failed' || s.status === 'canceled' || s.status === 'cancelled') {
            return s
        }
        if (s.status === 'queued') {
            if (queuedSince === null) {
                queuedSince = Date.now()
            } else if (Date.now() - queuedSince > queuedStallMs) {
                throw new Error(
                    'Video export is still queued after waiting a couple of minutes. A worker is probably not processing the studio video queue (often named `video-heavy`). Ask an admin to run a queue worker for that queue, then try publishing again.',
                )
            }
        } else {
            queuedSince = null
        }
        if (Date.now() - start > timeoutMs) {
            throw new Error('Video export timed out while waiting for the worker.')
        }
        await new Promise<void>((r) => setTimeout(r, intervalMs))
    }
}

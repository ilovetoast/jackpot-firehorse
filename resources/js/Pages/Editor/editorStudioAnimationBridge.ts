export type PreflightRiskDto = {
    has_high_text_density: boolean
    has_logo_prominence: boolean
    has_small_text: boolean
    risk_level: 'low' | 'medium' | 'high' | string
    warning_messages: string[]
    metrics?: Record<string, unknown>
}

export type StudioAnimationJobDto = {
    id: string
    status: string
    provider: string
    provider_model: string
    source_strategy: string
    composition_id: string | null
    source_composition_version_id?: string | null
    source_document_revision_hash?: string | null
    prompt: string | null
    motion_preset: string | null
    duration_seconds: number
    aspect_ratio: string
    generate_audio: boolean
    error_code: string | null
    error_message: string | null
    created_at?: string | null
    started_at: string | null
    completed_at: string | null
    animation_intent?: Record<string, unknown> | null
    preflight_risk?: PreflightRiskDto | null
    source_lock?: Record<string, unknown> | null
    retry_kind?: 'finalize_only' | 'poll_only' | 'full_retry' | null
    user_facing_error?: string | null
    last_pipeline_event?: Record<string, unknown> | null
    /** V1.2: canonical start-frame provenance + drift (subset also flattened below). */
    canonical_frame?: Record<string, unknown> | null
    frame_drift_status?: string | null
    drift_level?: 'low' | 'medium' | 'high' | string | null
    drift_summary?: string | null
    provider_submission_used_frame?: string | null
    finalize_last_outcome?: string | null
    credits_charged?: boolean
    credits_charged_units?: number
    /** Expected credit cost held at create time; charged only after successful finalize. */
    credits_reserved?: number
    intent_version?: string | null
    high_fidelity_submit?: boolean
    finalize_reuse_mode?: string | null
    was_reused_existing_output?: boolean
    render_engine?: string | null
    renderer_version?: string | null
    verified_webhook?: boolean
    drift_decision?: Record<string, unknown> | null
    /** Present when STUDIO_ANIMATION_DIAGNOSTICS_API=true (operators / QA only). */
    rollout_diagnostics?: Record<string, unknown> | null
    output: null | {
        asset_id: string | null
        asset_view_url: string | null
        video_path: string
        mime_type: string | null
        duration_seconds: number | null
        width: number | null
        height: number | null
    }
}

const STUDIO_ANIM_TERMINAL_STATUSES = new Set(['complete', 'failed', 'canceled'])

function looksLikeProviderSubmitAuthFailure(message: string | null | undefined): boolean {
    if (message == null || message === '') {
        return false
    }
    const m = message.toLowerCase()
    return (
        m.includes('authentication') ||
        m.includes('cannot access application') ||
        /\b401\b/.test(m)
    )
}

/**
 * Copy for the small "Recovery:" line on failed jobs. Auth detection here should match
 * PHP friendlyFailureMessage for provider_submit_failed + Fal auth body errors.
 */
export function getStudioAnimationFailureRecoveryLine(
    job: Pick<StudioAnimationJobDto, 'retry_kind' | 'error_code' | 'error_message'>,
): string | null {
    if (!job.retry_kind) {
        return null
    }
    if (job.retry_kind === 'finalize_only') {
        return 'Re-download and finalize the same provider result.'
    }
    if (job.retry_kind === 'poll_only') {
        return 'Resume provider polling only.'
    }
    if (job.error_code === 'provider_submit_failed' && looksLikeProviderSubmitAuthFailure(job.error_message)) {
        return 'After Fal authentication works on the server, use Retry. Retrying before that repeats the same error.'
    }
    return 'Re-run from snapshot (new start frame).'
}

/**
 * UX hints when a job sits in queue or runs longer than usual (workers, provider backlog).
 */
export function getStudioAnimationStallHints(job: StudioAnimationJobDto): {
    level: 'none' | 'notice' | 'warn'
    lines: string[]
} {
    const lines: string[] = []
    const createdMs = job.created_at ? Date.parse(job.created_at) : NaN
    const startedMs = job.started_at ? Date.parse(job.started_at) : NaN
    const now = Date.now()
    const ageMinutes = (since: number) => (now - since) / 60_000

    if (job.status === 'queued' && Number.isFinite(createdMs)) {
        const q = ageMinutes(createdMs)
        if (q >= 3) {
            lines.push('Still queued — background workers may not be running yet, or the AI queue is busy.')
        }
        if (q >= 8) {
            lines.push(
                'Video jobs use the AI queue (often named `ai`). Start Horizon, or run a worker that listens on that queue (e.g. `php artisan queue:work --queue=ai,default`).',
            )
            return { level: 'warn', lines }
        }
        if (lines.length > 0) {
            return { level: 'notice', lines }
        }
    }

    if (!STUDIO_ANIM_TERMINAL_STATUSES.has(job.status) && job.status !== 'queued') {
        const anchorMs = Number.isFinite(startedMs) ? startedMs : Number.isFinite(createdMs) ? createdMs : NaN
        if (Number.isFinite(anchorMs)) {
            const r = ageMinutes(anchorMs)
            if (r >= 8) {
                lines.push('Taking longer than usual — the provider can be slow when their service is busy.')
            }
            if (r >= 18) {
                lines.push('If it never completes, wait for a failed state and use Retry, or contact support with this job number.')
                return { level: 'warn', lines }
            }
            if (lines.length > 0) {
                return { level: 'notice', lines }
            }
        }
    }

    return { level: 'none', lines: [] }
}

function csrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

function formatStudioAnimationApiError(data: unknown, fallback: string): string {
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

export async function fetchStudioAnimations(compositionId: string): Promise<{ animations: StudioAnimationJobDto[] }> {
    const res = await fetch(`/app/studio/documents/${encodeURIComponent(compositionId)}/animations`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        const t = await res.text()
        throw new Error(t || `Animations list failed (${res.status})`)
    }
    return res.json()
}

export async function fetchStudioAnimationJob(jobId: string): Promise<StudioAnimationJobDto> {
    const res = await fetch(`/app/studio/animations/${encodeURIComponent(jobId)}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        const t = await res.text()
        throw new Error(t || `Animation job failed (${res.status})`)
    }
    return res.json()
}

export async function postStudioAnimationPreflight(
    compositionId: string,
    body: { document_json: unknown; canvas_width: number; canvas_height: number }
): Promise<{ preflight: PreflightRiskDto }> {
    const res = await fetch(`/app/studio/documents/${encodeURIComponent(compositionId)}/animation-preflight`, {
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
        throw new Error(text || 'Preflight failed')
    }
    if (!res.ok) {
        throw new Error((data as { message?: string })?.message || text || 'Preflight failed')
    }
    return data as { preflight: PreflightRiskDto }
}

export type PostStudioAnimationPayload = {
    provider: string
    provider_model: string
    source_strategy: string
    prompt: string | null
    negative_prompt: string | null
    motion_preset: string | null
    duration_seconds: number
    aspect_ratio: string
    generate_audio: boolean
    composition_snapshot_png_base64: string
    snapshot_width: number
    snapshot_height: number
    document_json?: unknown
    source_composition_version_id?: string | number | null
}

export async function postStudioAnimation(
    compositionId: string,
    payload: PostStudioAnimationPayload
): Promise<StudioAnimationJobDto> {
    const res = await fetch(`/app/studio/documents/${encodeURIComponent(compositionId)}/animations`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        if (res.status === 413) {
            throw new Error(
                'The snapshot is larger than this server accepts (HTTP 413). An admin should raise nginx client_max_body_size and PHP post_max_size (try 32m+). If you already raised limits, try a smaller canvas for this composition.'
            )
        }
        throw new Error(text || 'Animation request failed')
    }
    if (!res.ok) {
        if (res.status === 413) {
            throw new Error(
                'The snapshot is larger than this server accepts (HTTP 413). An admin should raise nginx client_max_body_size and PHP post_max_size (try 32m+). If you already raised limits, try a smaller canvas for this composition.'
            )
        }
        throw new Error(formatStudioAnimationApiError(data, text || `Animation request failed (${res.status})`))
    }
    return data as StudioAnimationJobDto
}

export async function postStudioAnimationRetry(jobId: string): Promise<StudioAnimationJobDto> {
    const res = await fetch(`/app/studio/animations/${encodeURIComponent(jobId)}/retry`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Retry failed')
    }
    if (!res.ok) {
        throw new Error((data as { message?: string })?.message || text)
    }
    return data as StudioAnimationJobDto
}

/** Versions-rail tile title: composition name + job id (falls back to “Video” if title empty). */
export function studioAnimationRailJobLabel(compositionTitle: string, jobId: string): string {
    const t = compositionTitle.trim()
    return t !== '' ? `${t} #${jobId}` : `Video #${jobId}`
}

export async function deleteStudioAnimationJob(jobId: string): Promise<void> {
    const res = await fetch(`/app/studio/animations/${encodeURIComponent(jobId)}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
    })
    if (res.status === 204 || res.status === 200) {
        return
    }
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || `Remove job failed (${res.status})`)
    }
    throw new Error((data as { message?: string })?.message || text || `Remove job failed (${res.status})`)
}

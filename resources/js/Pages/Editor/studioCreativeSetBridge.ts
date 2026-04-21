import type {
    CreativeSetApplyCommand,
    CreativeSetApplyPreviewDto,
    CreativeSetSemanticApplyScopeApi,
    StudioCreativeSetDto,
    StudioGenerationJobDto,
    StudioGenerationPresetsDto,
} from './studioCreativeSetTypes'

function csrfHeaders(): HeadersInit {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf ?? '',
    }
}

export async function fetchCreativeSetForComposition(compositionId: string): Promise<{
    creative_set: StudioCreativeSetDto | null
}> {
    const res = await fetch(`/app/api/creative-sets/for-composition/${encodeURIComponent(compositionId)}`, {
        method: 'GET',
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const data = (await res.json().catch(() => ({}))) as {
        creative_set?: StudioCreativeSetDto | null
        error?: string
    }
    if (!res.ok) {
        throw new Error(data.error || 'Could not load Versions set')
    }
    return { creative_set: data.creative_set ?? null }
}

export async function postCreativeSet(body: {
    composition_id: string
    name?: string
}): Promise<{ creative_set: StudioCreativeSetDto }> {
    const res = await fetch('/app/api/creative-sets', {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            composition_id: Number(body.composition_id),
            name: body.name,
        }),
    })
    const data = (await res.json().catch(() => ({}))) as {
        creative_set?: StudioCreativeSetDto
        error?: string
    }
    if (!res.ok) {
        throw new Error(data.error || 'Could not create Versions set')
    }
    if (!data.creative_set) {
        throw new Error('Invalid response')
    }
    return { creative_set: data.creative_set }
}

export async function postCreativeSetVariant(
    creativeSetId: string,
    body: { source_composition_id: string; label?: string }
): Promise<{ creative_set: StudioCreativeSetDto; variant: StudioCreativeSetDto['variants'][number] }> {
    const res = await fetch(`/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/variants`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            source_composition_id: Number(body.source_composition_id),
            label: body.label,
        }),
    })
    const data = (await res.json().catch(() => ({}))) as {
        creative_set?: StudioCreativeSetDto
        variant?: StudioCreativeSetDto['variants'][number]
        error?: string
    }
    if (!res.ok) {
        throw new Error(data.error || 'Could not duplicate version')
    }
    if (!data.creative_set || !data.variant) {
        throw new Error('Invalid response')
    }
    return { creative_set: data.creative_set, variant: data.variant }
}

export async function fetchGenerationPresets(): Promise<StudioGenerationPresetsDto> {
    const res = await fetch('/app/api/creative-sets/generation-presets', {
        method: 'GET',
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const data = (await res.json().catch(() => ({}))) as StudioGenerationPresetsDto & { error?: string }
    if (!res.ok) {
        throw new Error(data.error || 'Could not load generation presets')
    }
    return data as StudioGenerationPresetsDto
}

export async function postCreativeSetGenerate(
    creativeSetId: string,
    body: {
        source_composition_id: string
        color_ids?: string[]
        scene_ids?: string[]
        format_ids?: string[]
        selected_combination_keys?: string[]
    }
): Promise<{ generation_job: StudioGenerationJobDto }> {
    const res = await fetch(`/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/generate`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            source_composition_id: Number(body.source_composition_id),
            color_ids: body.color_ids ?? [],
            scene_ids: body.scene_ids ?? [],
            format_ids: body.format_ids ?? [],
            selected_combination_keys: body.selected_combination_keys,
        }),
    })
    const data = (await res.json().catch(() => ({}))) as {
        generation_job?: StudioGenerationJobDto
        message?: string
        errors?: Record<string, string[]>
    }
    if (!res.ok) {
        const fromValidation =
            data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors)
                      .flat()
                      .filter(Boolean)
                      .join(' ')
                : ''
        throw new Error(fromValidation || data.message || 'Could not start generation')
    }
    if (!data.generation_job) {
        throw new Error('Invalid response')
    }
    return { generation_job: data.generation_job }
}

export async function postRetryGenerationJobItem(
    creativeSetId: string,
    itemId: string
): Promise<{ generation_job: StudioGenerationJobDto; creative_set: StudioCreativeSetDto }> {
    const res = await fetch(
        `/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/generation-job-items/${encodeURIComponent(itemId)}/retry`,
        {
            method: 'POST',
            headers: csrfHeaders(),
            credentials: 'same-origin',
        }
    )
    const data = (await res.json().catch(() => ({}))) as {
        generation_job?: StudioGenerationJobDto
        creative_set?: StudioCreativeSetDto
        message?: string
        errors?: Record<string, string[]>
    }
    if (!res.ok) {
        const fromValidation =
            data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors)
                      .flat()
                      .filter(Boolean)
                      .join(' ')
                : ''
        throw new Error(fromValidation || data.message || 'Could not retry version')
    }
    if (!data.generation_job || !data.creative_set) {
        throw new Error('Invalid response')
    }
    return { generation_job: data.generation_job, creative_set: data.creative_set }
}

export async function fetchCreativeSetGenerationJob(
    creativeSetId: string,
    jobId: string
): Promise<{ generation_job: StudioGenerationJobDto; creative_set: StudioCreativeSetDto }> {
    const res = await fetch(
        `/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/generation-jobs/${encodeURIComponent(jobId)}`,
        {
            method: 'GET',
            headers: csrfHeaders(),
            credentials: 'same-origin',
        }
    )
    const data = (await res.json().catch(() => ({}))) as {
        generation_job?: StudioGenerationJobDto
        creative_set?: StudioCreativeSetDto
        error?: string
    }
    if (!res.ok) {
        throw new Error(data.error || 'Could not load generation job')
    }
    if (!data.generation_job || !data.creative_set) {
        throw new Error('Invalid response')
    }
    return { generation_job: data.generation_job, creative_set: data.creative_set }
}

export async function patchCreativeSetHero(
    creativeSetId: string,
    body: { composition_id: string | number | null }
): Promise<{ creative_set: StudioCreativeSetDto }> {
    const res = await fetch(`/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/hero`, {
        method: 'PATCH',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            composition_id:
                body.composition_id === null || body.composition_id === ''
                    ? null
                    : Number(body.composition_id),
        }),
    })
    const data = (await res.json().catch(() => ({}))) as {
        creative_set?: StudioCreativeSetDto
        message?: string
        errors?: Record<string, string[]>
    }
    if (!res.ok) {
        const fromValidation =
            data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors)
                      .flat()
                      .filter(Boolean)
                      .join(' ')
                : ''
        throw new Error(fromValidation || data.message || 'Could not update hero')
    }
    if (!data.creative_set) {
        throw new Error('Invalid response')
    }
    return { creative_set: data.creative_set }
}

export type {
    CreativeSetApplyCommand,
    CreativeSetApplyPreviewDto,
    CreativeSetSemanticApplyScopeApi,
} from './studioCreativeSetTypes'

export async function postCreativeSetApplyPreview(
    creativeSetId: string,
    body: {
        source_composition_id: string
        commands: CreativeSetApplyCommand[]
        scope?: CreativeSetSemanticApplyScopeApi
        target_composition_ids?: number[]
    }
): Promise<CreativeSetApplyPreviewDto> {
    const payload: Record<string, unknown> = {
        source_composition_id: Number(body.source_composition_id),
        commands: body.commands,
    }
    if (body.scope) {
        payload.scope = body.scope
    }
    if (body.target_composition_ids && body.target_composition_ids.length > 0) {
        payload.target_composition_ids = body.target_composition_ids
    }
    const res = await fetch(`/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/apply-preview`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    const data = (await res.json().catch(() => ({}))) as CreativeSetApplyPreviewDto & {
        ok?: boolean
        message?: string
        errors?: Record<string, string[]>
    }
    if (!res.ok) {
        const fromValidation =
            data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors)
                      .flat()
                      .filter(Boolean)
                      .join(' ')
                : ''
        throw new Error(fromValidation || (data as { message?: string }).message || 'Apply preview failed')
    }
    if (typeof data.sibling_compositions_targeted !== 'number') {
        throw new Error('Invalid preview response')
    }
    return {
        scope: data.scope,
        skipped: data.skipped ?? [],
        skipped_by_reason: data.skipped_by_reason ?? {},
        sibling_compositions_targeted: data.sibling_compositions_targeted,
        sibling_compositions_eligible: data.sibling_compositions_eligible ?? 0,
        sibling_compositions_would_skip: data.sibling_compositions_would_skip ?? 0,
        commands_considered: data.commands_considered ?? 0,
    }
}

export async function postCreativeSetApply(
    creativeSetId: string,
    body: {
        source_composition_id: string
        commands: CreativeSetApplyCommand[]
        scope?: CreativeSetSemanticApplyScopeApi
        target_composition_ids?: number[]
    }
): Promise<{
    updated_composition_ids: string[]
    skipped: Array<{ composition_id: string; reason: string; reason_code?: string }>
    skipped_by_reason?: Record<string, number>
    creative_set: StudioCreativeSetDto
    sibling_compositions_targeted?: number
    sibling_compositions_updated?: number
    commands_applied?: number
}> {
    const applyPayload: Record<string, unknown> = {
        source_composition_id: Number(body.source_composition_id),
        commands: body.commands,
    }
    if (body.scope) {
        applyPayload.scope = body.scope
    }
    if (body.target_composition_ids && body.target_composition_ids.length > 0) {
        applyPayload.target_composition_ids = body.target_composition_ids
    }
    const res = await fetch(`/app/api/creative-sets/${encodeURIComponent(creativeSetId)}/apply`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify(applyPayload),
    })
    const data = (await res.json().catch(() => ({}))) as {
        ok?: boolean
        updated_composition_ids?: string[]
        skipped?: Array<{ composition_id: string; reason: string; reason_code?: string }>
        skipped_by_reason?: Record<string, number>
        creative_set?: StudioCreativeSetDto
        sibling_compositions_targeted?: number
        sibling_compositions_updated?: number
        commands_applied?: number
        message?: string
        errors?: Record<string, string[]>
    }
    if (!res.ok) {
        const fromValidation =
            data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors)
                      .flat()
                      .filter(Boolean)
                      .join(' ')
                : ''
        throw new Error(fromValidation || data.message || 'Apply failed')
    }
    if (!data.creative_set || !Array.isArray(data.updated_composition_ids)) {
        throw new Error('Invalid response')
    }
    return {
        updated_composition_ids: data.updated_composition_ids,
        skipped: data.skipped ?? [],
        skipped_by_reason: data.skipped_by_reason,
        creative_set: data.creative_set,
        sibling_compositions_targeted: data.sibling_compositions_targeted,
        sibling_compositions_updated: data.sibling_compositions_updated,
        commands_applied: data.commands_applied,
    }
}

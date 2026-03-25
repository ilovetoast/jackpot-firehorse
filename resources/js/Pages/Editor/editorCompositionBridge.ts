import type { DocumentModel } from './documentModel'

export type CompositionDto = {
    id: string
    name: string
    document: DocumentModel
    thumbnail_url?: string | null
    created_at: string
    updated_at: string
}

export type CompositionVersionMeta = {
    id: string
    composition_id: string
    label: string | null
    created_at: string
    thumbnail_url?: string | null
}

export type CompositionVersionDto = CompositionVersionMeta & {
    document: DocumentModel
}

function csrfHeaders(): HeadersInit {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf ?? '',
    }
}

export async function postComposition(
    name: string,
    document: DocumentModel,
    opts?: { thumbnailPngBase64?: string | null }
): Promise<CompositionDto> {
    const res = await fetch('/app/api/compositions', {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            name,
            document,
            thumbnail_png_base64: opts?.thumbnailPngBase64 ?? undefined,
        }),
    })
    const text = await res.text()
    let data: { composition?: CompositionDto; error?: string }
    try {
        data = JSON.parse(text) as { composition?: CompositionDto; error?: string }
    } catch {
        throw new Error(text || 'Save failed')
    }
    if (!res.ok) {
        throw new Error(data.error || text || 'Save failed')
    }
    if (!data.composition) {
        throw new Error('Invalid response')
    }
    return data.composition
}

export async function putComposition(
    id: string,
    document: DocumentModel,
    opts?: {
        name?: string
        versionLabel?: string | null
        /** When false, only updates document (and optional thumbnail); no new version row (autosave). */
        createVersion?: boolean
        thumbnailPngBase64?: string | null
    }
): Promise<CompositionDto> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(id)}`, {
        method: 'PUT',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            name: opts?.name,
            document,
            version_label: opts?.versionLabel ?? null,
            create_version: opts?.createVersion ?? true,
            thumbnail_png_base64: opts?.thumbnailPngBase64 ?? undefined,
        }),
    })
    const text = await res.text()
    let data: { composition?: CompositionDto; error?: string }
    try {
        data = JSON.parse(text) as { composition?: CompositionDto; error?: string }
    } catch {
        throw new Error(text || 'Save failed')
    }
    if (!res.ok) {
        throw new Error(data.error || text || 'Save failed')
    }
    if (!data.composition) {
        throw new Error('Invalid response')
    }
    return data.composition
}

export async function getComposition(id: string): Promise<CompositionDto> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(id)}`, {
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: { composition?: CompositionDto; error?: string }
    try {
        data = JSON.parse(text) as { composition?: CompositionDto; error?: string }
    } catch {
        throw new Error(text || 'Load failed')
    }
    if (!res.ok) {
        throw new Error(data.error || text || 'Load failed')
    }
    if (!data.composition) {
        throw new Error('Invalid response')
    }
    return data.composition
}

export type CompositionSummaryDto = {
    id: string
    name: string
    thumbnail_url?: string | null
    updated_at: string
}

/** GET /app/api/compositions — lightweight list for “Open” (no full document JSON). */
export async function fetchCompositionSummaries(): Promise<CompositionSummaryDto[]> {
    const res = await fetch('/app/api/compositions', {
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: { compositions?: CompositionSummaryDto[]; error?: string }
    try {
        data = JSON.parse(text) as { compositions?: CompositionSummaryDto[]; error?: string }
    } catch {
        throw new Error(text || 'Failed to list compositions')
    }
    if (!res.ok) {
        throw new Error(data.error || text || 'Failed to list compositions')
    }
    return data.compositions ?? []
}

export async function fetchCompositionVersions(compositionId: string): Promise<CompositionVersionMeta[]> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(compositionId)}/versions`, {
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: { versions?: CompositionVersionMeta[]; error?: string }
    try {
        data = JSON.parse(text) as { versions?: CompositionVersionMeta[]; error?: string }
    } catch {
        return []
    }
    if (!res.ok) {
        return []
    }
    return data.versions ?? []
}

export async function getCompositionVersion(
    compositionId: string,
    versionId: string
): Promise<CompositionVersionDto | null> {
    const res = await fetch(
        `/app/api/compositions/${encodeURIComponent(compositionId)}/versions/${encodeURIComponent(versionId)}`,
        {
            headers: csrfHeaders(),
            credentials: 'same-origin',
        }
    )
    const text = await res.text()
    let data: { version?: CompositionVersionDto; error?: string }
    try {
        data = JSON.parse(text) as { version?: CompositionVersionDto; error?: string }
    } catch {
        return null
    }
    if (!res.ok || !data.version) {
        return null
    }
    return data.version
}

export async function postCompositionVersion(
    compositionId: string,
    document: DocumentModel,
    label?: string | null,
    thumbnailPngBase64?: string | null
): Promise<{ composition: CompositionDto; version: CompositionVersionDto | null }> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(compositionId)}/versions`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({
            document,
            label: label ?? null,
            thumbnail_png_base64: thumbnailPngBase64 ?? undefined,
        }),
    })
    const text = await res.text()
    let data: {
        composition?: CompositionDto
        version?: CompositionVersionDto
        error?: string
    }
    try {
        data = JSON.parse(text) as {
            composition?: CompositionDto
            version?: CompositionVersionDto
            error?: string
        }
    } catch {
        throw new Error(text || 'Version save failed')
    }
    if (!res.ok || !data.composition) {
        throw new Error(data.error || text || 'Version save failed')
    }
    return { composition: data.composition, version: data.version ?? null }
}

/** DELETE /app/api/compositions/{id} — removes composition + version rows; preview assets only; not library assets. */
export async function deleteCompositionApi(compositionId: string): Promise<void> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(compositionId)}`, {
        method: 'DELETE',
        headers: csrfHeaders(),
        credentials: 'same-origin',
    })
    const text = await res.text()
    let data: { ok?: boolean; error?: string }
    try {
        data = JSON.parse(text) as { ok?: boolean; error?: string }
    } catch {
        throw new Error(text || 'Delete failed')
    }
    if (!res.ok) {
        throw new Error(data.error || text || 'Delete failed')
    }
}

export async function duplicateCompositionApi(
    compositionId: string,
    name?: string
): Promise<CompositionDto> {
    const res = await fetch(`/app/api/compositions/${encodeURIComponent(compositionId)}/duplicate`, {
        method: 'POST',
        headers: csrfHeaders(),
        credentials: 'same-origin',
        body: JSON.stringify({ name: name ?? null }),
    })
    const text = await res.text()
    let data: { composition?: CompositionDto; error?: string }
    try {
        data = JSON.parse(text) as { composition?: CompositionDto; error?: string }
    } catch {
        throw new Error(text || 'Duplicate failed')
    }
    if (!res.ok || !data.composition) {
        throw new Error(data.error || text || 'Duplicate failed')
    }
    return data.composition
}

export async function postCompositionFromDocument(
    name: string,
    document: DocumentModel
): Promise<CompositionDto> {
    return postComposition(name, document)
}

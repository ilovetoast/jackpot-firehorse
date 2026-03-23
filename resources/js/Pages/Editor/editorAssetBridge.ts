import type { DamPickerAsset, DocumentModel } from './documentModel'

export type EditorAssetsResponse = {
    assets: Array<{
        id: string
        name: string
        thumbnail_url: string
        file_url: string
        width?: number
        height?: number
    }>
    default_category_id?: number | null
    default_category_slug?: string | null
}

export type EditorPublishCategory = { id: number; name: string; slug: string; asset_type: 'asset' | 'deliverable' }

export type EditorPublishCategoriesResponse = {
    categories: EditorPublishCategory[]
    default_category_id: number | null
}

/** Same payload as GET /app/uploads/metadata-schema (context=upload). */
export type EditorPublishMetadataSchema = {
    groups: Array<{
        key: string
        label: string
        fields: Array<{
            field_id: number
            key: string
            display_label: string
            type: string
            is_required?: boolean
            required?: boolean
            options?: Array<{ value: string; display_label: string }>
            can_edit?: boolean
            display_widget?: string
        }>
    }>
}

export async function fetchEditorPublishMetadataSchema(categoryId: number): Promise<EditorPublishMetadataSchema> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const params = new URLSearchParams({
        category_id: String(categoryId),
        asset_type: 'image',
        context: 'upload',
    })
    const res = await fetch(`/app/uploads/metadata-schema?${params.toString()}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    const data = (await res.json()) as { error?: string; message?: string; groups?: EditorPublishMetadataSchema['groups'] }
    if (!res.ok || data.error) {
        throw new Error(data.message || data.error || `Failed to load metadata schema (${res.status})`)
    }
    return { groups: data.groups ?? [] }
}

export type EditorCollectionOption = { id: number; name: string; is_public?: boolean }

export async function fetchEditorCollectionsForPublish(): Promise<EditorCollectionOption[]> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const res = await fetch('/app/collections/list', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        throw new Error(`Failed to load collections (${res.status})`)
    }
    const data = (await res.json()) as { collections?: EditorCollectionOption[] }
    return data.collections ?? []
}

/** GET /app/api/assets/categories — library categories for Save & publish */
export async function fetchEditorPublishCategories(): Promise<EditorPublishCategoriesResponse> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const res = await fetch('/app/api/assets/categories', {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        throw new Error(`Failed to load categories (${res.status})`)
    }
    return res.json()
}

export type FetchEditorAssetsOptions = {
    /** Library (default) vs executions / deliverables */
    assetType?: 'asset' | 'deliverable'
    /** Filter by DAM category (metadata.category_id), e.g. Photography, Print */
    categoryId?: number
}

export async function fetchEditorAssets(
    limit = 50,
    options?: FetchEditorAssetsOptions
): Promise<EditorAssetsResponse> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const params = new URLSearchParams({ limit: String(limit) })
    if (options?.assetType === 'deliverable') {
        params.set('asset_type', 'deliverable')
    }
    if (options?.categoryId != null && Number.isFinite(options.categoryId) && options.categoryId > 0) {
        params.set('category_id', String(Math.floor(options.categoryId)))
    }
    const res = await fetch(`/app/api/assets?${params.toString()}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        throw new Error(`Failed to load assets (${res.status})`)
    }
    return res.json()
}

/** GET /app/api/assets/{id} — single library asset for reference thumbnails not in the list batch. */
export async function fetchEditorAssetById(assetId: string): Promise<DamPickerAsset | null> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const res = await fetch(`/app/api/assets/${encodeURIComponent(assetId)}`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (res.status === 404) {
        return null
    }
    if (!res.ok) {
        return null
    }
    const data = (await res.json()) as { asset?: DamPickerAsset }
    return data.asset ?? null
}

export function loadImageNaturalSize(src: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const im = new Image()
        try {
            const u = new URL(src, window.location.href)
            if (u.origin !== window.location.origin) {
                im.crossOrigin = 'anonymous'
            }
        } catch {
            im.crossOrigin = 'anonymous'
        }
        im.onload = () => resolve({ width: im.naturalWidth, height: im.naturalHeight })
        im.onerror = () => reject(new Error('Image failed to load'))
        im.src = src
    })
}

/**
 * Always measure pixels from file_url in the browser (API/CDN dimensions can be wrong).
 * Falls back to API values, then safe defaults.
 */
export async function confirmDamAssetDimensions(asset: DamPickerAsset): Promise<{ width: number; height: number }> {
    try {
        const dims = await loadImageNaturalSize(asset.file_url)
        return { width: dims.width, height: dims.height }
    } catch {
        const w = asset.width && asset.width > 0 ? asset.width : 800
        const h = asset.height && asset.height > 0 ? asset.height : 600
        return { width: w, height: h }
    }
}

export type EditorPromotionMetadata = {
    source: 'editor'
    document: DocumentModel
    layers_count: number
    has_text: boolean
    has_images: boolean
    has_generative: boolean
    has_brand_influence: boolean
    reference_count: number
    /** Distinct first font-family tokens from text layers (for DAM / downstream). */
    font_families: string[]
}

function collectTextFontFamilies(doc: DocumentModel): string[] {
    const seen = new Set<string>()
    const out: string[] = []
    for (const l of doc.layers) {
        if (l.type !== 'text') {
            continue
        }
        const raw = l.style.fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')
        if (raw && !seen.has(raw)) {
            seen.add(raw)
            out.push(raw)
        }
    }
    return out
}

export function buildPromotionMetadata(doc: DocumentModel): EditorPromotionMetadata {
    const reference_count = doc.layers.reduce((acc, l) => {
        if (l.type !== 'generative_image') {
            return acc
        }
        return acc + (l.referenceAssetIds?.length ?? 0)
    }, 0)
    return {
        source: 'editor',
        document: doc,
        layers_count: doc.layers.length,
        has_text: doc.layers.some((l) => l.type === 'text'),
        has_images: doc.layers.some((l) => l.type === 'image'),
        has_generative: doc.layers.some((l) => l.type === 'generative_image'),
        has_brand_influence: doc.layers.some(
            (l) => l.type === 'generative_image' && l.applyBrandDna !== false
        ),
        reference_count,
        font_families: collectTextFontFamilies(doc),
    }
}

/** POST /app/api/assets — multipart composition export into DAM */
export async function promoteCompositionToAsset(
    blob: Blob,
    name: string,
    doc: DocumentModel,
    options?: {
        categoryId?: number
        description?: string
        /** DAM metadata field keys (from upload schema), merged with editor promotion payload. */
        fieldMetadata?: Record<string, unknown>
        collectionIds?: number[]
    }
): Promise<{ assetId: string }> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const safeBase = name.replace(/\.(png|jpg|jpeg|webp)$/i, '').trim() || 'composition'
    const fd = new FormData()
    const filename = `${safeBase.replace(/[^a-z0-9-_]+/gi, '_')}.png`
    fd.append('file', blob, filename)
    fd.append('name', safeBase)
    if (options?.categoryId != null && Number.isFinite(options.categoryId)) {
        fd.append('category_id', String(Math.floor(options.categoryId)))
    }
    const desc = options?.description?.trim()
    if (desc) {
        fd.append('description', desc)
    }
    // Only category upload-schema field keys (plus server-side editor_publish_description).
    // Do not merge buildPromotionMetadata() here — keys like source, document, layers_count
    // are not in the resolved upload schema and finalize rejects the whole manifest.
    const mergedMeta: Record<string, unknown> = {
        ...(options?.fieldMetadata ?? {}),
    }
    fd.append('metadata', JSON.stringify(mergedMeta))
    const cids = options?.collectionIds?.filter((id) => Number.isFinite(id) && id > 0) ?? []
    if (cids.length > 0) {
        fd.append('collection_ids', JSON.stringify(cids.map((id) => Math.floor(id))))
    }
    const res = await fetch('/app/api/assets', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
        body: fd,
    })
    const text = await res.text()
    let data: unknown
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || 'Save failed')
    }
    if (!res.ok) {
        const msg = (data as { message?: string })?.message || text
        throw new Error(msg)
    }
    const results = (data as { results?: Array<{ status?: string; asset_id?: number | string }> }).results
    const first = results?.[0]
    if (first?.status === 'failed') {
        throw new Error('Asset creation failed')
    }
    const id = first?.asset_id
    if (!id) {
        throw new Error('No asset id returned')
    }
    return { assetId: String(id) }
}

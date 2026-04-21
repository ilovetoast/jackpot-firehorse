export type EditorAssetVersionRow = {
    id: string
    url: string
    created_at?: string
    /** Monotonic per asset; 1 = original upload. */
    version_number?: number
    /** DAM “current” file — used when layer has no explicit {@link ImageLayer.assetVersionId}. */
    is_current?: boolean
}

export type EditorAssetVersionsResponse = {
    versions: EditorAssetVersionRow[]
}

/**
 * Timeline order: lowest version_number first (original upload), each AI save
 * appended with the next number — reads left → right as oldest → newest.
 */
export function orderAssetVersionsForStrip(versions: EditorAssetVersionRow[]): EditorAssetVersionRow[] {
    if (versions.length === 0) {
        return []
    }
    return [...versions].sort((a, b) => {
        const na = a.version_number ?? 0
        const nb = b.version_number ?? 0
        if (na !== nb) {
            return na - nb
        }
        return String(a.id).localeCompare(String(b.id))
    })
}

export function assetVersionStripLabel(v: EditorAssetVersionRow, minVersionNumber: number): string {
    const n = v.version_number ?? minVersionNumber
    if (n <= minVersionNumber) {
        return 'Original'
    }
    const step = n - minVersionNumber
    return step === 1 ? 'AI 1' : `AI ${step}`
}

const stripUrlKey = (u: string | undefined): string => (u || '').split('?')[0]

/** Highlight state when {@link ImageLayer.assetVersionId} is unset (e.g. legacy layer). */
export function isAssetVersionThumbnailActive(
    layer: { assetVersionId?: string; src?: string },
    v: EditorAssetVersionRow,
    orderedStrip: EditorAssetVersionRow[]
): boolean {
    if (layer.assetVersionId) {
        return layer.assetVersionId === v.id
    }
    if (layer.src && stripUrlKey(layer.src) === stripUrlKey(v.url)) {
        return true
    }
    const current = orderedStrip.find((r) => r.is_current)
    if (current) {
        return current.id === v.id
    }
    const nums = orderedStrip.map((r) => r.version_number ?? 0)
    if (nums.length === 0) {
        return false
    }
    const maxN = Math.max(...nums)
    return (v.version_number ?? 0) === maxN
}

/** GET /app/api/assets/{assetId}/versions */
export async function fetchAssetVersions(assetId: string): Promise<EditorAssetVersionsResponse> {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    // Bust caches: after repeated AI edits the asset id is unchanged but new version rows exist.
    const res = await fetch(
        `/app/api/assets/${encodeURIComponent(assetId)}/versions?_=${encodeURIComponent(String(Date.now()))}`,
        {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
            credentials: 'same-origin',
            cache: 'no-store',
        }
    )
    if (!res.ok) {
        throw new Error(`Failed to fetch versions (${res.status})`)
    }
    return res.json() as Promise<EditorAssetVersionsResponse>
}

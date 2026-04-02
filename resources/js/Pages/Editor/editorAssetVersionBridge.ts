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
 * Original (lowest version_number) first, then newer edits first (descending version_number).
 */
export function orderAssetVersionsForStrip(versions: EditorAssetVersionRow[]): EditorAssetVersionRow[] {
    if (versions.length === 0) {
        return []
    }
    const nums = versions.map((v) => v.version_number ?? 0)
    const minN = Math.min(...nums)
    const originals = versions.filter((v) => (v.version_number ?? minN) === minN)
    const edits = versions
        .filter((v) => (v.version_number ?? minN) !== minN)
        .sort((a, b) => (b.version_number ?? 0) - (a.version_number ?? 0))
    return [...originals, ...edits]
}

export function assetVersionStripLabel(v: EditorAssetVersionRow, minVersionNumber: number): string {
    const n = v.version_number ?? minVersionNumber
    if (n <= minVersionNumber) {
        return 'Original'
    }
    return `v${n}`
}

/** Highlight state when {@link ImageLayer.assetVersionId} is unset (e.g. legacy layer). */
export function isAssetVersionThumbnailActive(
    layer: { assetVersionId?: string },
    v: EditorAssetVersionRow,
    orderedStrip: EditorAssetVersionRow[]
): boolean {
    if (layer.assetVersionId) {
        return layer.assetVersionId === v.id
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
    const res = await fetch(`/app/api/assets/${encodeURIComponent(assetId)}/versions`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
        credentials: 'same-origin',
    })
    if (!res.ok) {
        throw new Error(`Failed to fetch versions (${res.status})`)
    }
    return res.json() as Promise<EditorAssetVersionsResponse>
}

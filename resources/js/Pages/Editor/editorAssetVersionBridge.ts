export type EditorAssetVersionRow = {
    id: string
    url: string
    created_at?: string
}

export type EditorAssetVersionsResponse = {
    versions: EditorAssetVersionRow[]
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

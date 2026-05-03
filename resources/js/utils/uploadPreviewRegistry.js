/**
 * Session-only registry of blob URLs for assets whose server thumbnails are not ready yet.
 * Survives Inertia partial reload in the same tab; cleared on explicit revoke / tab close.
 */

/** @typedef {{ clientFileId: string, assetId: string|null, objectUrl: string, filename: string, size: number, mimeType: string, createdAt: number, uploadSessionItemId?: string|null, status?: string, failReason?: string|null }} PreviewEntry */

const byClient = new Map()
/** @type {Map<string, PreviewEntry>} keyed by {@link normalizeAssetId} */
const byAsset = new Map()

/**
 * Laravel assets use string UUID primary keys; legacy/tests may use integer ids.
 * @param {unknown} assetId
 * @returns {string|null}
 */
export function normalizeAssetId(assetId) {
    if (assetId == null) return null
    if (typeof assetId === 'number') {
        return Number.isFinite(assetId) ? String(assetId) : null
    }
    const s = String(assetId).trim()
    return s.length > 0 ? s : null
}
/** Ordered client ids: finalize in flight or waiting for grid to include the new asset row */
const pendingFinalizeOrder = []
let globalVersion = 0
/** @type {Set<() => void>} */
const listeners = new Set()

function bump() {
    globalVersion += 1
    listeners.forEach((fn) => {
        try {
            fn()
        } catch (_) {
            /* ignore */
        }
    })
}

export function subscribeUploadPreviewRegistry(onStoreChange) {
    listeners.add(onStoreChange)
    return () => listeners.delete(onStoreChange)
}

export function getUploadPreviewRegistryVersion() {
    return globalVersion
}

/**
 * @param {string} clientFileId
 * @param {string} objectUrl
 * @param {{ filename?: string, size?: number, mimeType?: string }} meta
 */
export function registerUploadPreview(clientFileId, objectUrl, meta = {}) {
    if (!clientFileId || !objectUrl) return
    const key = String(clientFileId)
    revokeClientUploadPreview(key, false)
    const entry = {
        clientFileId: key,
        assetId: null,
        objectUrl,
        filename: meta.filename || '',
        size: meta.size ?? 0,
        mimeType: meta.mimeType || '',
        createdAt: Date.now(),
        uploadSessionItemId: meta.uploadSessionItemId != null ? String(meta.uploadSessionItemId) : null,
        status: 'registered',
        failReason: null,
    }
    byClient.set(key, entry)
    bump()
}

/**
 * @param {string} clientFileId
 * @param {number|string} assetId
 */
export function attachUploadPreviewAssetId(clientFileId, assetId) {
    const id = normalizeAssetId(assetId)
    if (!clientFileId || !id) return
    const entry = byClient.get(String(clientFileId))
    if (!entry) return
    entry.assetId = id
    entry.status = 'attached'
    byAsset.set(id, entry)
    bump()
}

/**
 * @param {Array<{ client_file_id?: string|null, asset_id?: number|string|null, status?: string }>} results
 */
export function attachUploadPreviewsFromFinalizeResults(results) {
    if (!Array.isArray(results)) return
    for (const r of results) {
        const ok = r?.status === 'success' || r?.status === true
        if (!ok) continue
        const cid = r.client_file_id ?? r.clientFileId
        const aid = r.asset_id ?? r.assetId
        if (cid && aid != null) {
            attachUploadPreviewAssetId(String(cid), aid)
        }
    }
}

/** @returns {string|null} */
export function getUploadPreviewObjectUrlForAsset(assetId) {
    const id = normalizeAssetId(assetId)
    if (!id) return null
    const e = byAsset.get(id)
    return e?.objectUrl ?? null
}

/** Stable string for useSyncExternalStore (version + URL, avoids object identity churn). */
export function getUploadPreviewSnapshotForAsset(assetId) {
    const u = getUploadPreviewObjectUrlForAsset(assetId)
    return `${globalVersion}\u0001${u ?? ''}`
}

/** @returns {string|null} */
export function getUploadPreviewObjectUrlForClient(clientFileId) {
    if (!clientFileId) return null
    const e = byClient.get(String(clientFileId))
    return e?.objectUrl ?? null
}

/** @returns {PreviewEntry|null} */
export function getUploadPreviewEntryForClient(clientFileId) {
    if (!clientFileId) return null
    return byClient.get(String(clientFileId)) ?? null
}

/** @returns {PreviewEntry|null} */
export function getUploadPreviewEntryForAsset(assetId) {
    const id = normalizeAssetId(assetId)
    if (!id) return null
    return byAsset.get(id) ?? null
}

/** @returns {string|null} */
export function getUploadPreviewForAsset(assetId) {
    return getUploadPreviewObjectUrlForAsset(assetId)
}

/** @returns {string|null} */
export function getUploadPreviewForClientFile(clientFileId) {
    return getUploadPreviewObjectUrlForClient(clientFileId)
}

export function markPendingFinalize(clientFileIds) {
    if (!Array.isArray(clientFileIds)) return
    for (const raw of clientFileIds) {
        const k = raw != null ? String(raw) : ''
        if (!k) continue
        if (!pendingFinalizeOrder.includes(k)) {
            pendingFinalizeOrder.push(k)
        }
        const entry = byClient.get(k)
        if (entry && entry.status !== 'failed') {
            entry.status = 'pending_finalize'
        }
    }
    bump()
}

export function removePendingFinalizeClient(clientFileId) {
    const k = clientFileId != null ? String(clientFileId) : ''
    if (!k) return
    const i = pendingFinalizeOrder.indexOf(k)
    if (i >= 0) {
        pendingFinalizeOrder.splice(i, 1)
        bump()
    }
}

export function clearAllPendingFinalize() {
    if (pendingFinalizeOrder.length === 0) return
    pendingFinalizeOrder.length = 0
    bump()
}

/**
 * Drop pending-queue entries once the corresponding asset row exists in the grid payload.
 * @param {Array<{ id?: number|string }>} assets
 */
export function prunePendingFinalizeVisibleInAssetList(assets) {
    const ids = new Set(
        (assets || [])
            .map((a) => normalizeAssetId(a?.id))
            .filter((id) => id != null),
    )
    let changed = false
    for (let i = pendingFinalizeOrder.length - 1; i >= 0; i--) {
        const cid = pendingFinalizeOrder[i]
        const entry = byClient.get(cid)
        const aid = entry?.assetId != null ? normalizeAssetId(entry.assetId) : null
        if (aid && ids.has(aid)) {
            pendingFinalizeOrder.splice(i, 1)
            changed = true
        }
    }
    if (changed) bump()
}

/** Stable snapshot for AssetGrid pending row subscription */
export function getPendingFinalizeSnapshot() {
    return `${globalVersion}\u0001${pendingFinalizeOrder.join('\u0002')}`
}

export function markUploadPreviewFailed(clientFileId, reason = null) {
    const k = clientFileId != null ? String(clientFileId) : ''
    if (!k) return
    const entry = byClient.get(k)
    if (entry) {
        entry.status = 'failed'
        entry.failReason = typeof reason === 'string' ? reason : reason != null ? String(reason) : null
    }
    removePendingFinalizeClient(k)
}

/** Alias: revoke by client id (and linked asset id if any) */
export function revokeUploadPreview(clientFileIdOrAssetId, mode = 'client') {
    if (mode === 'asset') {
        revokeUploadPreviewForAsset(clientFileIdOrAssetId)
        return
    }
    revokeClientUploadPreview(clientFileIdOrAssetId, true)
}

/** Stable snapshot for one queue row (re-renders only when this client’s URL or global version changes). */
export function getUploadPreviewSnapshotForClient(clientFileId) {
    const u = clientFileId ? (byClient.get(String(clientFileId))?.objectUrl ?? '') : ''
    return `${globalVersion}\u0001${u}`
}

export function revokeClientUploadPreview(clientFileId, shouldBump = true) {
    const key = clientFileId != null ? String(clientFileId) : ''
    const entry = key ? byClient.get(key) : null
    if (!entry) return
    try {
        URL.revokeObjectURL(entry.objectUrl)
    } catch (_) {
        /* ignore */
    }
    byClient.delete(key)
    if (entry.assetId != null) {
        byAsset.delete(entry.assetId)
    }
    if (shouldBump) bump()
}

export function revokeUploadPreviewForAsset(assetId) {
    const id = normalizeAssetId(assetId)
    if (!id) return
    const entry = byAsset.get(id)
    if (!entry) return
    try {
        URL.revokeObjectURL(entry.objectUrl)
    } catch (_) {
        /* ignore */
    }
    byAsset.delete(id)
    byClient.delete(entry.clientFileId)
    bump()
}

/**
 * When polling merges a durable server raster, drop the blob so we prefer server URLs.
 * Preview-only URLs alone do not revoke (keeps the blob as fallback while LQIP may still be missing or 404).
 * @param {object} asset
 */
export function revokeUploadPreviewIfServerRasterPresent(asset) {
    const id = normalizeAssetId(asset?.id)
    if (!id) return

    const tsRaw = asset.thumbnail_status?.value ?? asset.thumbnail_status ?? ''
    const ts = String(tsRaw).toLowerCase()
    const hasFinal = !!asset.final_thumbnail_url
    const hasCompletedLegacy =
        !!asset.thumbnail_url && (ts === 'completed' || asset.thumbnail_status?.value === 'completed')

    if (hasFinal || hasCompletedLegacy) {
        revokeUploadPreviewForAsset(id)
    }
}

/**
 * @param {number} maxAgeMs
 */
export function clearUploadPreviewsOlderThan(maxAgeMs) {
    const now = Date.now()
    for (const [cid, entry] of [...byClient.entries()]) {
        if (now - entry.createdAt > maxAgeMs) {
            revokeClientUploadPreview(cid, false)
        }
    }
    bump()
}

export const clearCompletedOlderThan = clearUploadPreviewsOlderThan

export function clearAllUploadPreviews() {
    for (const cid of [...byClient.keys()]) {
        revokeClientUploadPreview(cid, false)
    }
    bump()
}

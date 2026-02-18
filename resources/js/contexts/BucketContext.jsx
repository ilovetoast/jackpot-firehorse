/**
 * Download bucket state shared across app. Rendered outside the page component
 * so the bucket bar does not remount when category/URL changes (no flash).
 */
import { createContext, useContext, useState, useCallback, useEffect } from 'react'

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

const BucketContext = createContext(null)

export function BucketProvider({ children }) {
    const [bucketAssetIds, setBucketAssetIds] = useState([])

    const fetchBucket = useCallback(() => {
        if (typeof window === 'undefined' || !window.route) return
        fetch(route('download-bucket.items'), {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error('Failed to load bucket')))
            .then((data) => {
                const ids = (data.items || []).filter(Boolean).map((i) => (typeof i === 'string' ? i : i?.id)).filter(Boolean)
                setBucketAssetIds(ids)
            })
            .catch(() => setBucketAssetIds([]))
    }, [])

    useEffect(() => {
        fetchBucket()
    }, [fetchBucket])

    const applyBucketResponse = useCallback((data) => {
        const ids = (data?.items || []).filter(Boolean).map((i) => (typeof i === 'string' ? i : i?.id)).filter(Boolean)
        setBucketAssetIds(ids)
    }, [])

    const bucketAdd = useCallback((assetId) => {
        return fetch(route('download-bucket.add'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ asset_id: assetId }),
        })
            .then((r) => r.json().catch(() => ({})))
            .then((data) => {
                if (Array.isArray(data?.items) || typeof data?.count === 'number') applyBucketResponse(data)
                return data
            })
    }, [applyBucketResponse])

    const bucketRemove = useCallback((assetId) => {
        return fetch(route('download-bucket.remove', { asset: assetId }), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then(applyBucketResponse)
    }, [applyBucketResponse])

    const bucketClear = useCallback(() => {
        return fetch(route('download-bucket.clear'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then(applyBucketResponse)
    }, [applyBucketResponse])

    const bucketAddBatch = useCallback((assetIds) => {
        if (!assetIds?.length) return Promise.resolve()
        return fetch(route('download-bucket.add_batch'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ asset_ids: assetIds }),
        })
            .then((r) => r.json())
            .then(applyBucketResponse)
    }, [applyBucketResponse])

    const clearIfEmpty = useCallback((serverCount) => {
        if (typeof serverCount === 'number' && serverCount === 0) {
            setBucketAssetIds([])
        }
    }, [])

    const value = {
        bucketAssetIds,
        bucketAdd,
        bucketRemove,
        bucketClear,
        bucketAddBatch,
        applyBucketResponse,
        clearIfEmpty,
    }

    return (
        <BucketContext.Provider value={value}>
            {children}
        </BucketContext.Provider>
    )
}

export function useBucket() {
    const ctx = useContext(BucketContext)
    if (!ctx) {
        throw new Error('useBucket must be used within BucketProvider')
    }
    return ctx
}

export function useBucketOptional() {
    return useContext(BucketContext)
}

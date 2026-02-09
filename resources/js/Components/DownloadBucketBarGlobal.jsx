/**
 * App-level download bucket bar. Uses BucketContext so it does not remount
 * when the page (e.g. Assets/Deliverables) changes category or URL.
 */
import { useBucketOptional } from '../contexts/BucketContext'
import { usePage } from '@inertiajs/react'
import DownloadBucketBar from './DownloadBucketBar'

export default function DownloadBucketBarGlobal() {
    const bucket = useBucketOptional()
    const { auth } = usePage().props

    if (!bucket) return null

    const { bucketAssetIds, bucketRemove, bucketClear } = bucket
    const primaryColor = auth?.activeBrand?.primary_color || undefined

    return (
        <DownloadBucketBar
            bucketCount={bucketAssetIds.length}
            onRemove={bucketRemove}
            onClear={bucketClear}
            primaryColor={primaryColor}
        />
    )
}

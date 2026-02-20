/**
 * App-level download bucket bar. Phase 3: SelectionContext is the single source of truth.
 * Bar derives items from SelectionContext; remove/clear update SelectionContext.
 * Create Download syncs SelectionContext to backend bucket before opening (backend reads from bucket).
 */
import { useCallback } from 'react'
import { useSelectionOptional } from '../contexts/SelectionContext'
import { useBucketOptional } from '../contexts/BucketContext'
import { usePage } from '@inertiajs/react'
import DownloadBucketBar from './DownloadBucketBar'

export default function DownloadBucketBarGlobal() {
    const selection = useSelectionOptional()
    const bucket = useBucketOptional()
    const { auth } = usePage().props

    if (!selection) return null

    const { selectedItems, selectedCount, deselectItem, clearSelection, getSelectedIds } = selection
    const primaryColor = auth?.activeBrand?.primary_color || undefined

    // Map SelectedItem to bucket preview format (id, name, thumbnail_url)
    const items = selectedItems.map((item) => ({
        id: item.id,
        original_filename: item.name,
        title: item.name,
        thumbnail_url: item.thumbnail_url,
        final_thumbnail_url: item.thumbnail_url,
        preview_thumbnail_url: item.thumbnail_url,
    }))

    // Sync SelectionContext to backend bucket before opening Create Download (backend reads from bucket)
    const onBeforeCreateDownloadClick = useCallback(async () => {
        if (!bucket) return
        const ids = getSelectedIds()
        await bucket.bucketClear()
        if (ids.length > 0) {
            await bucket.bucketAddBatch(ids)
        }
    }, [bucket, getSelectedIds])

    return (
        <DownloadBucketBar
            bucketCount={selectedCount}
            items={items}
            onRemove={deselectItem}
            onClear={clearSelection}
        onBeforeCreateDownloadClick={onBeforeCreateDownloadClick}
        onCreateSuccess={clearSelection}
        primaryColor={primaryColor}
        />
    )
}

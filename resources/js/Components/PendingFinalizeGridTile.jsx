/**
 * Temporary grid tile shown while finalize is in flight or before the new asset row appears.
 * Uses the same session blob registry as AssetCard; non-previewable types show a processing placeholder.
 */
import { useMemo, useSyncExternalStore } from 'react'
import { usePage } from '@inertiajs/react'
import ThumbnailPreview from './ThumbnailPreview'
import {
    getUploadPreviewEntryForClient,
    getUploadPreviewSnapshotForClient,
    subscribeUploadPreviewRegistry,
} from '../utils/uploadPreviewRegistry'

function syntheticAssetForPending(entry, clientFileId) {
    const name = entry?.filename || 'Asset'
    return {
        // Stable per-upload id so branded placeholder hue/jitter differs per tile (never use 0 for all).
        id: clientFileId ? String(clientFileId) : name,
        title: name,
        original_filename: name,
        mime_type: entry?.mimeType || 'application/octet-stream',
        file_extension: name.includes('.') ? name.split('.').pop() : '',
        thumbnail_status: 'pending',
        preview_thumbnail_url: null,
        final_thumbnail_url: null,
        thumbnail_url: null,
    }
}

export default function PendingFinalizeGridTile({
    clientFileId,
    primaryColor = '#6366f1',
    cardStyle = 'default',
    cardSize = 220,
    layoutMode = 'grid',
    masonryMaxHeightPx = 560,
}) {
    const { auth } = usePage().props
    const chromePrimary = auth?.activeBrand?.primary_color || primaryColor

    const snapshot = useSyncExternalStore(
        subscribeUploadPreviewRegistry,
        () => getUploadPreviewSnapshotForClient(clientFileId),
        () => getUploadPreviewSnapshotForClient(clientFileId),
    )
    const entry = useMemo(() => getUploadPreviewEntryForClient(clientFileId), [clientFileId, snapshot])

    const ephemeralUrl = useMemo(() => {
        const sep = '\u0001'
        const i = snapshot.indexOf(sep)
        if (i < 0) return null
        const url = snapshot.slice(i + sep.length)
        return url.length > 0 ? url : null
    }, [snapshot])

    const synthetic = useMemo(() => syntheticAssetForPending(entry, clientFileId), [entry, clientFileId])

    const isGuidelines = cardStyle === 'guidelines'
    const aspectRatio = isGuidelines ? 'aspect-[5/3]' : 'aspect-[4/3]'
    const isMasonry = layoutMode === 'masonry'
    const masonryThumbnailMinHeightPx = useMemo(() => {
        if (!isMasonry) return undefined
        const w = Math.max(160, Math.min(600, Number(cardSize) || 220))
        return isGuidelines ? Math.round((w * 3) / 5) : Math.round((w * 3) / 4)
    }, [isMasonry, isGuidelines, cardSize])

    const statusLabel = ephemeralUrl ? 'Processing preview' : 'Creating asset'

    return (
        <div
            className="group relative select-none rounded-2xl flex flex-col overflow-visible cursor-default"
            style={{ '--primary-color': chromePrimary }}
            data-pending-finalize-tile
            data-client-file-id={clientFileId}
            aria-label={statusLabel}
        >
            <div
                className={`${
                    isMasonry ? 'w-full flex flex-col items-center justify-center' : aspectRatio
                } relative overflow-hidden rounded-2xl border border-dashed border-gray-300 bg-gray-50 transition-all duration-200`}
                style={
                    isMasonry
                        ? {
                              maxHeight: masonryMaxHeightPx,
                              minHeight: masonryThumbnailMinHeightPx,
                          }
                        : undefined
                }
            >
                <ThumbnailPreview
                    asset={synthetic}
                    alt={entry?.filename || 'Upload'}
                    className={isMasonry ? 'w-full max-h-full min-h-0' : 'w-full h-full'}
                    thumbnailVersion={null}
                    shouldAnimateThumbnail={false}
                    primaryColor={primaryColor}
                    masonryMaxHeight={isMasonry ? masonryMaxHeightPx : null}
                    masonryMinHeight={isMasonry ? masonryThumbnailMinHeightPx : null}
                    ephemeralLocalPreviewUrl={ephemeralUrl}
                />
                {!ephemeralUrl ? (
                    <span
                        className="pointer-events-none absolute bottom-1.5 left-1/2 z-10 w-[calc(100%-1rem)] -translate-x-1/2 truncate rounded bg-black/55 px-2 py-0.5 text-center text-[10px] font-medium text-white shadow-sm"
                        title="Creating asset on server"
                    >
                        Creating asset…
                    </span>
                ) : null}
            </div>
            {entry?.filename ? (
                <p className="mt-2 line-clamp-2 px-0.5 text-xs text-gray-600" title={entry.filename}>
                    {entry.filename}
                </p>
            ) : null}
        </div>
    )
}

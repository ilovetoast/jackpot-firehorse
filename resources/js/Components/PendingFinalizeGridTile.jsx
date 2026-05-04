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

function inferMimeFromFilenameForPending(name) {
    const ext = String(name || '')
        .split('.')
        .pop()
        ?.toLowerCase()
        .replace(/^\./, '')
    if (!ext) return null
    const map = {
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        png: 'image/png',
        gif: 'image/gif',
        webp: 'image/webp',
        avif: 'image/avif',
        heic: 'image/heic',
        tif: 'image/tiff',
        tiff: 'image/tiff',
        bmp: 'image/bmp',
        svg: 'image/svg+xml',
        pdf: 'application/pdf',
        mp4: 'video/mp4',
        mov: 'video/quicktime',
        webm: 'video/webm',
        mkv: 'video/x-matroska',
        m4v: 'video/x-m4v',
        avi: 'video/x-msvideo',
    }
    return map[ext] ?? null
}

function syntheticAssetForPending(entry, clientFileId) {
    const name = entry?.filename || 'Asset'
    const rawMime = (entry?.mimeType || '').trim().toLowerCase()
    const mimeFromEntry =
        rawMime && rawMime !== 'application/octet-stream' ? entry.mimeType : inferMimeFromFilenameForPending(name)
    const extFromName = name.includes('.') ? String(name.split('.').pop() || '').replace(/^\./, '') : ''
    return {
        // Stable per-upload id so branded placeholder hue/jitter differs per tile (never use 0 for all).
        id: clientFileId ? String(clientFileId) : name,
        title: name,
        original_filename: name,
        mime_type: mimeFromEntry || entry?.mimeType || 'application/octet-stream',
        file_extension: extFromName,
        /** Lets ThumbnailPreview + getAssetCardVisualState show animated mosaic while finalize runs (unknown MIME). */
        pending_finalize_client_tile: true,
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
    cardVariant = 'default',
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
    const isCinematic = cardVariant === 'cinematic'
    const aspectRatio = isGuidelines ? 'aspect-[5/3]' : 'aspect-[4/3]'
    const isMasonry = layoutMode === 'masonry'
    const masonryThumbnailMinHeightPx = useMemo(() => {
        if (!isMasonry) return undefined
        const w = Math.max(160, Math.min(600, Number(cardSize) || 220))
        return isGuidelines ? Math.round((w * 3) / 5) : Math.round((w * 3) / 4)
    }, [isMasonry, isGuidelines, cardSize])

    /** Tile chrome + ThumbnailPreview already show status copy — avoid a second “Creating asset…” chip. */
    const statusLabel = ephemeralUrl ? 'Processing preview' : 'Processing upload'

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
                } relative overflow-hidden rounded-2xl border border-dashed transition-all duration-200 ${
                    isCinematic ? 'border-white/25 bg-black/25' : 'border-gray-300 bg-gray-50'
                }`}
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
            </div>
            {entry?.filename ? (
                <p
                    className={`mt-2 line-clamp-2 px-0.5 text-xs ${isCinematic ? 'text-white/85' : 'text-gray-600'}`}
                    title={entry.filename}
                >
                    {entry.filename}
                </p>
            ) : null}
        </div>
    )
}

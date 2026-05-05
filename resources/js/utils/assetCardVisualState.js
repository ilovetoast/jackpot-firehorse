/**
 * Normalized grid/drawer presentation for assets without a final server thumbnail yet.
 * Does not use local object URLs — pass ephemeral blob URL via options when needed.
 */

import { originalImageGridFallbackUrl } from './originalImageGridFallbackUrl.js'
import { getThumbnailState, supportsThumbnail } from './thumbnailUtils.js'

const RAW_EXTENSIONS = new Set([
    'cr2',
    'cr3',
    'nef',
    'arw',
    'raf',
    'orf',
    'rw2',
    'dng',
    'srw',
    'pef',
    '3fr',
    'fff',
    'x3f',
    'raw',
])

const DESIGN_EXTENSIONS = new Set(['psd', 'psb', 'ai', 'eps'])

function normalizeThumbStatus(asset) {
    return String(asset?.thumbnail_status?.value ?? asset?.thumbnail_status ?? '').toLowerCase()
}

function extensionOf(asset) {
    return (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

export function hasServerRasterThumbnail(asset) {
    if (!asset) return false
    if (asset.final_thumbnail_url || asset.preview_thumbnail_url) return true
    const ts = normalizeThumbStatus(asset)
    if (asset.thumbnail_url && ts === 'completed') return true
    if (originalImageGridFallbackUrl(asset)) return true
    return false
}

function mimeIsRawLike(mime) {
    const m = (mime || '').toLowerCase()
    if (!m.startsWith('image/')) return false
    return (
        m.includes('raw') ||
        m.includes('x-canon-cr') ||
        m.includes('x-nikon-nef') ||
        m.includes('x-sony-arw') ||
        m.includes('x-adobe-dng') ||
        m.includes('x-panasonic-raw')
    )
}

function isRawAsset(asset) {
    const ext = extensionOf(asset)
    return RAW_EXTENSIONS.has(ext) || mimeIsRawLike(asset?.mime_type)
}

function isPdfAsset(asset) {
    const ext = extensionOf(asset)
    const m = (asset?.mime_type || '').toLowerCase()
    return ext === 'pdf' || m === 'application/pdf'
}

function isDesignAsset(asset) {
    const ext = extensionOf(asset)
    const m = (asset?.mime_type || '').toLowerCase()
    if (DESIGN_EXTENSIONS.has(ext)) return true
    return m.includes('photoshop') || m.includes('illustrator') || m.includes('postscript')
}

function isVideoAsset(asset) {
    const ext = extensionOf(asset)
    const m = (asset?.mime_type || '').toLowerCase()
    if (m.startsWith('video/')) return true
    return ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'].includes(ext)
}

function hasVideoPoster(asset) {
    return !!(asset?.final_thumbnail_url || asset?.preview_thumbnail_url)
}

/**
 * @typedef {'ready'|'local_preview'|'generating_preview'|'raw_processing'|'document_processing'|'video_processing'|'preview_unavailable'|'failed'|'unknown_processing'} AssetCardVisualKind
 * @typedef {'neutral'|'processing'|'warning'|'danger'} AssetCardBadgeTone
 * @returns {{
 *   kind: AssetCardVisualKind,
 *   label: string,
 *   description: string,
 *   showThumbnail: boolean,
 *   showLocalPreview: boolean,
 *   showFileTypeCard: boolean,
 *   badgeTone: AssetCardBadgeTone,
 *   badgeShort: string,
 *   extensionLabel: string,
 * }}
 */
export function getAssetCardVisualState(asset, options = {}) {
    const ephemeral =
        typeof options.ephemeralLocalPreviewUrl === 'string' && options.ephemeralLocalPreviewUrl.length > 0
            ? options.ephemeralLocalPreviewUrl
            : null

    const empty = {
        kind: 'unknown_processing',
        label: 'Processing',
        description: 'Preview will appear when processing finishes.',
        showThumbnail: false,
        showLocalPreview: false,
        showFileTypeCard: true,
        badgeTone: 'processing',
        badgeShort: '…',
        extensionLabel: 'FILE',
    }

    if (!asset?.id) {
        return empty
    }

    const ext = extensionOf(asset)
    const extUpper = ext ? ext.toUpperCase() : 'FILE'
    const mime = (asset.mime_type || '').toLowerCase()
    const ts = normalizeThumbStatus(asset)
    const meta = asset.metadata || {}
    const previewMsg = asset.preview_unavailable_user_message || meta.preview_unavailable_user_message
    const timedOut = meta.thumbnail_timeout === true || meta.thumbnail_timeout === 'true'
    const processingFailed = meta.processing_failed === true || meta.processing_failed === 'true'

    if (ephemeral && !asset.final_thumbnail_url) {
        return {
            kind: 'local_preview',
            label: 'Local preview',
            description: 'Shown until the library thumbnail is ready.',
            showThumbnail: false,
            showLocalPreview: true,
            showFileTypeCard: false,
            badgeTone: 'processing',
            badgeShort: 'Local',
            extensionLabel: extUpper,
        }
    }

    if (hasServerRasterThumbnail(asset)) {
        return {
            kind: 'ready',
            label: '',
            description: '',
            showThumbnail: true,
            showLocalPreview: false,
            showFileTypeCard: false,
            badgeTone: 'neutral',
            badgeShort: '',
            extensionLabel: extUpper,
        }
    }

    if (ts === 'failed' || processingFailed) {
        return {
            kind: 'failed',
            label: 'Preview failed',
            description: 'The original file is still available.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'danger',
            badgeShort: 'Failed',
            extensionLabel: extUpper,
        }
    }

    // Terminal skip: pipeline finished but raster thumbnails were skipped (e.g. dimensions_unknown).
    // analysis_status can lag until reconciliation — avoid infinite "Generating preview" in the grid.
    if (
        meta.pipeline_completed_at &&
        (meta.thumbnail_skip_reason || meta.preview_skipped) &&
        !hasServerRasterThumbnail(asset)
    ) {
        return {
            kind: 'preview_unavailable',
            label: 'No grid preview',
            description:
                meta.thumbnail_skip_message ||
                meta.preview_skipped_reason ||
                'Thumbnail step was skipped after the pipeline finalized.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'warning',
            badgeShort: 'Unavailable',
            extensionLabel: extUpper,
        }
    }

    if (previewMsg || (timedOut && !hasServerRasterThumbnail(asset))) {
        return {
            kind: 'preview_unavailable',
            label: 'Preview unavailable',
            description: previewMsg
                ? String(previewMsg).slice(0, 160) + (String(previewMsg).length > 160 ? '…' : '')
                : 'Original file is saved. This type may not get a grid thumbnail.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'warning',
            badgeShort: 'Unavailable',
            extensionLabel: extUpper,
        }
    }

    const thumbSupported = supportsThumbnail(asset.mime_type, ext)

    if ((!thumbSupported && !asset.pending_finalize_client_tile) || ts === 'skipped') {
        return {
            kind: 'preview_unavailable',
            label: 'No grid preview',
            description: 'This file type does not get an automatic library thumbnail.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'neutral',
            badgeShort: '',
            extensionLabel: extUpper,
        }
    }

    if (isVideoAsset(asset) && !hasVideoPoster(asset)) {
        return {
            kind: 'video_processing',
            label: 'Video',
            description: 'Poster or preview is still generating.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'processing',
            badgeShort: 'Video',
            extensionLabel: extUpper,
        }
    }

    if (isPdfAsset(asset) && (ts === 'pending' || ts === 'processing' || ts === '')) {
        return {
            kind: 'document_processing',
            label: 'PDF',
            description: 'First-page preview is processing…',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'processing',
            badgeShort: 'PDF',
            extensionLabel: extUpper,
        }
    }

    if (isDesignAsset(asset) && (ts === 'pending' || ts === 'processing' || ts === '')) {
        return {
            kind: 'document_processing',
            label: 'Preview processing',
            description: 'Large design files can take a little longer.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'processing',
            badgeShort: 'PSD',
            extensionLabel: extUpper,
        }
    }

    if (isRawAsset(asset) && (ts === 'pending' || ts === 'processing' || ts === '')) {
        return {
            kind: 'raw_processing',
            label: 'RAW preview',
            description: 'RAW files may take longer to process.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'processing',
            badgeShort: 'RAW',
            extensionLabel: extUpper,
        }
    }

    if (ts === 'pending' || ts === 'processing' || ts === '') {
        return {
            kind: 'generating_preview',
            label: 'Generating preview',
            description: 'Usually under a minute.',
            showThumbnail: false,
            showLocalPreview: false,
            showFileTypeCard: true,
            badgeTone: 'processing',
            badgeShort: 'Processing',
            extensionLabel: extUpper,
        }
    }

    return {
        kind: 'unknown_processing',
        label: 'Processing',
        description: 'Preview will appear when processing finishes.',
        showThumbnail: false,
        showLocalPreview: false,
        showFileTypeCard: true,
        badgeTone: 'processing',
        badgeShort: 'Processing',
        extensionLabel: extUpper,
    }
}

/**
 * Grid thumbnail poll target — assets still waiting on a server raster (preview or final).
 * Matches prior smart-poll rules: supported type, pending/processing, no terminal error.
 */
export function assetThumbnailPollEligible(asset) {
    if (!asset?.id) return false
    if (asset.final_thumbnail_url) return false
    const meta = asset.metadata || {}
    if (meta.pipeline_completed_at && (meta.thumbnail_skip_reason || meta.preview_skipped)) {
        return false
    }
    const { state } = getThumbnailState(asset)
    if (state === 'NOT_SUPPORTED') return false
    if (asset.thumbnail_error) return false
    const ts = normalizeThumbStatus(asset)
    if (ts === 'failed' || ts === 'skipped') return false
    const pendingish = ts === 'pending' || ts === 'processing' || ts === ''
    if (!pendingish) return false
    const ext = extensionOf(asset)
    if (!supportsThumbnail(asset.mime_type, ext)) return false
    return true
}

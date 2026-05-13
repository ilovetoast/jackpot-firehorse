/**
 * Headlines and badges for {@link AssetProcessingPlaceholder} (grid + drawer).
 * Does not replace {@link getAssetCardVisualState} — only presentation strings.
 */

function extLower(asset) {
    return (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

function extensionBadgeFromAsset(asset) {
    const raw = asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || ''
    const ext = String(raw)
        .replace(/^\./, '')
        .trim()
    return ext ? ext.toUpperCase() : 'FILE'
}

function isPdfLike(asset) {
    const e = extLower(asset)
    const m = (asset?.mime_type || '').toLowerCase()
    return e === 'pdf' || m === 'application/pdf'
}

/** Mosaic / grid status line for generic in-flight preview (not raw/doc/video-specific visual states). */
function defaultProcessingHeadline(asset) {
    const ext = extLower(asset)
    const m = (asset?.mime_type || '').toLowerCase()
    const rawish = ['cr2', 'cr3', 'nef', 'arw', 'raf', 'orf', 'rw2', 'dng', 'raw'].includes(ext)
    if (rawish || m.includes('x-raw') || m === 'image/x-adobe-dng') {
        return 'RAW preview processing'
    }
    if (
        ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'avif', 'tif', 'tiff', 'bmp', 'svg'].includes(ext) ||
        (m.startsWith('image/') && !m.includes('raw'))
    ) {
        return 'Generating preview'
    }
    if (
        ext === 'pdf' ||
        m === 'application/pdf' ||
        ['psd', 'psb', 'ai', 'eps'].includes(ext) ||
        m.startsWith('video/')
    ) {
        return 'Preview processing'
    }
    return 'Generating preview'
}

/**
 * @param {object} visualState — from {@link getAssetCardVisualState}
 * @param {'processing'|'failed'|'unavailable'|'skipped'|'default'|null|undefined} placeholderHint
 */
export function getAssetProcessingPlaceholderCopy(asset, visualState, placeholderHint = null) {
    const ext = extLower(asset)
    const extUp = extensionBadgeFromAsset(asset)
    const errMsg =
        typeof asset?.thumbnail_error === 'string' && asset.thumbnail_error.trim()
            ? asset.thumbnail_error.trim()
            : typeof asset?.metadata?.thumbnail_error === 'string' && asset.metadata.thumbnail_error.trim()
              ? asset.metadata.thumbnail_error.trim()
              : ''

    if (visualState.kind === 'failed') {
        const headline = placeholderHint === 'failed' ? 'Preview failed' : visualState.label
        return {
            headline,
            helper: errMsg ? errMsg.slice(0, 120) + (errMsg.length > 120 ? '…' : '') : visualState.description,
            badgeShort: 'Failed',
            badgeTitle: errMsg ? errMsg.slice(0, 200) : `${visualState.label}. ${visualState.description}`,
            badgeTone: 'danger',
            animate: false,
            typeMark: '',
            showFaintTypeWatermark: false,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'model_3d_stub_raster') {
        return {
            headline: visualState.label,
            helper: visualState.description,
            badgeShort: visualState.badgeShort || '3D',
            badgeTitle: `${visualState.label}. ${visualState.description}`,
            badgeTone: visualState.badgeTone === 'warning' ? 'warning' : 'neutral',
            animate: false,
            typeMark: extUp,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'preview_unavailable') {
        return {
            headline: visualState.label,
            helper: visualState.description,
            badgeShort: visualState.badgeShort || 'Unavailable',
            badgeTitle: `${visualState.label}. ${visualState.description}`,
            badgeTone: visualState.badgeTone === 'warning' ? 'warning' : 'neutral',
            animate: false,
            typeMark: extUp,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'unknown_processing') {
        return {
            headline: defaultProcessingHeadline(asset),
            helper: visualState.description,
            badgeShort: '',
            badgeTitle: `${visualState.label}. ${visualState.description}`,
            badgeTone: 'processing',
            animate: true,
            typeMark: extUp,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'raw_processing') {
        return {
            headline: 'RAW preview processing',
            helper: 'RAW files may take longer',
            // Grid cards already show a bottom status chip from getAssetCardVisualState — avoid duplicate corner pill.
            badgeShort: '',
            badgeTitle: 'RAW file — preview is still generating',
            badgeTone: 'processing',
            animate: true,
            typeMark: extUp,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'document_processing') {
        if (isPdfLike(asset)) {
            return {
                headline: 'Preview processing',
                helper: 'First-page preview is still generating',
                badgeShort: '',
                badgeTitle: 'PDF — first-page preview is still generating',
                badgeTone: 'processing',
                animate: true,
                typeMark: 'PDF',
                showFaintTypeWatermark: true,
                videoPlaySlot: false,
            }
        }
        const designLabel = ['psb', 'ai', 'eps'].includes(ext) ? extUp : 'PSD'
        return {
            headline: 'Preview processing',
            helper: visualState.description || 'Large design files can take a little longer.',
            badgeShort: '',
            badgeTitle: 'Design file — preview is still generating',
            badgeTone: 'processing',
            animate: true,
            typeMark: designLabel,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    if (visualState.kind === 'video_processing') {
        return {
            headline: 'Preview processing',
            helper: visualState.description,
            badgeShort: '',
            badgeTitle: `${visualState.label}. ${visualState.description}`,
            badgeTone: 'processing',
            animate: true,
            typeMark: '',
            showFaintTypeWatermark: false,
            videoPlaySlot: true,
        }
    }

    if (visualState.kind === 'local_preview') {
        return {
            headline: visualState.label,
            helper: visualState.description,
            badgeShort: 'Local',
            badgeTitle: 'Shown from your browser until the library thumbnail is ready',
            badgeTone: 'processing',
            animate: true,
            typeMark: extUp,
            showFaintTypeWatermark: true,
            videoPlaySlot: false,
        }
    }

    return {
        headline: defaultProcessingHeadline(asset),
        helper: visualState.description || 'Usually under a minute',
        badgeShort: '',
        badgeTitle: 'Thumbnail is still generating',
        badgeTone: 'processing',
        animate: true,
        typeMark: extUp,
        showFaintTypeWatermark: true,
        videoPlaySlot: false,
    }
}

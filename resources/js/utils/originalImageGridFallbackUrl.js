/**
 * When preview/final derivative URLs are missing but the asset is a normal web-displayable
 * image, use the authenticated `original` URL so the grid does not sit on "Generating preview"
 * forever (pipeline lag, missing LQIP, or orphaned jobs).
 *
 * Excludes RAW, HEIC/HEIF (often no browser decode), SVG (handled separately), and non-images.
 */
const RAW_EXT = new Set([
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

function extensionOf(asset) {
    return (asset?.file_extension || asset?.original_filename?.split?.('.')?.pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
}

export function originalImageGridFallbackUrl(asset) {
    if (!asset?.original || typeof asset.original !== 'string' || !asset.original.trim()) {
        return null
    }
    const mime = (asset.mime_type || '').toLowerCase()
    if (!mime.startsWith('image/')) {
        return null
    }
    if (mime === 'image/svg+xml') {
        return null
    }
    if (mime.includes('raw') || mime.includes('x-adobe-dng') || mime.includes('x-canon-cr') || mime.includes('x-nikon-nef')) {
        return null
    }
    const ext = extensionOf(asset)
    if (RAW_EXT.has(ext)) {
        return null
    }
    if (ext === 'heic' || ext === 'heif') {
        return null
    }
    return asset.original
}

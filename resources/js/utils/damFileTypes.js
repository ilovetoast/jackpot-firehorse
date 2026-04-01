/**
 * DAM file type hints from the Laravel registry (config/file_types.php → FileTypeService),
 * shared on every Inertia response as `dam_file_types`.
 *
 * Synced from BrandThemeProvider on each page load so plain JS helpers (thumbnailUtils, etc.)
 * stay aligned with the backend without duplicating extension/MIME lists.
 */

/**
 * @typedef {{
 *   thumbnail_mime_types: string[],
 *   thumbnail_extensions: string[],
 *   upload_mime_types: string[],
 *   upload_extensions: string[],
 *   upload_accept: string,
 *   thumbnail_accept: string
 * }} DamFileTypesPayload
 */

/** @type {DamFileTypesPayload | null} */
let cached = null

const FALLBACK_THUMB_MIMES = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/tiff',
    'image/tif',
    'image/x-canon-cr2',
    'image/avif',
    'image/vnd.adobe.photoshop',
    'image/svg+xml',
    'application/pdf',
    'application/postscript',
    'application/vnd.adobe.illustrator',
    'application/illustrator',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/msword',
    'application/vnd.ms-excel',
    'application/vnd.ms-powerpoint',
    'video/mp4',
    'video/quicktime',
    'video/x-msvideo',
    'video/x-matroska',
    'video/webm',
    'video/x-m4v',
]

const FALLBACK_THUMB_EXTS = [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'tiff',
    'tif',
    'cr2',
    'avif',
    'psd',
    'psb',
    'svg',
    'pdf',
    'ai',
    'eps',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'mp4',
    'mov',
    'avi',
    'mkv',
    'webm',
    'm4v',
]

function fallbackPayload() {
    const uploadMimes = [...FALLBACK_THUMB_MIMES]
    const uploadExts = [...FALLBACK_THUMB_EXTS]
    const mimePart = uploadMimes.join(',')
    const extPart = uploadExts.map((e) => `.${e}`).join(',')
    return {
        thumbnail_mime_types: [...FALLBACK_THUMB_MIMES],
        thumbnail_extensions: [...FALLBACK_THUMB_EXTS],
        upload_mime_types: uploadMimes,
        upload_extensions: uploadExts,
        upload_accept: `${mimePart},${extPart}`,
        thumbnail_accept: `${mimePart},${extPart}`,
    }
}

/**
 * @param {object|null|undefined} initialPage Inertia initial page: { props?: { dam_file_types?: object } }
 */
export function syncDamFileTypesFromPage(initialPage) {
    const next = initialPage?.props?.dam_file_types
    if (next && typeof next === 'object' && Array.isArray(next.thumbnail_mime_types)) {
        cached = next
        return
    }
    if (!cached) {
        cached = fallbackPayload()
    }
}

export function getDamFileTypes() {
    return cached ?? fallbackPayload()
}

export function getThumbnailMimeTypes() {
    return getDamFileTypes().thumbnail_mime_types
}

export function getThumbnailExtensions() {
    return getDamFileTypes().thumbnail_extensions
}

export function getUploadAcceptAttribute() {
    return getDamFileTypes().upload_accept || ''
}

export function getThumbnailAcceptAttribute() {
    return getDamFileTypes().thumbnail_accept || ''
}

/**
 * Whether MIME or extension matches a DAM-registered upload type (full registry).
 */
export function isDamRegisteredUploadType(mimeType, fileExtension) {
    const cfg = getDamFileTypes()
    const m = mimeType ? String(mimeType).toLowerCase() : ''
    const e = fileExtension ? String(fileExtension).toLowerCase().replace(/^\./, '') : ''
    if (m && cfg.upload_mime_types.includes(m)) {
        return true
    }
    if (e && cfg.upload_extensions.includes(e)) {
        return true
    }
    return false
}

/** Extensions that are not raster/inline images (for logo / image cropper pickers). */
const NON_INLINE_IMAGE_EXTENSIONS = new Set([
    'pdf',
    'psd',
    'psb',
    'ai',
    'eps',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'mp4',
    'mov',
    'avi',
    'mkv',
    'webm',
    'm4v',
])

/**
 * `accept` string for pickers that should only offer images (not PDF/video/office), still driven by the DAM registry.
 */
/** Document / video / vector upload types — not “image tile” in grid chrome (see AssetCard). */
const ASSET_CARD_NON_IMAGE_EXTENSIONS = new Set([
    'pdf',
    'ai',
    'eps',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'ppt',
    'pptx',
    'mp4',
    'mov',
    'avi',
    'mkv',
    'webm',
    'm4v',
])

/**
 * Grid/card “image” styling: true for raster/image pipeline types, false for PDF/office/video even if thumbnailed.
 */
export function isImageLikeForAssetCard(mimeType, fileExtension) {
    const m = mimeType ? String(mimeType).toLowerCase() : ''
    const e = fileExtension ? String(fileExtension).toLowerCase().replace(/^\./, '') : ''
    if (m.startsWith('image/')) {
        return true
    }
    if (!e || !getThumbnailExtensions().includes(e)) {
        return false
    }
    return !ASSET_CARD_NON_IMAGE_EXTENSIONS.has(e)
}

export function getInlineImagePickerAccept() {
    const cfg = getDamFileTypes()
    const mimes = cfg.thumbnail_mime_types.filter((m) => String(m).toLowerCase().startsWith('image/'))
    const exts = cfg.thumbnail_extensions.filter((e) => !NON_INLINE_IMAGE_EXTENSIONS.has(String(e).toLowerCase()))
    const ms = [...new Set(mimes.map((x) => String(x).toLowerCase()))].sort()
    const es = [...new Set(exts.map((x) => String(x).toLowerCase()))].sort()
    return [...ms, ...es.map((e) => `.${e}`)].join(',')
}

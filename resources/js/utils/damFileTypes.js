/**
 * DAM file type hints from the Laravel registry (config/file_types.php → FileTypeService),
 * shared on every Inertia response as `dam_file_types`.
 *
 * Synced from BrandThemeProvider on each page load so plain JS helpers (thumbnailUtils, etc.)
 * stay aligned with the backend without duplicating extension/MIME lists.
 *
 * IMPORTANT — fail closed:
 *   This module DOES NOT carry hardcoded fallback lists of allowed types. If
 *   the Inertia prop is missing the helpers below return empty arrays, which
 *   causes the upload UI to refuse every type until the registry arrives.
 *   This is intentional: a fail-open fallback is what created the historical
 *   drift between front and back end and is the wrong posture for an
 *   enterprise DAM. Always rely on `dam_file_types` from the server.
 */

/**
 * @typedef {{
 *   thumbnail_mime_types: string[],
 *   thumbnail_extensions: string[],
 *   upload_mime_types: string[],
 *   upload_extensions: string[],
 *   upload_accept: string,
 *   thumbnail_accept: string,
 *   blocked_extensions?: string[],
 *   blocked_mime_types?: string[],
 *   blocked_groups?: Record<string, { extensions: string[], mime_types: string[], message: string, code_suffix: string }>,
 *   coming_soon?: Record<string, { name: string, message: string, extensions: string[] }>
 * }} DamFileTypesPayload
 */

/** @type {DamFileTypesPayload | null} */
let cached = null

/** Empty (fail-closed) payload. */
function emptyPayload() {
    return {
        thumbnail_mime_types: [],
        thumbnail_extensions: [],
        upload_mime_types: [],
        upload_extensions: [],
        upload_accept: '',
        thumbnail_accept: '',
        blocked_extensions: [],
        blocked_mime_types: [],
        blocked_groups: {},
        coming_soon: {},
        types_for_help: [],
    }
}

/**
 * @param {object|null|undefined} initialPage Inertia initial page: { props?: { dam_file_types?: object } }
 */
export function syncDamFileTypesFromPage(initialPage) {
    const next = initialPage?.props?.dam_file_types
    if (next && typeof next === 'object' && Array.isArray(next.thumbnail_mime_types)) {
        cached = {
            ...emptyPayload(),
            ...next,
        }
        return
    }
    if (!cached) {
        cached = emptyPayload()
    }
}

export function getDamFileTypes() {
    return cached ?? emptyPayload()
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
 * Per-type summary suitable for help / settings UI: name, description,
 * extensions, status, capabilities, max size. Driven entirely by the
 * server registry — no hardcoded list here, so adding a type to
 * config/file_types.php automatically surfaces it in the help panel.
 *
 * @returns {Array<{
 *   key: string,
 *   name: string,
 *   description: string,
 *   extensions: string[],
 *   status: 'enabled' | 'coming_soon' | 'disabled' | string,
 *   enabled: boolean,
 *   disabled_message: string | null,
 *   max_size_bytes: number | null,
 *   capabilities: {
 *     preview: boolean,
 *     thumbnail: boolean,
 *     ai_analysis: boolean,
 *     download_only: boolean,
 *   },
 * }>}
 */
export function getRegisteredTypesForHelp() {
    const cfg = getDamFileTypes()
    return Array.isArray(cfg.types_for_help) ? cfg.types_for_help : []
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

/**
 * Whether MIME or extension is on the explicit `blocked` server list (executable,
 * server_script, archive, web). Returns the matching group + message, or null.
 *
 * @param {string|null|undefined} mimeType
 * @param {string|null|undefined} fileExtension
 * @returns {{group: string, message: string, code: string}|null}
 */
export function isExplicitlyBlocked(mimeType, fileExtension) {
    const cfg = getDamFileTypes()
    const groups = cfg.blocked_groups || {}
    const m = mimeType ? String(mimeType).toLowerCase() : ''
    const e = fileExtension ? String(fileExtension).toLowerCase().replace(/^\./, '') : ''
    if (!m && !e) return null
    for (const [group, def] of Object.entries(groups)) {
        const exts = (def.extensions || []).map((x) => String(x).toLowerCase())
        const mimes = (def.mime_types || []).map((x) => String(x).toLowerCase())
        if ((e && exts.includes(e)) || (m && mimes.includes(m))) {
            return {
                group,
                message: def.message || 'This file type cannot be uploaded for security reasons.',
                code: 'blocked_' + (def.code_suffix || group),
            }
        }
    }
    return null
}

/**
 * Whether a known-but-not-yet-enabled type matched the file (e.g. an
 * extension whose pipeline ships in a later release). Returns the
 * coming_soon entry or null.
 *
 * @param {string|null|undefined} fileExtension
 * @returns {{key: string, name: string, message: string}|null}
 */
export function isComingSoonType(fileExtension) {
    const cfg = getDamFileTypes()
    const cs = cfg.coming_soon || {}
    const e = fileExtension ? String(fileExtension).toLowerCase().replace(/^\./, '') : ''
    if (!e) return null
    for (const [key, def] of Object.entries(cs)) {
        const exts = (def.extensions || []).map((x) => String(x).toLowerCase())
        if (exts.includes(e)) {
            return {
                key,
                name: def.name || key,
                message: def.message || 'This file type is coming soon.',
            }
        }
    }
    return null
}

/**
 * Single client-side decision: can this file be enqueued for upload?
 * Mirrors backend FileTypeService::isUploadAllowed but operates only on the
 * payload Inertia ships, so it is purely advisory — the server still has
 * final say. Used in handleFileSelect to give the user instant feedback.
 *
 * @returns {{ allowed: true } | { allowed: false, code: string, message: string }}
 */
export function decideClientUpload(mimeType, fileExtension) {
    const blocked = isExplicitlyBlocked(mimeType, fileExtension)
    if (blocked) {
        return { allowed: false, code: blocked.code, message: blocked.message }
    }
    const comingSoon = isComingSoonType(fileExtension)
    if (comingSoon) {
        return { allowed: false, code: 'coming_soon', message: comingSoon.message }
    }
    if (!isDamRegisteredUploadType(mimeType, fileExtension)) {
        return {
            allowed: false,
            code: 'unsupported_type',
            message: 'This file type is not supported for upload.',
        }
    }
    return { allowed: true }
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
    'mp3',
    'wav',
    'aac',
    'm4a',
    'ogg',
    'flac',
    'weba',
])

/** Document / video / vector / audio upload types — not "image tile" in grid chrome (see AssetCard). */
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
    'mp3',
    'wav',
    'aac',
    'm4a',
    'ogg',
    'flac',
    'weba',
])

/**
 * Grid/card "image" styling: true for raster/image pipeline types, false for PDF/office/video/audio even if thumbnailed.
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

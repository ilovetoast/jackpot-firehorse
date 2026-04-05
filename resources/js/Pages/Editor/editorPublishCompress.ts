/**
 * Publish export size guard for environments where reverse proxies enforce a small
 * `client_max_body_size` (e.g. nginx default 1m) and that limit cannot be changed.
 *
 * The POST body includes multipart boundaries, metadata fields, and the file — we
 * target the file portion well under 1MiB.
 */
export const EDITOR_PUBLISH_FILE_BYTE_BUDGET = 800_000

/**
 * Target max size for the image file inside the multipart body. Override with
 * `VITE_EDITOR_PUBLISH_MAX_FILE_BYTES` (build-time) if the proxy limit is stricter than ~1MB total.
 */
export function editorPublishFileByteBudget(): number {
    try {
        const raw = import.meta.env?.VITE_EDITOR_PUBLISH_MAX_FILE_BYTES
        if (raw != null && String(raw).trim() !== '') {
            const n = Number.parseInt(String(raw), 10)
            if (Number.isFinite(n) && n >= 60_000 && n <= 4_000_000) {
                return n
            }
        }
    } catch {
        // ignore
    }
    return EDITOR_PUBLISH_FILE_BYTE_BUDGET
}

/**
 * Re-encode as JPEG at reduced quality and/or scale until the blob is under budget.
 * No-op if already small enough.
 */
export async function compressImageBlobForLegacyUploadLimit(
    blob: Blob,
    maxFileBytes: number = EDITOR_PUBLISH_FILE_BYTE_BUDGET
): Promise<Blob> {
    if (blob.size <= maxFileBytes) {
        return blob
    }

    const bitmap = await createImageBitmap(blob)
    try {
        let scale = 1
        let quality = 0.88

        const encode = (): Promise<Blob> =>
            new Promise((resolve, reject) => {
                const w = Math.max(1, Math.round(bitmap.width * scale))
                const h = Math.max(1, Math.round(bitmap.height * scale))
                const canvas = document.createElement('canvas')
                canvas.width = w
                canvas.height = h
                const ctx = canvas.getContext('2d')
                if (!ctx) {
                    reject(new Error('Canvas unavailable'))
                    return
                }
                ctx.fillStyle = '#ffffff'
                ctx.fillRect(0, 0, w, h)
                ctx.drawImage(bitmap, 0, 0, w, h)
                canvas.toBlob(
                    (b) => (b ? resolve(b) : reject(new Error('Image encode failed'))),
                    'image/jpeg',
                    quality
                )
            })

        let out = await encode()
        let guard = 0
        while (out.size > maxFileBytes && guard < 28) {
            guard += 1
            const longEdge = Math.max(bitmap.width * scale, bitmap.height * scale)
            if (quality > 0.48) {
                quality -= 0.06
            } else if (longEdge > 480) {
                scale *= 0.88
                quality = Math.min(0.9, quality + 0.05)
            } else {
                break
            }
            out = await encode()
        }

        if (out.size > maxFileBytes) {
            const longest = Math.max(bitmap.width, bitmap.height)
            scale = Math.min(scale, 520 / longest)
            quality = 0.42
            out = await encode()
        }

        return out
    } finally {
        bitmap.close()
    }
}

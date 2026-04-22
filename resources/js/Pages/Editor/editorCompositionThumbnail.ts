import { toPng } from 'html-to-image'
import { editorHtmlToImageFetchRequestInit } from './editorHardening'
import type { DocumentModel } from './documentModel'

type CaptureThumbnailOptions = {
    /**
     * html-to-image scales output dimensions by this factor. Thumbnails use 0.5 to save payload;
     * Studio animation snapshots must match {@link DocumentModel} pixel size for server validation.
     */
    pixelRatio?: number
}

/**
 * Re-draw a full-size PNG data URL to a canvas and export JPEG base64 (no data URL prefix).
 * Keeps pixel dimensions identical to reduce POST size (avoids nginx 413) while satisfying server dimension checks.
 */
function reencodePngDataUrlAsJpegBase64(
    pngDataUrl: string,
    width: number,
    height: number,
    quality: number
): Promise<string | null> {
    return new Promise((resolve) => {
        const img = new Image()
        img.crossOrigin = 'anonymous'
        img.onload = () => {
            try {
                const canvas = document.createElement('canvas')
                canvas.width = width
                canvas.height = height
                const ctx = canvas.getContext('2d')
                if (!ctx) {
                    resolve(null)
                    return
                }
                ctx.fillStyle = '#ffffff'
                ctx.fillRect(0, 0, width, height)
                ctx.drawImage(img, 0, 0, width, height)
                const dataUrl = canvas.toDataURL('image/jpeg', quality)
                resolve(dataUrl.replace(/^data:image\/jpeg;base64,/, ''))
            } catch {
                resolve(null)
            }
        }
        img.onerror = () => resolve(null)
        img.src = pngDataUrl
    })
}

/**
 * Rasterize a single layer node for Studio Animate "One layer (cropped)".
 * The full-canvas path plus document-space crop can still include text/logos when the
 * selected layer is full-bleed; this captures only that layer’s subtree.
 */
export async function captureStudioAnimationLayerIsolatedBase64(
    layerEl: HTMLElement,
    width: number,
    height: number
): Promise<string | null> {
    if (!Number.isFinite(width) || !Number.isFinite(height) || width < 2 || height < 2) {
        return null
    }
    try {
        const pngDataUrl = await toPng(layerEl, {
            cacheBust: true,
            skipFonts: true,
            pixelRatio: 1,
            width,
            height,
            backgroundColor: '#ffffff',
            fetchRequestInit: editorHtmlToImageFetchRequestInit,
            style: {
                width: `${width}px`,
                height: `${height}px`,
                boxSizing: 'border-box',
            },
        })
        const jpeg = await reencodePngDataUrlAsJpegBase64(pngDataUrl, width, height, 0.87)
        if (jpeg) {
            return jpeg
        }
        return pngDataUrl.replace(/^data:image\/png;base64,/, '')
    } catch {
        return null
    }
}

/**
 * Full-resolution snapshot for Studio Animate: same pixel size as the document, JPEG-compressed for smaller uploads.
 */
export async function captureCompositionStudioAnimationSnapshotBase64(
    stageEl: HTMLElement,
    doc: DocumentModel
): Promise<string | null> {
    try {
        const pngDataUrl = await toPng(stageEl, {
            cacheBust: true,
            skipFonts: true,
            pixelRatio: 1,
            width: doc.width,
            height: doc.height,
            backgroundColor: '#ffffff',
            fetchRequestInit: editorHtmlToImageFetchRequestInit,
            style: {
                transform: 'none',
                width: `${doc.width}px`,
                height: `${doc.height}px`,
            },
        })
        const jpeg = await reencodePngDataUrlAsJpegBase64(pngDataUrl, doc.width, doc.height, 0.87)
        if (jpeg) {
            return jpeg
        }
        return pngDataUrl.replace(/^data:image\/png;base64,/, '')
    } catch {
        return null
    }
}

/** PNG base64 (no data URL prefix) for API `thumbnail_png_base64`. */
export async function captureCompositionThumbnailBase64(
    stageEl: HTMLElement,
    doc: DocumentModel,
    options?: CaptureThumbnailOptions
): Promise<string | null> {
    const pixelRatio = options?.pixelRatio ?? 0.5
    try {
        const dataUrl = await toPng(stageEl, {
            cacheBust: true,
            /** Avoid SecurityError on cross-origin stylesheets (e.g. fonts.bunny.net) when reading cssRules. */
            skipFonts: true,
            /** Thumbnails: 0.5. Animation submit: 1 so decoded PNG matches snapshot_width/height. */
            pixelRatio,
            width: doc.width,
            height: doc.height,
            backgroundColor: '#ffffff',
            fetchRequestInit: editorHtmlToImageFetchRequestInit,
            style: {
                transform: 'none',
                width: `${doc.width}px`,
                height: `${doc.height}px`,
            },
        })
        return dataUrl.replace(/^data:image\/png;base64,/, '')
    } catch {
        return null
    }
}

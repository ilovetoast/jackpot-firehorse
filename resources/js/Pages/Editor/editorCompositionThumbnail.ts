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

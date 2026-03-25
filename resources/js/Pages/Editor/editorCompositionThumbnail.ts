import { toPng } from 'html-to-image'
import { editorHtmlToImageFetchRequestInit } from './editorHardening'
import type { DocumentModel } from './documentModel'

/** PNG base64 (no data URL prefix) for API `thumbnail_png_base64`. */
export async function captureCompositionThumbnailBase64(
    stageEl: HTMLElement,
    doc: DocumentModel
): Promise<string | null> {
    try {
        const dataUrl = await toPng(stageEl, {
            cacheBust: true,
            /** Avoid SecurityError on cross-origin stylesheets (e.g. fonts.bunny.net) when reading cssRules. */
            skipFonts: true,
            /** Caps PNG payload size (~4× fewer pixels vs pixelRatio 1 at same logical size). */
            pixelRatio: 0.5,
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

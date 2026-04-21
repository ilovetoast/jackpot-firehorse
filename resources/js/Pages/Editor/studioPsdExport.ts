import { writePsdUint8Array, type Psd } from 'ag-psd'

function loadImageFromDataUrl(dataUrl: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const img = new Image()
        img.onload = () => resolve(img)
        img.onerror = () => reject(new Error('Failed to decode export image for PSD'))
        img.src = dataUrl
    })
}

/**
 * v1 test PSD: one raster layer matching the flattened stage PNG (same as Export PNG).
 * Proves the pipeline; future versions can map real layers.
 */
export async function buildTestPsdFromFlattenedPng(
    dataUrl: string,
    width: number,
    height: number
): Promise<Uint8Array> {
    const img = await loadImageFromDataUrl(dataUrl)
    const layerCanvas = document.createElement('canvas')
    layerCanvas.width = width
    layerCanvas.height = height
    const ctx = layerCanvas.getContext('2d')
    if (!ctx) {
        throw new Error('Canvas not available for PSD export')
    }
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, width, height)
    ctx.drawImage(img, 0, 0, width, height)

    const psd: Psd = {
        width,
        height,
        children: [
            {
                name: 'Studio (v1 test — flattened)',
                top: 0,
                left: 0,
                bottom: height,
                right: width,
                canvas: layerCanvas,
            },
        ],
    }
    return writePsdUint8Array(psd)
}

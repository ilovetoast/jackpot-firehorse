/**
 * Deterministic branded pixel mosaic for processing thumbnails (grid / drawer).
 * Per-cell variation from hash(asset.id || filename) — no Math.random().
 */

import { sanitizeHexColor } from './getAssetPlaceholderTheme.js'
import { fnv1a32, getAssetPlaceholderHue, parseBrandHueFromHex, getPlaceholderVariationIndex } from './assetPlaceholderHue.js'

const JACKPOT_FALLBACK = '#6366f1'

function placeholderHashKey(asset) {
    if (asset?.id != null && asset.id !== '') return String(asset.id)
    const name =
        asset?.original_filename ||
        asset?.filename ||
        asset?.title ||
        asset?.name ||
        'asset'
    return String(name)
}

function cellHash(assetKey, row, col) {
    return fnv1a32(`${assetKey}|${row}|${col}`)
}

/**
 * @typedef {{ h: number, s: number, l: number, delayMs: number, index: number }} MosaicCell
 * @typedef {{ cols: number, rows: number, cells: MosaicCell[], baseHue: number, assetKey: string }} MosaicModel
 */

/**
 * @param {object|null|undefined} asset
 * @param {{ primary_color?: string, primaryColor?: string, accent_color?: string, accentColor?: string }} [brandTheme={}]
 * @param {{ cols?: number, rows?: number }} [options]
 * @returns {MosaicModel}
 */
export function getAssetMosaicPlaceholder(asset, brandTheme = {}, options = {}) {
    const primary = sanitizeHexColor(
        brandTheme.primary_color ?? brandTheme.primaryColor ?? JACKPOT_FALLBACK,
        JACKPOT_FALLBACK,
    )
    const cols = Number(options.cols) > 0 ? Math.min(16, Math.floor(Number(options.cols))) : 10
    const rows = Number(options.rows) > 0 ? Math.min(12, Math.floor(Number(options.rows))) : 6
    const assetKey = placeholderHashKey(asset)
    const baseHue = getAssetPlaceholderHue(asset, primary)
    const brandHue = parseBrandHueFromHex(primary)
    const globalMix = getPlaceholderVariationIndex(asset)

    const cells = []
    let idx = 0
    for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
            const seed = cellHash(assetKey, r, c) ^ (globalMix >>> ((r + c * 3) % 17))
            const dh = (seed % 9) - 4
            const ds = ((seed >>> 4) % 7) - 3
            const dl = ((seed >>> 8) % 9) - 4

            const h = Math.round((baseHue * 0.55 + brandHue * 0.45 + dh * 0.85 + 360) % 360)
            const s = Math.min(42, Math.max(18, 30 + ds * 1.1))
            const l = Math.min(20, Math.max(8, 13 + dl * 0.55))

            const stagger = ((seed >>> 12) % 1800) + ((r * cols + c) * 37) % 420
            const delayMs = stagger % 2400

            cells.push({ h, s, l, delayMs, index: idx })
            idx++
        }
    }

    return { cols, rows, cells, baseHue, assetKey }
}

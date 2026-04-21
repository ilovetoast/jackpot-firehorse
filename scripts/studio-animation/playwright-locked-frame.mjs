#!/usr/bin/env node
/**
 * First-party deterministic locked-document → PNG (Playwright Chromium).
 * Contract (argv): <document.json path> <output.png path> <viewportW> <viewportH> <aspectRatio> <rendererVersion>
 * Document JSON: { width, height, layers: [{ id, type, visible, z, transform, ... }] }
 * Determinism: deviceScaleFactor=1, no external network, canvas draw order by z.
 */
import fs from 'fs'
import { chromium } from 'playwright'

const [, , jsonPath, outPath, vw, vh, aspectRatio, rendererVersion] = process.argv

if (!jsonPath || !outPath) {
    console.error('usage: playwright-locked-frame.mjs <document.json> <output.png> <w> <h> <aspectRatio> <version>')
    process.exit(2)
}

const raw = fs.readFileSync(jsonPath, 'utf8')
const doc = JSON.parse(raw)
const width = Math.min(8192, Math.max(1, parseInt(vw || doc.width, 10) || doc.width || 1))
const height = Math.min(8192, Math.max(1, parseInt(vh || doc.height, 10) || doc.height || 1))

const browser = await chromium.launch({
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    headless: true,
})
try {
    const page = await browser.newPage({
        viewport: { width, height },
        deviceScaleFactor: 1,
    })
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"/></head><body style="margin:0;padding:0;background:#fff"><canvas id="c" width="${width}" height="${height}"></canvas></body></html>`
    await page.setContent(html, { waitUntil: 'load' })
    await page.evaluate((d) => {
        const layers = Array.isArray(d.layers) ? [...d.layers] : []
        layers.sort((a, b) => (Number(a.z) || 0) - (Number(b.z) || 0))
        const c = document.getElementById('c')
        const ctx = c.getContext('2d')
        ctx.fillStyle = '#ffffff'
        ctx.fillRect(0, 0, c.width, c.height)
        for (const layer of layers) {
            if (!layer || layer.visible === false) continue
            const t = layer.transform || {}
            const x = Number(t.x) || 0
            const y = Number(t.y) || 0
            const w = Math.max(0, Number(t.width) || 0)
            const h = Math.max(0, Number(t.height) || 0)
            const type = String(layer.type || '')
            let fill = '#e5e7eb'
            if (layer.id) {
                let h0 = 0
                for (let i = 0; i < String(layer.id).length; i++) h0 = (h0 * 31 + String(layer.id).charCodeAt(i)) >>> 0
                const hue = h0 % 360
                fill = `hsl(${hue} 35% 88%)`
            }
            if (type === 'fill' && layer.fill && typeof layer.fill === 'string') {
                fill = layer.fill
            }
            ctx.fillStyle = fill
            ctx.fillRect(x, y, w, h)
            if (type === 'text' && layer.content) {
                ctx.fillStyle = String(layer.style?.color || '#111111')
                const size = Math.max(8, Math.min(256, Number(layer.style?.fontSize) || 16))
                ctx.font = `${size}px ui-monospace, monospace`
                ctx.textBaseline = 'top'
                const text = String(layer.content).slice(0, 5000)
                ctx.fillText(text, x + 2, y + 2, Math.max(0, w - 4))
            }
            if (type === 'image') {
                ctx.strokeStyle = '#9ca3af'
                ctx.lineWidth = 2
                ctx.strokeRect(x + 1, y + 1, Math.max(0, w - 2), Math.max(0, h - 2))
                ctx.fillStyle = '#d1d5db'
                ctx.font = '12px ui-monospace, monospace'
                ctx.fillText('IMG', x + 4, y + 4)
            }
        }
    }, doc)
    await page.screenshot({
        path: outPath,
        type: 'png',
        clip: { x: 0, y: 0, width, height },
    })
} finally {
    await browser.close()
}

if (process.env.STUDIO_ANIMATION_PLAYWRIGHT_DEBUG) {
    console.error(`[playwright-locked-frame] ok version=${rendererVersion || '?'} aspect=${aspectRatio || '?'} ${width}x${height}`)
}

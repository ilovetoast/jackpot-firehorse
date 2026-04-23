#!/usr/bin/env node
/**
 * Playwright driver: open signed Studio composition export render URL, wait for bridge readiness,
 * step time deterministically, capture PNG frames from [data-jp-composition-scene-root].
 *
 * Exit codes: 0 success | 2 bad args | 3 navigation | 4 readiness | 5 capture | 6 manifest I/O
 */
import { mkdir, writeFile } from 'node:fs/promises'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const MANIFEST_SCHEMA = 'studio_canvas_capture_manifest_v1'

/** @param {string[]} argv */
export function parseStudioCanvasExportArgs(argv) {
    const raw = {}
    for (const a of argv) {
        if (!a.startsWith('--')) {
            continue
        }
        const body = a.slice(2)
        const eq = body.indexOf('=')
        if (eq === -1) {
            raw[body] = 'true'
        } else {
            raw[body.slice(0, eq)] = body.slice(eq + 1)
        }
    }
    const required = ['url', 'output-dir', 'fps', 'duration-ms', 'width', 'height', 'export-job-id']
    const missing = required.filter((k) => raw[k] == null || String(raw[k]).trim() === '')
    if (missing.length > 0) {
        return {
            ok: false,
            error: `missing required flags: ${missing.map((m) => `--${m}`).join(', ')}`,
            raw,
        }
    }
    const fps = Number(raw.fps)
    const durationMs = Number(raw['duration-ms'])
    const width = Number(raw.width)
    const height = Number(raw.height)
    if (!Number.isFinite(fps) || fps < 1 || fps > 120) {
        return { ok: false, error: '--fps must be a number between 1 and 120', raw }
    }
    if (!Number.isFinite(durationMs) || durationMs < 1) {
        return { ok: false, error: '--duration-ms must be a positive number', raw }
    }
    if (!Number.isFinite(width) || width < 1 || !Number.isFinite(height) || height < 1) {
        return { ok: false, error: '--width and --height must be positive numbers', raw }
    }
    return {
        ok: true,
        config: {
            url: String(raw.url),
            outputDir: String(raw['output-dir']),
            fps,
            durationMs,
            width,
            height,
            exportJobId: String(raw['export-job-id']),
            readinessTimeoutMs: Number(raw['readiness-timeout-ms'] ?? 120_000),
            navigationTimeoutMs: Number(raw['navigation-timeout-ms'] ?? 120_000),
            frameSettleMs: Number(raw['frame-settle-ms'] ?? 50),
            deviceScaleFactor: Number(raw['device-scale-factor'] ?? 1),
            totalTimeoutMs: Number(raw['total-timeout-ms'] ?? 3_600_000),
        },
    }
}

async function writeDiagnosticsFile(outputDir, payload) {
    try {
        await mkdir(outputDir, { recursive: true })
        await writeFile(join(outputDir, 'capture-diagnostics.json'), JSON.stringify(payload, null, 2), 'utf8')
    } catch {
        /* best-effort */
    }
}

async function settleAfterTimeStep(page, frameSettleMs) {
    await page.evaluate(() => {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => resolve(undefined))
            })
        })
    })
    if (frameSettleMs > 0) {
        await new Promise((r) => setTimeout(r, frameSettleMs))
    }
}

async function main() {
    const parsed = parseStudioCanvasExportArgs(process.argv.slice(2))
    if (!parsed.ok) {
        const err = { schema: 'studio_canvas_capture_diagnostics_v1', phase: 'args', error: parsed.error }
        // eslint-disable-next-line no-console -- CLI contract
        console.error(JSON.stringify(err))
        process.exit(2)
    }
    const cfg = parsed.config
    const startedAt = Date.now()
    const consoleLines = []
    const pageErrors = []

    await mkdir(cfg.outputDir, { recursive: true })

    let browser = null
    let readinessMs = null
    let bridgeAfterReady = null
    const bridgeSnapshots = []

    try {
        browser = await chromium.launch({
            headless: true,
            args: [
                '--disable-dev-shm-usage',
                '--disable-background-networking',
                '--disable-background-timer-throttling',
                '--disable-renderer-backgrounding',
                '--force-color-profile=srgb',
            ],
        })
        const context = await browser.newContext({
            viewport: { width: cfg.width, height: cfg.height },
            deviceScaleFactor: Number.isFinite(cfg.deviceScaleFactor) && cfg.deviceScaleFactor > 0 ? cfg.deviceScaleFactor : 1,
            javaScriptEnabled: true,
        })
        const page = await context.newPage()
        page.on('console', (msg) => {
            const t = msg.type()
            const text = msg.text()
            if (t === 'error' || t === 'warning') {
                consoleLines.push({ type: t, text })
            }
        })
        page.on('pageerror', (err) => {
            pageErrors.push(String(err?.stack || err))
        })

        const navStart = Date.now()
        await page.goto(cfg.url, {
            waitUntil: 'domcontentloaded',
            timeout: cfg.navigationTimeoutMs,
        })

        await page.waitForFunction(
            () => typeof window.__COMPOSITION_EXPORT_BRIDGE__?.getState === 'function',
            { timeout: cfg.readinessTimeoutMs },
        )

        await page.waitForFunction(
            () => {
                const s = window.__COMPOSITION_EXPORT_BRIDGE__?.getState?.()
                return Boolean(s && s.ready === true)
            },
            { timeout: cfg.readinessTimeoutMs },
        )
        readinessMs = Date.now() - navStart
        bridgeAfterReady = await page.evaluate(() => window.__COMPOSITION_EXPORT_BRIDGE__?.getState?.() ?? null)
        bridgeSnapshots.push({ label: 'after_ready', state: bridgeAfterReady, at_ms: Date.now() - startedAt })

        const totalFrames = Math.max(1, Math.ceil((cfg.durationMs / 1000) * cfg.fps - 1e-12))
        const pad = String(totalFrames).length
        const padN = Math.max(6, pad)

        for (let i = 0; i < totalFrames; i++) {
            if (Date.now() - startedAt > cfg.totalTimeoutMs) {
                throw new Error(`total capture wall time exceeded total-timeout-ms=${cfg.totalTimeoutMs}`)
            }
            const frameTimeMs = i === totalFrames - 1 ? cfg.durationMs : Math.min(cfg.durationMs, Math.round((i * 1000) / cfg.fps))
            await page.evaluate((ms) => {
                window.__COMPOSITION_EXPORT_BRIDGE__?.setTimeMs?.(ms)
            }, frameTimeMs)
            await settleAfterTimeStep(page, cfg.frameSettleMs)

            const name = `frame_${String(i).padStart(padN, '0')}.png`
            const outPath = join(cfg.outputDir, name)
            const handle = await page.locator('[data-jp-composition-scene-root]').first()
            const count = await handle.count()
            if (count < 1) {
                throw new Error('missing [data-jp-composition-scene-root] for screenshot')
            }
            await handle.screenshot({ path: outPath, type: 'png' })

            if (i === 0 || i === totalFrames - 1 || (i + 1) % 30 === 0) {
                const st = await page.evaluate(() => window.__COMPOSITION_EXPORT_BRIDGE__?.getState?.() ?? null)
                bridgeSnapshots.push({ label: `frame_${i}`, frame_index: i, frame_time_ms: frameTimeMs, state: st, at_ms: Date.now() - startedAt })
            }
        }

        const firstFrameTimeMs = 0
        const lastFrameTimeMs =
            totalFrames === 1 ? cfg.durationMs : Math.min(cfg.durationMs, Math.round(((totalFrames - 1) * 1000) / cfg.fps))

        const manifest = {
            schema: MANIFEST_SCHEMA,
            export_job_id: cfg.exportJobId,
            fps: cfg.fps,
            duration_ms: cfg.durationMs,
            width: cfg.width,
            height: cfg.height,
            render_url: cfg.url,
            readiness_wait_ms: readinessMs,
            wall_clock_total_ms: Date.now() - startedAt,
            total_expected_frames: totalFrames,
            total_captured_frames: totalFrames,
            frame_filename_pattern: `frame_%0${padN}d.png`,
            first_frame_time_ms: firstFrameTimeMs,
            last_frame_time_ms: lastFrameTimeMs,
            console_lines: consoleLines,
            page_errors: pageErrors,
            bridge_snapshots: bridgeSnapshots,
            unsupported_layer_types: bridgeAfterReady?.unsupportedLayerTypes ?? [],
            assets_failed: bridgeAfterReady?.assetsFailed ?? [],
            fonts_failed: bridgeAfterReady?.fontsFailed ?? [],
            playwright_browser_version: browser.version(),
        }

        await writeFile(join(cfg.outputDir, 'capture-manifest.json'), JSON.stringify(manifest, null, 2), 'utf8')
        // eslint-disable-next-line no-console -- CLI contract: single-line path for optional parsers
        console.log(JSON.stringify({ ok: true, manifest_path: join(cfg.outputDir, 'capture-manifest.json') }))
        process.exit(0)
    } catch (e) {
        const message = e instanceof Error ? e.message : String(e)
        try {
            if (browser) {
                const pages = browser.contexts().flatMap((c) => c.pages())
                const p = pages[0]
                if (p) {
                    await p.screenshot({ path: join(cfg.outputDir, 'failure-fullpage.png'), type: 'png' }).catch(() => {})
                    const loc = p.locator('[data-jp-composition-scene-root]').first()
                    if ((await loc.count()) > 0) {
                        await loc.screenshot({ path: join(cfg.outputDir, 'failure-scene-root.png'), type: 'png' }).catch(() => {})
                    }
                }
            }
        } catch {
            /* ignore */
        }
        let bridgeState = null
        try {
            const pages = browser?.contexts().flatMap((c) => c.pages()) ?? []
            const p = pages[0]
            if (p) {
                bridgeState = await p.evaluate(() => window.__COMPOSITION_EXPORT_BRIDGE__?.getState?.() ?? null)
            }
        } catch {
            /* ignore */
        }
        await writeDiagnosticsFile(cfg.outputDir, {
            schema: 'studio_canvas_capture_diagnostics_v1',
            phase: 'capture',
            error: message,
            readiness_wait_ms: readinessMs,
            bridge_state: bridgeState,
            console_lines: consoleLines,
            page_errors: pageErrors,
            bridge_snapshots: bridgeSnapshots,
        })
        // eslint-disable-next-line no-console
        console.error(JSON.stringify({ ok: false, error: message }))
        const code = /goto|navigation|timeout.*goto/i.test(message) ? 3 : /ready|bridge|getState/i.test(message) ? 4 : 5
        process.exit(code)
    } finally {
        if (browser) {
            await browser.close().catch(() => {})
        }
    }
}

const isMain = Boolean(process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1]))
if (isMain) {
    void main()
}

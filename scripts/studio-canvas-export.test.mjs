import { strict as assert } from 'node:assert'
import { spawnSync } from 'node:child_process'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

test('parseStudioCanvasExportArgs rejects missing flags', async () => {
    const { parseStudioCanvasExportArgs } = await import('./studio-canvas-export.mjs')
    const r = parseStudioCanvasExportArgs(['--fps=30'])
    assert.equal(r.ok, false)
    assert.match(String(r.error), /missing required flags/)
})

test('parseStudioCanvasExportArgs accepts a full valid argv', async () => {
    const { parseStudioCanvasExportArgs } = await import('./studio-canvas-export.mjs')
    const r = parseStudioCanvasExportArgs([
        '--url=https://example.test/render',
        '--output-dir=/tmp/out',
        '--fps=30',
        '--duration-ms=5000',
        '--width=1080',
        '--height=1920',
        '--export-job-id=42',
    ])
    assert.equal(r.ok, true)
    assert.equal(r.config.exportJobId, '42')
    assert.equal(r.config.fps, 30)
})

test('readiness and navigation timeouts below 60s fall back to 120s', async () => {
    const { parseStudioCanvasExportArgs } = await import('./studio-canvas-export.mjs')
    const r = parseStudioCanvasExportArgs([
        '--url=https://example.test/render',
        '--output-dir=/tmp/out',
        '--fps=30',
        '--duration-ms=5000',
        '--width=1080',
        '--height=1920',
        '--export-job-id=42',
        '--readiness-timeout-ms=30000',
        '--navigation-timeout-ms=10000',
    ])
    assert.equal(r.ok, true)
    assert.equal(r.config.readinessTimeoutMs, 120_000)
    assert.equal(r.config.navigationTimeoutMs, 120_000)
})

test('CLI exits 2 when required args missing', () => {
    const script = join(repoRoot, 'scripts', 'studio-canvas-export.mjs')
    const r = spawnSync(process.execPath, [script], { encoding: 'utf8', cwd: repoRoot })
    assert.equal(r.status, 2)
    assert.doesNotThrow(() => JSON.parse(r.stderr.trim()))
})

import test from 'node:test'
import assert from 'node:assert/strict'
import {
    getUploadItemProgress,
    computeOverallBatchUploadPercent,
    isUploadItemTransferTerminal,
} from './uploadQueueProgress.js'

test('getUploadItemProgress: uploaded+complete returns 100 even with stale progress', () => {
    const item = { lifecycle: 'uploaded', uploadStatus: 'complete', progress: 88 }
    assert.equal(getUploadItemProgress(item), 100)
})

test('computeOverallBatchUploadPercent: all uploaded+complete returns 100 despite stale per-file progress', () => {
    const items = Array.from({ length: 6 }, () => ({
        lifecycle: 'uploaded',
        uploadStatus: 'complete',
        progress: 88,
    }))
    assert.equal(computeOverallBatchUploadPercent(items), 100)
})

test('computeOverallBatchUploadPercent: five complete + one uploading at 50% averages over six non-skipped', () => {
    const items = [
        ...Array.from({ length: 5 }, () => ({
            lifecycle: 'uploaded',
            uploadStatus: 'complete',
            progress: 0,
        })),
        { lifecycle: 'uploaded', uploadStatus: 'uploading', progress: 50 },
    ]
    const p = computeOverallBatchUploadPercent(items)
    assert.ok(Math.abs(p - (500 + 50) / 6) < 0.01)
})

test('computeOverallBatchUploadPercent: five uploaded + one failed (all terminal) is 100', () => {
    const items = [
        ...Array.from({ length: 5 }, () => ({
            lifecycle: 'uploaded',
            uploadStatus: 'complete',
            progress: 12,
        })),
        { lifecycle: 'failed', uploadStatus: 'failed', progress: 0 },
    ]
    assert.equal(computeOverallBatchUploadPercent(items), 100)
    assert.equal(isUploadItemTransferTerminal(items[5]), true)
})

test('computeOverallBatchUploadPercent: skipped excluded from average; all terminal including skipped is 100', () => {
    const mixed = [
        ...Array.from({ length: 5 }, () => ({
            lifecycle: 'uploaded',
            uploadStatus: 'complete',
            progress: 40,
        })),
        { lifecycle: 'uploaded', uploadStatus: 'skipped', progress: 0 },
    ]
    assert.equal(computeOverallBatchUploadPercent(mixed), 100)

    const oneActive = [
        ...Array.from({ length: 5 }, () => ({
            lifecycle: 'uploaded',
            uploadStatus: 'complete',
            progress: 100,
        })),
        { lifecycle: 'uploaded', uploadStatus: 'skipped', progress: 0 },
        { lifecycle: 'uploaded', uploadStatus: 'uploading', progress: 50 },
    ]
    const p = computeOverallBatchUploadPercent(oneActive)
    assert.equal(p, (5 * 100 + 50) / 6)
})

test('getUploadItemProgress: queued and checking-like states are 0', () => {
    assert.equal(getUploadItemProgress({ lifecycle: 'selected', uploadStatus: 'queued', progress: 50 }), 0)
    assert.equal(getUploadItemProgress({ lifecycle: 'pending_preflight', uploadStatus: 'queued', progress: 0 }), 0)
})

test('getUploadItemProgress: uploading uses clamped progress', () => {
    assert.equal(getUploadItemProgress({ lifecycle: 'uploading', uploadStatus: 'uploading', progress: 77 }), 77)
    assert.equal(getUploadItemProgress({ lifecycle: 'uploading', uploadStatus: 'uploading', progress: 150 }), 100)
    assert.equal(getUploadItemProgress({ lifecycle: 'uploading', uploadStatus: 'uploading', progress: -3 }), 0)
})

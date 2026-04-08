import test from 'node:test'
import assert from 'node:assert/strict'
import { getExecutionGridDisplayUrl, getExecutionGridHoverCrossfadeUrl } from './executionThumbnailDisplay.js'

const lsStore = {}
globalThis.localStorage = {
    getItem: (k) => (Object.prototype.hasOwnProperty.call(lsStore, k) ? lsStore[k] : null),
    setItem: (k, v) => {
        lsStore[k] = String(v)
    },
    removeItem: (k) => {
        delete lsStore[k]
    },
}

const asset = {
    thumbnail_mode_urls: {
        original: { medium: 'https://example.com/o.webp' },
        preferred: { medium: 'https://example.com/p.webp' },
        enhanced: { medium: 'https://example.com/e.webp' },
        presentation: { medium: 'https://example.com/pr.webp' },
    },
}

test('getExecutionGridDisplayUrl original uses original only', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'original', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridDisplayUrl enhanced prefers enhanced then preferred then original', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'enhanced', 'medium'), 'https://example.com/e.webp')
    const noPreferred = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            enhanced: { medium: 'https://example.com/e.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noPreferred, 'enhanced', 'medium'), 'https://example.com/e.webp')
    const onlyOriginal = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(onlyOriginal, 'enhanced', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridDisplayUrl presentation cascades presentation → enhanced → preferred → original', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'presentation', 'medium'), 'https://example.com/pr.webp')
    const noPres = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            preferred: { medium: 'https://example.com/p.webp' },
            enhanced: { medium: 'https://example.com/e.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noPres, 'presentation', 'medium'), 'https://example.com/e.webp')
})

test('getExecutionGridDisplayUrl clean legacy prefers preferred', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'clean', 'medium'), 'https://example.com/p.webp')
})

test('getExecutionGridHoverCrossfadeUrl returns original when display differs from original', () => {
    assert.equal(getExecutionGridHoverCrossfadeUrl(asset, 'enhanced', 'medium'), 'https://example.com/o.webp')
    assert.equal(getExecutionGridHoverCrossfadeUrl(asset, 'presentation', 'medium'), 'https://example.com/o.webp')
})

test('per-asset preferred tier applies only in Standard mode; global Pres. ignores drawer pref', () => {
    const id = 'asset-pref-standard-only'
    lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset = JSON.stringify({
        [id]: 'original',
    })
    const a = { ...asset, id }
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/o.webp')
    assert.equal(getExecutionGridDisplayUrl(a, 'presentation', 'medium'), 'https://example.com/pr.webp')
    lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset = JSON.stringify({
        [id]: 'presentation',
    })
    assert.equal(getExecutionGridDisplayUrl(a, 'enhanced', 'medium'), 'https://example.com/e.webp')
    assert.equal(getExecutionGridDisplayUrl(a, 'presentation', 'medium'), 'https://example.com/pr.webp')
    delete lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset
})

test('getExecutionGridDisplayUrl standard uses preferred tier from localStorage', () => {
    const id = 'asset-standard-test'
    lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset = JSON.stringify({
        [id]: 'presentation',
    })
    const a = { ...asset, id }
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/pr.webp')
    lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset = JSON.stringify({
        [id]: 'enhanced',
    })
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/e.webp')
    lsStore.jackpot_execution_preferred_thumbnail_tier_by_asset = JSON.stringify({})
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridHoverCrossfadeUrl returns null when display is original', () => {
    const onlyOriginal = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
        },
    }
    assert.equal(getExecutionGridHoverCrossfadeUrl(onlyOriginal, 'enhanced', 'medium'), null)
    assert.equal(getExecutionGridHoverCrossfadeUrl(onlyOriginal, 'original', 'medium'), null)
})

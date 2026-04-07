import test from 'node:test'
import assert from 'node:assert/strict'
import { getThumbnailUrl, getThumbnailUrlModeOnly } from './thumbnailUrlResolve.js'
import {
    mergeThumbnailModeUrlsPreserveCache,
    shouldShowPreferredPreviewOption,
    shouldShowEnhancedPreviewRadio,
    isCompleteEnhancedOutputStillFresh,
    isEnhancedOutputStale,
} from './thumbnailModes.js'

test('getThumbnailUrl prefers requested mode then original', () => {
    const asset = {
        thumbnail_mode_urls: {
            preferred: { medium: 'https://example.com/preferred.webp' },
            original: { medium: 'https://example.com/original.webp' },
        },
    }
    assert.equal(getThumbnailUrl(asset, 'medium', 'preferred'), 'https://example.com/preferred.webp')
    assert.equal(getThumbnailUrl(asset, 'medium', 'enhanced'), 'https://example.com/original.webp')
})

test('getThumbnailUrlModeOnly returns null when mode bucket missing (no fallback to original)', () => {
    const asset = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/original.webp' },
        },
    }
    assert.equal(getThumbnailUrlModeOnly(asset, 'medium', 'enhanced'), null)
})

test('getThumbnailUrlModeOnly returns enhanced URL only from enhanced bucket', () => {
    const asset = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/original.webp' },
            enhanced: { medium: 'https://example.com/enhanced.webp' },
        },
    }
    assert.equal(
        getThumbnailUrlModeOnly(asset, 'medium', 'enhanced'),
        'https://example.com/enhanced.webp',
    )
})

test('getThumbnailUrl falls back when preferred bucket missing', () => {
    const asset = {
        thumbnail_mode_urls: {
            original: { thumb: 'https://example.com/t.webp' },
        },
    }
    assert.equal(getThumbnailUrl(asset, 'medium', 'preferred'), 'https://example.com/t.webp')
})

test('getThumbnailUrl returns null when map empty and no legacy fields', () => {
    assert.equal(getThumbnailUrl({ thumbnail_mode_urls: { enhanced: {} } }, 'medium', 'enhanced'), null)
})

test('mergeThumbnailModeUrlsPreserveCache keeps prior URLs when cache_key matches', () => {
    const prevUrls = { preferred: { medium: 'https://old-signed.example/a' } }
    const nextUrls = { preferred: { medium: 'https://new-signed.example/b' } }
    const prevMeta = { preferred: { cache_key: 'v1:abc' } }
    const nextMeta = { preferred: { cache_key: 'v1:abc' } }
    const merged = mergeThumbnailModeUrlsPreserveCache(prevUrls, nextUrls, prevMeta, nextMeta)
    assert.equal(merged.preferred.medium, 'https://old-signed.example/a')
})

test('mergeThumbnailModeUrlsPreserveCache adopts new URLs when cache_key changes', () => {
    const prevUrls = { preferred: { medium: 'https://old.example/a' } }
    const nextUrls = { preferred: { medium: 'https://new.example/b' } }
    const prevMeta = { preferred: { cache_key: 'v1:aaa' } }
    const nextMeta = { preferred: { cache_key: 'v1:bbb' } }
    const merged = mergeThumbnailModeUrlsPreserveCache(prevUrls, nextUrls, prevMeta, nextMeta)
    assert.equal(merged.preferred.medium, 'https://new.example/b')
})

test('shouldShowPreferredPreviewOption respects processing and failed', () => {
    assert.equal(shouldShowPreferredPreviewOption({ thumbnail_modes_status: { preferred: 'processing' } }), true)
    assert.equal(shouldShowPreferredPreviewOption({ thumbnail_modes_status: { preferred: 'complete' } }), true)
    assert.equal(shouldShowPreferredPreviewOption({ thumbnail_modes_status: { preferred: 'failed' } }), false)
    assert.equal(shouldShowPreferredPreviewOption({ thumbnail_modes_status: {} }), false)
})

test('shouldShowPreferredPreviewOption hides low-signal clean crop (trim_ratio + edge_density)', () => {
    const base = {
        thumbnail_modes_status: { preferred: 'complete' },
        thumbnail_modes_meta: {
            preferred: { trim_ratio: 0.04, edge_density: 0.35 },
        },
    }
    assert.equal(shouldShowPreferredPreviewOption(base), false)
    assert.equal(
        shouldShowPreferredPreviewOption({
            ...base,
            thumbnail_modes_meta: { preferred: { trim_ratio: 0.04, edge_density: 0.5 } },
        }),
        true,
    )
    assert.equal(
        shouldShowPreferredPreviewOption({
            ...base,
            thumbnail_modes_meta: { preferred: { trim_ratio: 0.1, edge_density: 0.35 } },
        }),
        true,
    )
})

test('isCompleteEnhancedOutputStillFresh is true only when complete and output_fresh is not false', () => {
    assert.equal(isCompleteEnhancedOutputStillFresh({ thumbnail_modes_status: { enhanced: 'processing' } }), false)
    assert.equal(isCompleteEnhancedOutputStillFresh({ thumbnail_modes_status: { enhanced: 'complete' } }), true)
    assert.equal(
        isCompleteEnhancedOutputStillFresh({
            thumbnail_modes_status: { enhanced: 'complete' },
            thumbnail_modes_meta: { enhanced: { output_fresh: false } },
        }),
        false,
    )
    assert.equal(
        isCompleteEnhancedOutputStillFresh({
            thumbnail_modes_status: { enhanced: 'complete' },
            thumbnail_modes_meta: { enhanced: { output_fresh: true } },
        }),
        true,
    )
})

test('isEnhancedOutputStale is true only when complete and output_fresh is false (not fresh)', () => {
    assert.equal(isEnhancedOutputStale({ thumbnail_modes_status: { enhanced: 'complete' } }), false)
    assert.equal(
        isEnhancedOutputStale({
            thumbnail_modes_status: { enhanced: 'complete' },
            thumbnail_modes_meta: { enhanced: { output_fresh: false } },
        }),
        true,
    )
    assert.equal(
        isEnhancedOutputStale({
            thumbnail_modes_status: { enhanced: 'complete' },
            thumbnail_modes_meta: { enhanced: { output_fresh: true } },
        }),
        false,
    )
    assert.equal(isEnhancedOutputStale({ thumbnail_modes_status: { enhanced: 'processing' } }), false)
})

test('shouldShowEnhancedPreviewRadio is true when enhanced URLs exist', () => {
    assert.equal(
        shouldShowEnhancedPreviewRadio({
            thumbnail_mode_urls: { enhanced: { medium: 'https://example.com/e.webp' } },
        }),
        true,
    )
})

test('shouldShowEnhancedPreviewRadio is true for pipeline states without URLs yet', () => {
    for (const st of ['processing', 'complete', 'failed', 'skipped']) {
        assert.equal(shouldShowEnhancedPreviewRadio({ thumbnail_modes_status: { enhanced: st } }), true)
    }
})

test('shouldShowEnhancedPreviewRadio is false when no enhanced URLs and no pipeline state', () => {
    assert.equal(shouldShowEnhancedPreviewRadio({ thumbnail_modes_status: {} }), false)
    assert.equal(shouldShowEnhancedPreviewRadio({ thumbnail_mode_urls: { original: { medium: 'x' } } }), false)
})

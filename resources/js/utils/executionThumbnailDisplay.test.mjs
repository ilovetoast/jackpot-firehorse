import test from 'node:test'
import assert from 'node:assert/strict'
import {
    getExecutionGridDisplayUrl,
    getExecutionGridHoverCrossfadeUrl,
    resolveExecutionGridThumbnail,
} from './executionThumbnailDisplay.js'

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

const PREF_V2 = 'jackpot_execution_preferred_thumbnail_tier_by_asset_v2'

const presentationMeta = {
    thumbnail_modes_meta: {
        presentation_css: { preset: 'neutral_studio' },
    },
}

const asset = {
    ...presentationMeta,
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

test('getExecutionGridDisplayUrl enhanced prefers Studio then Source only', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'enhanced', 'medium'), 'https://example.com/e.webp')
    const noPreferred = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            enhanced: { medium: 'https://example.com/e.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noPreferred, 'enhanced', 'medium'), 'https://example.com/e.webp')
    const onlyOriginal = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(onlyOriginal, 'enhanced', 'medium'), 'https://example.com/o.webp')
    const preferredOnly = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            preferred: { medium: 'https://example.com/p.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(preferredOnly, 'enhanced', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridDisplayUrl presentation uses CSS base when preset + base exist', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'presentation', 'medium'), 'https://example.com/e.webp')
    const noStudio = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            preferred: { medium: 'https://example.com/p.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noStudio, 'presentation', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridDisplayUrl presentation falls back to Studio then Source when no preset meta', () => {
    const bare = {
        thumbnail_mode_urls: asset.thumbnail_mode_urls,
    }
    assert.equal(getExecutionGridDisplayUrl(bare, 'presentation', 'medium'), 'https://example.com/e.webp')
    const noStudioBare = {
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            preferred: { medium: 'https://example.com/p.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noStudioBare, 'presentation', 'medium'), 'https://example.com/o.webp')
})

test('resolveExecutionGridThumbnail presentation sets usePresentationCss only with preset', () => {
    const r = resolveExecutionGridThumbnail(asset, 'presentation', 'medium')
    assert.equal(r.imageUrl, 'https://example.com/e.webp')
    assert.equal(r.usePresentationCss, true)
    assert.equal(r.presentationPreset, 'neutral_studio')
    const bare = { thumbnail_mode_urls: asset.thumbnail_mode_urls }
    const f = resolveExecutionGridThumbnail(bare, 'presentation', 'medium')
    assert.equal(f.usePresentationCss, false)
    assert.equal(f.presentationPreset, null)
    assert.equal(f.imageUrl, 'https://example.com/e.webp')
})

test('getExecutionGridDisplayUrl ai cascades presentation raster then Studio then Source', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'ai', 'medium'), 'https://example.com/pr.webp')
    const noPres = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
            enhanced: { medium: 'https://example.com/e.webp' },
        },
    }
    assert.equal(getExecutionGridDisplayUrl(noPres, 'ai', 'medium'), 'https://example.com/e.webp')
})

test('getExecutionGridDisplayUrl clean legacy prefers preferred', () => {
    assert.equal(getExecutionGridDisplayUrl(asset, 'clean', 'medium'), 'https://example.com/p.webp')
})

test('getExecutionGridHoverCrossfadeUrl returns original when display differs from original', () => {
    assert.equal(getExecutionGridHoverCrossfadeUrl(asset, 'enhanced', 'medium'), 'https://example.com/o.webp')
    assert.equal(getExecutionGridHoverCrossfadeUrl(asset, 'ai', 'medium'), 'https://example.com/o.webp')
})

test('per-asset preferred tier applies only in Standard mode; global Pres. ignores drawer pref', () => {
    const id = 'asset-pref-standard-only'
    lsStore[PREF_V2] = JSON.stringify({
        [id]: 'original',
    })
    const a = { ...asset, id }
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/o.webp')
    assert.equal(getExecutionGridDisplayUrl(a, 'presentation', 'medium'), 'https://example.com/e.webp')
    lsStore[PREF_V2] = JSON.stringify({
        [id]: 'ai',
    })
    assert.equal(getExecutionGridDisplayUrl(a, 'enhanced', 'medium'), 'https://example.com/e.webp')
    assert.equal(getExecutionGridDisplayUrl(a, 'ai', 'medium'), 'https://example.com/pr.webp')
    delete lsStore[PREF_V2]
})

test('getExecutionGridDisplayUrl standard uses preferred tier from localStorage v2', () => {
    const id = 'asset-standard-test'
    lsStore[PREF_V2] = JSON.stringify({
        [id]: 'ai',
    })
    const a = { ...asset, id }
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/pr.webp')
    lsStore[PREF_V2] = JSON.stringify({
        [id]: 'enhanced',
    })
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/e.webp')
    lsStore[PREF_V2] = JSON.stringify({})
    assert.equal(getExecutionGridDisplayUrl(a, 'standard', 'medium'), 'https://example.com/o.webp')
})

test('getExecutionGridHoverCrossfadeUrl returns null when display is original', () => {
    const onlyOriginal = {
        ...presentationMeta,
        thumbnail_mode_urls: {
            original: { medium: 'https://example.com/o.webp' },
        },
    }
    assert.equal(getExecutionGridHoverCrossfadeUrl(onlyOriginal, 'enhanced', 'medium'), null)
    assert.equal(getExecutionGridHoverCrossfadeUrl(onlyOriginal, 'original', 'medium'), null)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import {
    audioCardIntrinsicMinHeightPx,
    audioMasonryWrapperStyle,
} from './audioCardSizing.js'

/*
 * Locks the fix for the staging bug where audio assets rendered as fully
 * white cards in masonry layout — `AudioCardVisual` paints with `h-full`,
 * but masonry parents only set `min-height` so percentage heights resolved
 * to 0 and the parent's bg-gray-50 showed through. Two layered defenses:
 *   1. Wrapper at the AssetCard call site sets explicit pixel height.
 *   2. AudioCardVisual itself sets a pixel `min-height` floor.
 */

test('non-masonry returns null wrapper (grid path keeps aspect-ratio container)', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: false,
        masonryThumbnailMinHeightPx: 165,
        masonryMaxHeightPx: 560,
    })
    assert.equal(out, null)
})

test('masonry with both bounds returns explicit height + maxHeight', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: true,
        masonryThumbnailMinHeightPx: 165,
        masonryMaxHeightPx: 560,
    })
    assert.deepEqual(out, { height: 165, maxHeight: 560, width: '100%' })
})

test('masonry caps height at maxHeight when min would exceed max', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: true,
        masonryThumbnailMinHeightPx: 800,
        masonryMaxHeightPx: 560,
    })
    assert.equal(out.height, 560,
        'Audio tile must not blow past the masonry maxHeight — that would shove neighbors off the column')
    assert.equal(out.maxHeight, 560)
})

test('masonry without minHeight returns null (caller falls back to default)', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: true,
        masonryThumbnailMinHeightPx: undefined,
        masonryMaxHeightPx: 560,
    })
    assert.equal(out, null)
})

test('masonry with non-finite values is treated as missing', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: true,
        masonryThumbnailMinHeightPx: NaN,
        masonryMaxHeightPx: 560,
    })
    assert.equal(out, null)
})

test('masonry with min only (no max) still produces a sized wrapper', () => {
    const out = audioMasonryWrapperStyle({
        isMasonry: true,
        masonryThumbnailMinHeightPx: 200,
        masonryMaxHeightPx: undefined,
    })
    assert.equal(out.height, 200)
    assert.equal(out.maxHeight, 200,
        'maxHeight collapses to the height when caller did not supply one — never undefined-leaking into inline style')
})

test('intrinsic min-height is largest in lightbox, smallest in card', () => {
    const card = audioCardIntrinsicMinHeightPx('card')
    const drawer = audioCardIntrinsicMinHeightPx('drawer')
    const lightbox = audioCardIntrinsicMinHeightPx('lightbox')
    assert.ok(card > 0)
    assert.ok(drawer > card,
        'drawer rail should be taller than a grid tile')
    assert.ok(lightbox > drawer,
        'modal player needs the most room for the bigger waveform + transport')
})

test('intrinsic min-height defaults to card variant for unknown sizes', () => {
    assert.equal(audioCardIntrinsicMinHeightPx(undefined), audioCardIntrinsicMinHeightPx('card'))
    assert.equal(audioCardIntrinsicMinHeightPx('garbage'), audioCardIntrinsicMinHeightPx('card'),
        'Unknown size variants must not return 0 / NaN — that would re-introduce the white-card collapse')
})

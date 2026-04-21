import test from 'node:test'
import assert from 'node:assert/strict'
import {
    orderExportCompositionIdsHeroFirst,
    sanitizeExportSegment,
    studioHandoffBundleZipFilename,
    studioHandoffVersionRasterFilename,
    zeroPadSequence,
} from './studioVersionsExportNaming.mjs'

test('sanitizeExportSegment trims and strips unsafe chars', () => {
    assert.equal(sanitizeExportSegment('  Hello World!!  '), 'Hello_World')
})

test('zeroPadSequence widens with total', () => {
    assert.equal(zeroPadSequence(3, 12), '03')
    assert.equal(zeroPadSequence(10, 100), '010')
})

test('studioHandoffVersionRasterFilename marks hero', () => {
    const n = studioHandoffVersionRasterFilename({
        index1Based: 1,
        totalCount: 5,
        label: 'My Ad',
        compositionId: '99',
        heroCompositionId: '99',
        ext: 'png',
    })
    assert.match(n, /^01_HERO_/)
    assert.ok(n.endsWith('.png'))
})

test('studioHandoffBundleZipFilename is stable shape', () => {
    const z = studioHandoffBundleZipFilename({
        setName: 'Spring Drop',
        setId: 'cs-abc',
        rasterKind: 'png',
        stamp: '2026-04-22_12-00-00',
    })
    assert.ok(z.startsWith('Studio-Versions_'))
    assert.ok(z.endsWith('.zip'))
})

test('orderExportCompositionIdsHeroFirst pulls hero only when present', () => {
    assert.deepEqual(orderExportCompositionIdsHeroFirst(['a', 'b', 'c'], null), ['a', 'b', 'c'])
    assert.deepEqual(orderExportCompositionIdsHeroFirst(['a', 'b', 'c'], 'x'), ['a', 'b', 'c'])
    assert.deepEqual(orderExportCompositionIdsHeroFirst(['a', 'b', 'c'], 'b'), ['b', 'a', 'c'])
})

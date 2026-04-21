import test from 'node:test'
import assert from 'node:assert/strict'
import {
    buildSameColorSelection,
    buildSameFormatSelection,
    buildSameSceneSelection,
} from './studioCreativeSetAxisQuickTarget.mjs'

const mk = (composition_id, axis) => ({ composition_id, axis })

test('same scene: selects siblings with matching scene id', () => {
    const variants = [
        mk('1', { scene: { id: 's-a', label: 'Indoor' }, color: { id: 'c-1', label: 'Red' } }),
        mk('2', { scene: { id: 's-a', label: 'Indoor' }, color: { id: 'c-2', label: 'Blue' } }),
        mk('3', { scene: { id: 's-b', label: 'Outdoor' }, color: { id: 'c-1', label: 'Red' } }),
    ]
    const r = buildSameSceneSelection(variants, '1')
    assert.equal(r.disabled, 'none')
    assert.deepEqual(r.ids, ['2'])
    assert.equal(r.ref?.id, 's-a')
})

test('same scene: missing axis on current version', () => {
    const variants = [mk('1', {}), mk('2', { scene: { id: 's-a', label: 'A' } })]
    const r = buildSameSceneSelection(variants, '1')
    assert.equal(r.disabled, 'missing_axis')
    assert.deepEqual(r.ids, [])
})

test('same scene: no other matches', () => {
    const variants = [mk('1', { scene: { id: 's-a', label: 'Only' } }), mk('2', { scene: { id: 's-b', label: 'Other' } })]
    const r = buildSameSceneSelection(variants, '1')
    assert.equal(r.disabled, 'no_matches')
    assert.deepEqual(r.ids, [])
    assert.equal(r.ref?.id, 's-a')
})

test('same scene: falls back to label match when id absent', () => {
    const variants = [
        mk('1', { scene: { label: 'Lifestyle' } }),
        mk('2', { scene: { label: 'Lifestyle' } }),
        mk('3', { scene: { label: 'Product' } }),
    ]
    const r = buildSameSceneSelection(variants, '1')
    assert.equal(r.disabled, 'none')
    assert.deepEqual(r.ids, ['2'])
})

test('same color: selects siblings with matching color id', () => {
    const variants = [
        mk('10', { color: { id: 'c-x', label: 'Navy' }, scene: { id: 's-1', label: 'A' } }),
        mk('11', { color: { id: 'c-x', label: 'Navy' }, scene: { id: 's-2', label: 'B' } }),
        mk('12', { color: { id: 'c-y', label: 'Gold' }, scene: { id: 's-1', label: 'A' } }),
    ]
    const r = buildSameColorSelection(variants, '10')
    assert.equal(r.disabled, 'none')
    assert.deepEqual(r.ids, ['11'])
})

test('same color: missing axis on current version', () => {
    const variants = [mk('1', { scene: { id: 's', label: 'S' } }), mk('2', { color: { id: 'c', label: 'C' } })]
    const r = buildSameColorSelection(variants, '1')
    assert.equal(r.disabled, 'missing_axis')
})

test('same format: matches by format id', () => {
    const variants = [
        mk('1', { format: { id: 'portrait_1080x1350', label: 'Portrait', width: 1080, height: 1350 } }),
        mk('2', { format: { id: 'portrait_1080x1350', label: 'Portrait', width: 1080, height: 1350 } }),
        mk('3', { format: { id: 'square_1080', label: 'Square', width: 1080, height: 1080 } }),
    ]
    const r = buildSameFormatSelection(variants, '1')
    assert.equal(r.disabled, 'none')
    assert.deepEqual(r.ids, ['2'])
})

test('same format: missing on current version', () => {
    const variants = [mk('1', { color: { id: 'c', label: 'C' } }), mk('2', { format: { id: 'square_1080', label: 'Sq', width: 1080, height: 1080 } })]
    const r = buildSameFormatSelection(variants, '1')
    assert.equal(r.disabled, 'missing_axis')
})

test('explicit selection path: preset ids are composition ids only (no source)', () => {
    const variants = [
        mk('src', { scene: { id: 's1', label: 'S' }, color: { id: 'c1', label: 'C' } }),
        mk('a', { scene: { id: 's1', label: 'S' }, color: { id: 'c2', label: 'C2' } }),
        mk('b', { scene: { id: 's1', label: 'S' }, color: { id: 'c3', label: 'C3' } }),
    ]
    const r = buildSameSceneSelection(variants, 'src')
    assert.deepEqual(new Set(r.ids), new Set(['a', 'b']))
    assert.ok(!r.ids.includes('src'))
})

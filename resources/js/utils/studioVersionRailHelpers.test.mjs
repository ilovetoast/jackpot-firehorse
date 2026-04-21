import test from 'node:test'
import assert from 'node:assert/strict'
import {
    getBaseCompositionId,
    getVariantAxisChipTexts,
    shouldShowVersionHints,
    variantHasAxisMetadata,
} from './studioVersionRailHelpers.mjs'

const v = (composition_id, sort_order, axis) => ({ composition_id, sort_order, axis })

test('base is lowest sort_order', () => {
    assert.equal(getBaseCompositionId([v('b', 2, {}), v('a', 0, {}), v('c', 1, {})]), 'a')
})

test('axis chips collect color scene format', () => {
    const chips = getVariantAxisChipTexts({
        color: { label: 'Navy' },
        scene: { label: 'Studio' },
        format: { label: 'Portrait', width: 1080, height: 1350 },
    })
    assert.deepEqual(chips, ['Navy', 'Studio', 'Portrait'])
})

test('variantHasAxisMetadata', () => {
    assert.equal(variantHasAxisMetadata({ color: { id: 'x' } }), true)
    assert.equal(variantHasAxisMetadata({}), false)
})

test('shouldShowVersionHints for low counts only', () => {
    assert.equal(shouldShowVersionHints(1), true)
    assert.equal(shouldShowVersionHints(2), true)
    assert.equal(shouldShowVersionHints(3), false)
    assert.equal(shouldShowVersionHints(0), false)
})

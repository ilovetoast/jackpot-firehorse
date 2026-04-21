import test from 'node:test'
import assert from 'node:assert/strict'
import {
    mergeHeroAndAlternatesForExport,
    sortCompositionIdsByVariantSortOrder,
} from './studioVersionsHandoffHelpers.mjs'

const v = (cid, order) => ({ composition_id: cid, sort_order: order })

test('sortCompositionIdsByVariantSortOrder orders by sort_order', () => {
    const variants = [v('c', 2), v('a', 0), v('b', 1)]
    assert.deepEqual(sortCompositionIdsByVariantSortOrder(['c', 'a'], variants), ['a', 'c'])
})

test('mergeHeroAndAlternatesForExport dedupes and orders', () => {
    const variants = [v('h', 0), v('x', 2), v('y', 1)]
    assert.deepEqual(mergeHeroAndAlternatesForExport('h', ['y', 'x', 'h'], variants), ['h', 'y', 'x'])
})

test('mergeHeroAndAlternatesForExport without hero is just sorted alts', () => {
    const variants = [v('a', 1), v('b', 0)]
    assert.deepEqual(mergeHeroAndAlternatesForExport(null, ['a', 'b'], variants), ['b', 'a'])
})

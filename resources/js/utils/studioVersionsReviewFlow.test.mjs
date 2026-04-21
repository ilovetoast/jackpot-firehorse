import test from 'node:test'
import assert from 'node:assert/strict'
import { firstScrollTargetCompositionId, nextNewCompositionId } from './studioVersionsReviewFlow.mjs'

const v = (cid, order) => ({ composition_id: cid, sort_order: order })

test('nextNew wraps among unviewed newcomers', () => {
    const sorted = [v('a', 0), v('b', 1), v('c', 2)]
    assert.equal(nextNewCompositionId(sorted, 'b', ['b', 'c'], new Set()), 'c')
    assert.equal(nextNewCompositionId(sorted, 'c', ['b', 'c'], new Set()), 'b')
})

test('nextNew when current not in pool goes to first', () => {
    const sorted = [v('a', 0), v('b', 1)]
    assert.equal(nextNewCompositionId(sorted, 'x', ['b'], new Set()), 'b')
})

test('firstScrollTarget prefers first unviewed in sort order', () => {
    const sorted = [v('a', 0), v('b', 1), v('c', 2)]
    assert.equal(firstScrollTargetCompositionId(sorted, ['c', 'b'], new Set()), 'b')
})

test('firstScrollTarget when all viewed uses first newcomer in sort order', () => {
    const sorted = [v('a', 0), v('b', 1), v('c', 2)]
    const viewed = new Set(['b', 'c'])
    assert.equal(firstScrollTargetCompositionId(sorted, ['c', 'b'], viewed), 'b')
})

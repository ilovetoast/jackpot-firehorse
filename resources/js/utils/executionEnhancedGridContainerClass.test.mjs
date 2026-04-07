import test from 'node:test'
import assert from 'node:assert/strict'
import { executionEnhancedGridContainerClass } from './executionEnhancedGridContainerClass.js'

test('grid container has no extra wrapper chrome for deliverables thumbnail modes', () => {
    assert.equal(executionEnhancedGridContainerClass(null), '')
    assert.equal(executionEnhancedGridContainerClass('original'), '')
    assert.equal(executionEnhancedGridContainerClass('clean'), '')
    assert.equal(executionEnhancedGridContainerClass('enhanced'), '')
    assert.equal(executionEnhancedGridContainerClass('presentation'), '')
})

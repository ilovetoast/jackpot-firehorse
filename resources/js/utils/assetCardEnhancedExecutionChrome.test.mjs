import test from 'node:test'
import assert from 'node:assert/strict'
import {
    assetCardEnhancedExecutionChromeClass,
    isExecutionEnhancedGridMode,
} from './assetCardEnhancedExecutionChrome.js'

test('isExecutionEnhancedGridMode is true for enhanced and presentation grid modes', () => {
    assert.equal(isExecutionEnhancedGridMode(null), false)
    assert.equal(isExecutionEnhancedGridMode('original'), false)
    assert.equal(isExecutionEnhancedGridMode('clean'), false)
    assert.equal(isExecutionEnhancedGridMode('enhanced'), true)
    assert.equal(isExecutionEnhancedGridMode('presentation'), true)
})

test('assetCardEnhancedExecutionChromeClass applies for polished grid modes', () => {
    assert.equal(assetCardEnhancedExecutionChromeClass(null), '')
    assert.equal(assetCardEnhancedExecutionChromeClass('original'), '')
    assert.equal(assetCardEnhancedExecutionChromeClass('clean'), '')
    assert.ok(assetCardEnhancedExecutionChromeClass('enhanced').includes('shadow-lg'))
    assert.ok(assetCardEnhancedExecutionChromeClass('presentation').includes('shadow-lg'))
})

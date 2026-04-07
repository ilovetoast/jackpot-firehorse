import test, { afterEach } from 'node:test'
import assert from 'node:assert/strict'
import {
    markEnhancedComparisonSeenForTemplate,
    shouldShowEnhancedComparisonForTemplate,
} from './enhancedPreviewComparisonStorage.js'

function mockWindowStorage() {
    const store = new Map()
    const ls = {
        getItem(k) {
            return store.has(k) ? store.get(k) : null
        },
        setItem(k, v) {
            store.set(k, String(v))
        },
        removeItem(k) {
            store.delete(k)
        },
    }
    globalThis.window = { localStorage: ls }
    return store
}

afterEach(() => {
    delete globalThis.window
})

test('comparison modal shows until dismissed for a template key', () => {
    mockWindowStorage()
    assert.equal(shouldShowEnhancedComparisonForTemplate('neutral|1.0.0'), true)
    markEnhancedComparisonSeenForTemplate('neutral|1.0.0')
    assert.equal(shouldShowEnhancedComparisonForTemplate('neutral|1.0.0'), false)
})

test('comparison modal shows again after template_version changes (storage key mismatch)', () => {
    mockWindowStorage()
    markEnhancedComparisonSeenForTemplate('neutral|1.0.0')
    assert.equal(shouldShowEnhancedComparisonForTemplate('neutral|1.0.0'), false)
    assert.equal(shouldShowEnhancedComparisonForTemplate('neutral|1.1.0'), true)
})

test('dismissing with new key does not show until template identity changes again', () => {
    mockWindowStorage()
    markEnhancedComparisonSeenForTemplate('neutral|1.1.0')
    assert.equal(shouldShowEnhancedComparisonForTemplate('neutral|1.1.0'), false)
})

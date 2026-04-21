import test from 'node:test'
import assert from 'node:assert/strict'
import { combinationKeys, labelForCombinationKey } from './studioVersionsGenerationCartesian.mjs'

test('combinationKeys color-only pack', () => {
    assert.deepEqual(combinationKeys(['a', 'b', 'c'], [], []), ['c:a', 'c:b', 'c:c'])
})

test('combinationKeys format-only pack', () => {
    assert.deepEqual(combinationKeys([], [], ['f1', 'f2']), ['f:f1', 'f:f2'])
})

test('combinationKeys color x scene', () => {
    const k = combinationKeys(['c1'], ['s1', 's2'], [])
    assert.deepEqual(k.sort(), ['c:c1|s:s1', 'c:c1|s:s2'].sort())
})

test('labelForCombinationKey joins labels', () => {
    const presets = {
        preset_colors: [{ id: 'c1', label: 'Navy' }],
        preset_scenes: [{ id: 's1', label: 'Studio' }],
        preset_formats: [{ id: 'f1', label: 'Story' }],
    }
    assert.equal(labelForCombinationKey('c:c1|s:s1|f:f1', presets), 'Navy · Studio · Story')
})

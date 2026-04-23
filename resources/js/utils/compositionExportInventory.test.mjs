/**
 * Lightweight checks for export inventory rules (mirrors
 * {@link ../components/studio/composition/exportAssetInventory.ts} supported set).
 */
import test from 'node:test'
import assert from 'node:assert/strict'

const SUPPORTED_LAYER_TYPES = new Set([
    'image',
    'text',
    'generative_image',
    'fill',
    'mask',
    'video',
])

function listUnsupportedLayerTypes(layers) {
    const u = new Set()
    for (const l of layers) {
        const t = l.type ?? 'unknown'
        if (!SUPPORTED_LAYER_TYPES.has(t)) {
            u.add(t)
        }
    }
    return [...u]
}

test('listUnsupportedLayerTypes is empty for a normal studio stack', () => {
    const layers = [
        { type: 'fill' },
        { type: 'image' },
        { type: 'text' },
        { type: 'generative_image' },
        { type: 'mask' },
        { type: 'video' },
    ]
    assert.deepEqual(listUnsupportedLayerTypes(layers), [])
})

test('listUnsupportedLayerTypes surfaces unknown types', () => {
    assert.deepEqual(listUnsupportedLayerTypes([{ type: 'text' }, { type: 'particle_field' }]), ['particle_field'])
})

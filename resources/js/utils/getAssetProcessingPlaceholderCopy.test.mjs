import test from 'node:test'
import assert from 'node:assert/strict'
import { getAssetProcessingPlaceholderCopy } from './getAssetProcessingPlaceholderCopy.js'

test('RAW CR2 processing copy', () => {
    const asset = { id: 1, file_extension: 'cr2', mime_type: 'image/x-canon-cr2' }
    const vs = {
        kind: 'raw_processing',
        label: 'RAW preview',
        description: 'RAW files may take longer to process.',
        badgeShort: 'RAW',
    }
    const c = getAssetProcessingPlaceholderCopy(asset, vs, null)
    assert.equal(c.headline, 'RAW preview processing')
    assert.match(c.helper, /RAW files may take longer/i)
    assert.equal(c.animate, true)
})

test('JPG generating preview copy', () => {
    const asset = { id: 2, file_extension: 'jpg', mime_type: 'image/jpeg' }
    const vs = {
        kind: 'generating_preview',
        label: 'Generating preview',
        description: 'Usually under a minute.',
        badgeShort: 'Processing',
    }
    const c = getAssetProcessingPlaceholderCopy(asset, vs, null)
    assert.equal(c.headline, 'Generating preview')
    assert.match(c.helper, /Usually under a minute/i)
})

test('PDF generic processing headline', () => {
    const asset = { id: 22, file_extension: 'pdf', mime_type: 'application/pdf' }
    const vs = {
        kind: 'generating_preview',
        label: '',
        description: 'Usually under a minute.',
        badgeShort: 'Processing',
    }
    const c = getAssetProcessingPlaceholderCopy(asset, vs, null)
    assert.equal(c.headline, 'Preview processing')
})

test('video processing headline', () => {
    const asset = { id: 3, file_extension: 'mp4', mime_type: 'video/mp4' }
    const vs = {
        kind: 'video_processing',
        label: 'Video',
        description: 'Poster or preview is still generating.',
        badgeShort: 'Video',
    }
    const c = getAssetProcessingPlaceholderCopy(asset, vs, null)
    assert.equal(c.headline, 'Preview processing')
    assert.equal(c.videoPlaySlot, true)
})

test('failed preview does not animate', () => {
    const asset = { id: 4, file_extension: 'jpg', mime_type: 'image/jpeg', thumbnail_status: 'failed' }
    const vs = {
        kind: 'failed',
        label: 'Preview failed',
        description: 'The original file is still available.',
        badgeShort: 'Failed',
    }
    const c = getAssetProcessingPlaceholderCopy(asset, vs, null)
    assert.equal(c.animate, false)
})

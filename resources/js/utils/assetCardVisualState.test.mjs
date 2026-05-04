import test from 'node:test'
import assert from 'node:assert/strict'
import {
    getAssetCardVisualState,
    assetThumbnailPollEligible,
    hasServerRasterThumbnail,
} from './assetCardVisualState.js'
import { computeThumbnailPipelineGridSummary } from './assetGridPipelineSummary.js'

test('RAW CR2 without server thumbnail shows raw_processing', () => {
    const asset = {
        id: 101,
        file_extension: 'cr2',
        mime_type: 'image/x-canon-cr2',
        thumbnail_status: 'pending',
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'raw_processing')
    assert.equal(vs.label, 'RAW preview')
    assert.match(vs.description, /RAW/i)
    assert.equal(vs.badgeShort, 'RAW')
    assert.equal(vs.showThumbnail, false)
    assert.equal(vs.showFileTypeCard, true)
})

test('JPG without thumbnail and pending shows generating_preview', () => {
    const asset = {
        id: 102,
        file_extension: 'jpg',
        mime_type: 'image/jpeg',
        thumbnail_status: 'processing',
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'generating_preview')
    assert.equal(vs.label, 'Generating preview')
    assert.equal(vs.badgeShort, 'Processing')
})

test('JPG pending with original URL uses ready (grid fallback for stuck derivatives)', () => {
    const asset = {
        id: 1021,
        file_extension: 'jpg',
        mime_type: 'image/jpeg',
        thumbnail_status: 'processing',
        original: 'https://cdn.example.com/o.jpg',
    }
    assert.equal(hasServerRasterThumbnail(asset), true)
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'ready')
    assert.equal(vs.badgeShort, '')
})

test('pending_finalize_client_tile with unknown MIME still gets generating_preview (animated mosaic path)', () => {
    const asset = {
        id: 'client-uuid-1',
        file_extension: '',
        mime_type: 'application/octet-stream',
        thumbnail_status: 'pending',
        pending_finalize_client_tile: true,
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'generating_preview')
    assert.equal(vs.label, 'Generating preview')
})

test('failed preview shows failed kind and danger tone', () => {
    const asset = {
        id: 103,
        file_extension: 'jpg',
        mime_type: 'image/jpeg',
        thumbnail_status: 'failed',
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'failed')
    assert.equal(vs.badgeTone, 'danger')
    assert.equal(vs.badgeShort, 'Failed')
})

test('processing_failed in metadata yields failed', () => {
    const asset = {
        id: 104,
        file_extension: 'nef',
        mime_type: 'image/jpeg',
        thumbnail_status: 'processing',
        metadata: { processing_failed: true },
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'failed')
})

test('local ephemeral preview takes local_preview when no final thumb', () => {
    const asset = {
        id: 105,
        file_extension: 'png',
        mime_type: 'image/png',
        thumbnail_status: 'pending',
    }
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: 'blob:http://x/y' })
    assert.equal(vs.kind, 'local_preview')
    assert.equal(vs.showLocalPreview, true)
})

test('hasServerRasterThumbnail true yields ready', () => {
    const asset = {
        id: 106,
        file_extension: 'jpg',
        mime_type: 'image/jpeg',
        preview_thumbnail_url: 'https://example.com/p.jpg',
    }
    assert.equal(hasServerRasterThumbnail(asset), true)
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'ready')
    assert.equal(vs.badgeShort, '')
})

test('assetThumbnailPollEligible is true for pending supported type without final', () => {
    assert.equal(
        assetThumbnailPollEligible({
            id: 1,
            mime_type: 'image/jpeg',
            file_extension: 'jpg',
            thumbnail_status: 'pending',
        }),
        true,
    )
    assert.equal(
        assetThumbnailPollEligible({
            id: 1,
            mime_type: 'image/jpeg',
            file_extension: 'jpg',
            final_thumbnail_url: 'https://x/f.jpg',
            thumbnail_status: 'pending',
        }),
        false,
    )
})

test('computeThumbnailPipelineGridSummary counts rawProcessing', () => {
    const assets = [
        {
            id: 1,
            file_extension: 'cr2',
            mime_type: 'image/x-canon-cr2',
            thumbnail_status: 'pending',
        },
        {
            id: 2,
            file_extension: 'jpg',
            mime_type: 'image/jpeg',
            thumbnail_status: 'pending',
        },
    ]
    const s = computeThumbnailPipelineGridSummary(assets)
    assert.equal(s.processing, 2)
    assert.equal(s.rawProcessing, 1)
    assert.equal(s.attention, 0)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { syncDamFileTypesFromPage } from './damFileTypes.js'
import {
    getAssetCardVisualState,
    assetThumbnailPollEligible,
    hasServerRasterThumbnail,
} from './assetCardVisualState.js'
import { computeThumbnailPipelineGridSummary } from './assetGridPipelineSummary.js'

const DAM_MINIMAL = {
    thumbnail_mime_types: ['image/jpeg', 'image/png', 'image/x-canon-cr2'],
    thumbnail_extensions: ['jpg', 'jpeg', 'png', 'cr2'],
    upload_mime_types: ['image/jpeg', 'image/png'],
    upload_extensions: ['jpg', 'png', 'cr2'],
    upload_accept: '',
    thumbnail_accept: '',
    types_for_help: [],
    grid_file_type_filter_options: { grouped: [] },
}

test.beforeEach(() => {
    syncDamFileTypesFromPage({ props: { dam_file_types: DAM_MINIMAL } })
})

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

test('GLB with real preview_3d_poster_url counts as server raster thumbnail', () => {
    syncDamFileTypesFromPage({
        props: {
            dam_file_types: {
                ...DAM_MINIMAL,
                types_for_help: [
                    { key: 'model_glb', name: 'glTF Binary', extensions: ['glb'], status: 'enabled', enabled: true },
                ],
            },
        },
    })
    const asset = {
        id: 201,
        file_extension: 'glb',
        mime_type: 'model/gltf-binary',
        thumbnail_status: 'pending',
        preview_3d_poster_url: 'https://cdn.example.com/p.webp',
        preview_3d_poster_is_stub: false,
    }
    assert.equal(hasServerRasterThumbnail(asset), true)
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'ready')
})

test('GLB with stub poster does not count as server raster; grid shows failed (no neutral stub tile)', () => {
    syncDamFileTypesFromPage({
        props: {
            dam_file_types: {
                ...DAM_MINIMAL,
                types_for_help: [
                    { key: 'model_glb', name: 'glTF Binary', extensions: ['glb'], status: 'enabled', enabled: true },
                ],
            },
        },
    })
    const asset = {
        id: 202,
        file_extension: 'glb',
        mime_type: 'model/gltf-binary',
        thumbnail_status: 'completed',
        preview_3d_poster_url: 'https://cdn.example.com/stub.webp',
        preview_3d_poster_is_stub: true,
        final_thumbnail_url: 'https://cdn.example.com/final-from-stub.webp',
    }
    assert.equal(hasServerRasterThumbnail(asset), false)
    const vs = getAssetCardVisualState(asset, { ephemeralLocalPreviewUrl: null })
    assert.equal(vs.kind, 'failed')
    assert.equal(vs.showFileTypeCard, true)
    assert.equal(vs.badgeShort, '')
})

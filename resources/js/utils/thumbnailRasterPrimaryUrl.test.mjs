import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveRasterPrimaryThumbnailUrl } from './thumbnailRasterPrimaryUrl.js'
import { failedRasterThumbnailUrls } from './thumbnailRasterFailedCache.js'

const DAM = {
    types_for_help: [{ key: 'model_glb', extensions: ['glb'], status: 'enabled', enabled: true }],
}

test.beforeEach(() => {
    failedRasterThumbnailUrls.clear()
})
test('3D poster wins over final_thumbnail_url', () => {
    const failed = new Set()
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: 'https://cdn.example.com/poster.webp',
        final_thumbnail_url: 'https://cdn.example.com/thumb.webp',
        thumbnail_status: 'completed',
    }
    assert.equal(
        resolveRasterPrimaryThumbnailUrl(asset, false, failed, DAM),
        'https://cdn.example.com/poster.webp',
    )
})

test('non-3D uses final thumbnail', () => {
    const failed = new Set()
    const asset = {
        file_extension: 'jpg',
        mime_type: 'image/jpeg',
        final_thumbnail_url: 'https://cdn.example.com/final.webp',
        thumbnail_status: 'completed',
    }
    assert.equal(
        resolveRasterPrimaryThumbnailUrl(asset, false, failed, DAM),
        'https://cdn.example.com/final.webp',
    )
})

test('failed poster falls back to final', () => {
    const poster = 'https://cdn.example.com/poster.webp'
    const failed = new Set([poster])
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: poster,
        final_thumbnail_url: 'https://cdn.example.com/final.webp',
        thumbnail_status: 'completed',
    }
    assert.equal(
        resolveRasterPrimaryThumbnailUrl(asset, false, failed, DAM),
        'https://cdn.example.com/final.webp',
    )
})

test('shared failedRasterThumbnailUrls cache excludes poster for resolver', () => {
    const poster = 'https://cdn.example.com/poster.webp'
    failedRasterThumbnailUrls.add(poster)
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: poster,
        final_thumbnail_url: 'https://cdn.example.com/final.webp',
        thumbnail_status: 'completed',
    }
    assert.equal(
        resolveRasterPrimaryThumbnailUrl(asset, false, failedRasterThumbnailUrls, DAM),
        'https://cdn.example.com/final.webp',
    )
})

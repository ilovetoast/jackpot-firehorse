import test from 'node:test'
import assert from 'node:assert/strict'
import { getThumbnailVersion } from './thumbnailUtils.js'

test('getThumbnailVersion includes preview_3d viewer and revision', () => {
    const a = {
        thumbnail_url: '',
        final_thumbnail_url: '',
        preview_thumbnail_url: '',
        preview_3d_poster_url: 'https://cdn.example.com/p.webp',
        preview_3d_viewer_url: 'https://cdn.example.com/m.glb',
        preview_3d_revision: 'abc123deadbe',
        thumbnail_status: 'completed',
        updated_at: '2026-01-01',
    }
    const b = { ...a, preview_3d_revision: '000000000000' }
    assert.notEqual(getThumbnailVersion(a), getThumbnailVersion(b))
})

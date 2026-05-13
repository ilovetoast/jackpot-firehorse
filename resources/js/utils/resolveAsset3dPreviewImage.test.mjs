import test from 'node:test'
import assert from 'node:assert/strict'
import { syncDamFileTypesFromPage } from './damFileTypes.js'
import {
    getRegistryModel3dPosterDisplayUrl,
    getRegistryModelGlbModelSourceUrl,
    isRegistryModel3dAsset,
    isRegistryModel3dPosterStub,
    isRegistryModelGlbAsset,
    getRegistryModelGlbViewerDisplayUrl,
    shouldShowRealtimeGlbModelViewer,
} from './resolveAsset3dPreviewImage.js'

const DAM_STUB = {
    thumbnail_mime_types: [],
    thumbnail_extensions: [],
    upload_mime_types: ['model/gltf-binary'],
    upload_extensions: ['glb'],
    upload_accept: '',
    thumbnail_accept: '',
    types_for_help: [
        {
            key: 'model_glb',
            name: 'glTF Binary',
            extensions: ['glb'],
            status: 'enabled',
            enabled: true,
        },
    ],
    grid_file_type_filter_options: { grouped: [] },
}

const DAM_GLB_STL = {
    ...DAM_STUB,
    types_for_help: [
        ...DAM_STUB.types_for_help,
        { key: 'model_stl', name: 'STL', extensions: ['stl'], status: 'enabled', enabled: true },
    ],
}

test('model_glb with glb extension is registry 3D', () => {
    syncDamFileTypesFromPage({ props: { dam_file_types: DAM_STUB } })
    const asset = { file_extension: 'glb', mime_type: 'model/gltf-binary' }
    assert.equal(isRegistryModel3dAsset(asset, DAM_STUB), true)
    assert.equal(isRegistryModel3dAsset({ file_extension: 'jpg' }, DAM_STUB), false)
})

test('poster URL omitted when pipeline stub (API flag)', () => {
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: 'https://cdn.example.com/stub.webp',
        preview_3d_poster_is_stub: true,
    }
    assert.equal(isRegistryModel3dPosterStub(asset), true)
    assert.equal(getRegistryModel3dPosterDisplayUrl(asset, new Set(), DAM_STUB), null)
})

test('poster URL omitted when pipeline stub (metadata debug)', () => {
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: 'https://cdn.example.com/stub.webp',
        metadata: { preview_3d: { debug: { poster_stub: true } } },
    }
    assert.equal(getRegistryModel3dPosterDisplayUrl(asset, new Set(), DAM_STUB), null)
})

test('poster URL returned for model asset when present', () => {
    const asset = {
        file_extension: 'glb',
        preview_3d_poster_url: 'https://cdn.example.com/poster.webp',
    }
    assert.equal(getRegistryModel3dPosterDisplayUrl(asset, new Set(), DAM_STUB), 'https://cdn.example.com/poster.webp')
})

test('broken poster URL excluded when in failed set', () => {
    const url = 'https://cdn.example.com/bad.webp'
    const failed = new Set([url])
    const asset = { file_extension: 'glb', preview_3d_poster_url: url }
    assert.equal(getRegistryModel3dPosterDisplayUrl(asset, failed, DAM_STUB), null)
})

test('non-3D asset never returns poster from helper', () => {
    const asset = {
        file_extension: 'jpg',
        preview_3d_poster_url: 'https://cdn.example.com/x.webp',
    }
    assert.equal(getRegistryModel3dPosterDisplayUrl(asset, new Set(), DAM_STUB), null)
})

test('GLB model source prefers preview_3d_viewer_url over original', () => {
    const asset = {
        file_extension: 'glb',
        preview_3d_viewer_url: 'https://cdn.example.com/from-meta.glb',
        original: 'https://cdn.example.com/original.glb',
    }
    assert.equal(getRegistryModelGlbModelSourceUrl(asset, DAM_STUB), 'https://cdn.example.com/from-meta.glb')
})

test('GLB model source falls back to signed original when viewer URL missing', () => {
    const asset = {
        file_extension: 'glb',
        original: 'https://cdn.example.com/original.glb?sig=1',
    }
    assert.equal(getRegistryModelGlbModelSourceUrl(asset, DAM_STUB), 'https://cdn.example.com/original.glb?sig=1')
    assert.equal(shouldShowRealtimeGlbModelViewer(asset, DAM_STUB, true), true)
})

test('shouldShowRealtimeGlbModelViewer when GLB, viewer URL, and DAM_3D', () => {
    const asset = {
        file_extension: 'glb',
        preview_3d_viewer_url: 'https://cdn.example.com/model.glb?sig=1',
    }
    assert.equal(shouldShowRealtimeGlbModelViewer(asset, DAM_STUB, true), true)
    assert.equal(shouldShowRealtimeGlbModelViewer(asset, DAM_STUB, false), false)
    assert.equal(shouldShowRealtimeGlbModelViewer({ ...asset, preview_3d_viewer_url: '  ' }, DAM_STUB, true), false)
    assert.equal(
        shouldShowRealtimeGlbModelViewer(
            { file_extension: 'glb', preview_3d_viewer_url: '  ', original: 'https://cdn.example.com/o.glb' },
            DAM_STUB,
            true,
        ),
        true,
    )
    assert.equal(getRegistryModelGlbViewerDisplayUrl(asset), 'https://cdn.example.com/model.glb?sig=1')
    assert.equal(isRegistryModelGlbAsset(asset, DAM_STUB), true)
})

test('STL with poster and hypothetical viewer URL does not enable GLB realtime viewer', () => {
    const stl = {
        file_extension: 'stl',
        preview_3d_poster_url: 'https://cdn.example.com/p.webp',
        preview_3d_viewer_url: 'https://cdn.example.com/m.glb',
    }
    assert.equal(isRegistryModel3dAsset(stl, DAM_GLB_STL), true)
    assert.equal(isRegistryModelGlbAsset(stl, DAM_GLB_STL), false)
    assert.equal(shouldShowRealtimeGlbModelViewer(stl, DAM_GLB_STL, true), false)
})

test('GLB file without model_glb registry row does not enable realtime viewer', () => {
    const damStlOnly = {
        ...DAM_STUB,
        types_for_help: [{ key: 'model_stl', extensions: ['stl'], status: 'enabled', enabled: true }],
    }
    const asset = { file_extension: 'glb', preview_3d_viewer_url: 'https://cdn.example.com/m.glb' }
    assert.equal(isRegistryModelGlbAsset(asset, damStlOnly), false)
    assert.equal(shouldShowRealtimeGlbModelViewer(asset, damStlOnly, true), false)
})

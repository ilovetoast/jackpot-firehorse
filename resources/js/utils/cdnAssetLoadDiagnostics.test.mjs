import assert from 'node:assert/strict'
import test from 'node:test'
import {
    cdnUrlForDisplayWithoutQuery,
    classifyAudioPlaybackFailure,
    getCdnPreviewFailureCopy,
    inferAudioDeliveryVariant,
    inferGlbDeliveryVariant,
    isProbablyCloudFrontSignedUrl,
} from './cdnAssetLoadDiagnostics.js'

test('isProbablyCloudFrontSignedUrl detects query signing', () => {
    assert.equal(
        isProbablyCloudFrontSignedUrl('https://cdn.example.com/a.glb?Signature=abc&Key-Pair-Id=K'),
        true,
    )
    assert.equal(isProbablyCloudFrontSignedUrl('https://cdn.example.com/a.glb'), false)
})

test('cdnUrlForDisplayWithoutQuery strips query', () => {
    assert.equal(
        cdnUrlForDisplayWithoutQuery('https://cdn.example.com/p/a.glb?Signature=secret'),
        'https://cdn.example.com/p/a.glb',
    )
})

test('inferGlbDeliveryVariant matches viewer vs original', () => {
    const asset = {
        preview_3d_viewer_url: 'https://cdn.example.com/t/m.glb?x=1',
        original: 'https://cdn.example.com/t/o.glb',
    }
    assert.equal(inferGlbDeliveryVariant(asset, 'https://cdn.example.com/t/m.glb?y=2'), 'preview_3d_glb')
    assert.equal(inferGlbDeliveryVariant(asset, 'https://cdn.example.com/t/o.glb'), 'original')
})

test('getCdnPreviewFailureCopy unauthorized mentions status', () => {
    const a = getCdnPreviewFailureCopy('unauthorized', 403)
    assert.match(a.primary, /403 Forbidden/)
    const b = getCdnPreviewFailureCopy('cors_or_unknown', null)
    assert.match(b.primary, /browser blocked access/i)
})

test('classifyAudioPlaybackFailure maps probe + media error', () => {
    assert.equal(classifyAudioPlaybackFailure({ category: 'unauthorized', httpStatus: 403 }, 4), 'unauthorized')
    assert.equal(classifyAudioPlaybackFailure({ category: 'ok', httpStatus: 200 }, 3), 'media_decode')
    assert.equal(classifyAudioPlaybackFailure({ category: 'ok', httpStatus: 200 }, null), 'play_rejected')
})

test('inferAudioDeliveryVariant', () => {
    const asset = {
        audio_playback_url: 'https://cdn.example.com/t/a.mp3?q=1',
        original_url: 'https://cdn.example.com/t/o.wav',
    }
    assert.equal(inferAudioDeliveryVariant(asset, 'https://cdn.example.com/t/a.mp3?x=2'), 'audio_web')
    assert.equal(inferAudioDeliveryVariant(asset, 'https://cdn.example.com/t/o.wav'), 'original')
})

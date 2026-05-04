import test from 'node:test'
import assert from 'node:assert/strict'
import { pendingUploadIdentityKey } from './uploadPreviewRegistry.js'

test('pendingUploadIdentityKey matches server title to client RAW filename', () => {
    assert.equal(pendingUploadIdentityKey('IMG_6562.CR2'), 'img6562')
    assert.equal(pendingUploadIdentityKey('Img 6562'), 'img6562')
    assert.equal(pendingUploadIdentityKey('img_6562.cr2'), 'img6562')
})

test('pendingUploadIdentityKey strips path and extension', () => {
    assert.equal(pendingUploadIdentityKey('/tmp/foo/BAR.NEF'), 'bar')
    assert.equal(pendingUploadIdentityKey('vacation.jpeg'), 'vacation')
})

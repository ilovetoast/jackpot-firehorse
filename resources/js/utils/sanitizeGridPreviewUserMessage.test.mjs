import test from 'node:test'
import assert from 'node:assert/strict'
import { sanitizeGridPreviewUserMessage } from './sanitizeGridPreviewUserMessage.js'

test('strips UUIDs from preview messages', () => {
    const s = sanitizeGridPreviewUserMessage('no preview 019e2739-1b98-702f-b8d3-a8e8e071d550')
    assert.equal(s, 'no preview')
})

test('returns empty when only UUIDs', () => {
    assert.equal(sanitizeGridPreviewUserMessage('019e2739-1b98-702f-b8d3-a8e8e071d550'), '')
})

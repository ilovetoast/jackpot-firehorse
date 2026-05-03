import test from 'node:test'
import assert from 'node:assert/strict'
import { getAssetPlaceholderTheme, sanitizeHexColor } from './getAssetPlaceholderTheme.js'

test('sanitizeHexColor normalizes 3-digit and 6-digit hex', () => {
    assert.equal(sanitizeHexColor('#aBc'), '#aabbcc')
    assert.equal(sanitizeHexColor('336699'), '#336699')
    assert.equal(sanitizeHexColor('nope', '#6366f1'), '#6366f1')
})

test('same asset id yields identical placeholder theme vars', () => {
    const a1 = { id: 42, file_extension: 'cr2' }
    const a2 = { id: 42, file_extension: 'nef' }
    const t = { primary_color: '#8844cc' }
    const s1 = getAssetPlaceholderTheme(a1, t).surfaceStyle
    const s2 = getAssetPlaceholderTheme(a2, t).surfaceStyle
    assert.equal(s1['--asset-placeholder-bg-1'], s2['--asset-placeholder-bg-1'])
})

test('different asset ids can yield different bg var', () => {
    const t = { primary_color: '#8844cc' }
    const s1 = getAssetPlaceholderTheme({ id: 1 }, t).surfaceStyle
    const s2 = getAssetPlaceholderTheme({ id: 999 }, t).surfaceStyle
    assert.notEqual(s1['--asset-placeholder-bg-1'], s2['--asset-placeholder-bg-1'])
})

test('surfaceStyle exposes expected CSS variables', () => {
    const s = getAssetPlaceholderTheme({ id: 7, file_extension: 'dng' }, { primary_color: '#112233' }).surfaceStyle
    assert.ok(typeof s['--asset-placeholder-bg-1'] === 'string')
    assert.ok(typeof s['--asset-placeholder-sheen'] === 'string')
    assert.ok(s.backgroundImage)
})

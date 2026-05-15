import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveQuickFilterTone } from './folderQuickFilterTone.js'

// Phase 4.4 — verify the brand-aware tone resolver returns a complete
// palette and tints the surface to match the sidebar's brand color.

const KEYS = [
    'isDark',
    'surface',
    'surfaceElevated',
    'border',
    'separator',
    'rowOpenBg',
    'rowHoverBg',
    'valueHoverBg',
    'valueSelectedBg',
    'labelStrong',
    'labelWeak',
    'countLabel',
    'indicatorBorder',
    'indicatorActiveBg',
    'indicatorActiveFg',
    'leftGuide',
    'shadow',
    'scrollbarThumb',
]

test('resolveQuickFilterTone: returns full palette for white textColor (dark sidebar)', () => {
    const t = resolveQuickFilterTone('#ffffff', '#5B2D7E', '#3a1d52')
    for (const k of KEYS) assert.ok(k in t, `missing key: ${k}`)
    assert.equal(t.isDark, true)
})

test('resolveQuickFilterTone: surface tints from sidebarColor when provided', () => {
    const t = resolveQuickFilterTone('#ffffff', '#5b2d7e')
    // 0x5b=91, 0x2d=45, 0x7e=126
    assert.match(t.surface, /^rgba\(91,\s*45,\s*126/)
})

test('resolveQuickFilterTone: valueSelectedBg uses sidebarActiveBg when provided', () => {
    const t = resolveQuickFilterTone('#ffffff', '#5b2d7e', '#3a1d52')
    assert.equal(t.valueSelectedBg, '#3a1d52')
})

test('resolveQuickFilterTone: valueSelectedBg falls back to a darken of the sidebar when no active bg', () => {
    const t = resolveQuickFilterTone('#ffffff', '#5b2d7e')
    // Should be a hex string darker than the original. With dark sidebar
    // and -0.20 darken, R=91 → 91*(1-0.20)=72.8 → 73 → 0x49.
    assert.match(t.valueSelectedBg, /^#[0-9a-f]{6}$/)
    assert.notEqual(t.valueSelectedBg, '#5b2d7e')
})

test('resolveQuickFilterTone: surface falls back to neutral slab when no sidebarColor passed', () => {
    const tDark = resolveQuickFilterTone('#ffffff')
    const tLight = resolveQuickFilterTone('#0f172a')
    assert.match(tDark.surface, /^rgba\(/)
    assert.match(tLight.surface, /^rgba\(/)
})

test('resolveQuickFilterTone: case-insensitive white detection', () => {
    assert.equal(resolveQuickFilterTone('#FFFFFF').isDark, true)
    assert.equal(resolveQuickFilterTone('#FFF').isDark, true)
    assert.equal(resolveQuickFilterTone('white').isDark, true)
})

test('resolveQuickFilterTone: rgba near-white textColor selects dark flyout (matches dark sidebar rail)', () => {
    const t = resolveQuickFilterTone('rgba(255, 255, 255, 0.88)', '#1a1a1c', '#2d2d32')
    assert.equal(t.isDark, true)
    assert.match(t.surface, /^rgba\(26,\s*26,\s*28/)
})

test('resolveQuickFilterTone: rgba dark slate textColor stays light flyout variant', () => {
    const t = resolveQuickFilterTone('rgba(15, 23, 42, 0.92)', '#f8fafc')
    assert.equal(t.isDark, false)
})

test('resolveQuickFilterTone: undefined / null / empty default to light variant', () => {
    assert.equal(resolveQuickFilterTone(undefined).isDark, false)
    assert.equal(resolveQuickFilterTone(null).isDark, false)
    assert.equal(resolveQuickFilterTone('').isDark, false)
})

test('resolveQuickFilterTone: shadow has multiple stops (layered ambient, not single dropdown)', () => {
    const dark = resolveQuickFilterTone('#ffffff', '#5b2d7e').shadow
    const light = resolveQuickFilterTone('#0f172a').shadow
    // Each stop is comma-separated; "rgba(...)" itself contains commas, but
    // the helper uses 3 stops × 4 commas in rgba ≈ 11+ commas total.
    assert.ok(dark.split('),').length >= 3, 'expected ≥3 shadow stops in dark variant')
    assert.ok(light.split('),').length >= 3, 'expected ≥3 shadow stops in light variant')
})

test('resolveQuickFilterTone: 3-digit hex sidebarColor expands correctly', () => {
    const t = resolveQuickFilterTone('#ffffff', '#abc')
    // #abc = #aabbcc
    assert.match(t.surface, /^rgba\(170,\s*187,\s*204/)
})

test('resolveQuickFilterTone: rejects malformed sidebarColor and falls back', () => {
    const t = resolveQuickFilterTone('#ffffff', 'not-a-color')
    assert.match(t.surface, /^rgba\(/)
    // Falls back to neutral dark slab — no purple R channel.
    assert.doesNotMatch(t.surface, /^rgba\(91,/)
})

test('resolveQuickFilterTone: rowHoverBg and valueHoverBg are identical (Phase 5 hover unification)', () => {
    const dark = resolveQuickFilterTone('#ffffff', '#5b2d7e', '#3a1d52')
    const light = resolveQuickFilterTone('#0f172a', '#f5f5f5')
    assert.equal(dark.rowHoverBg, dark.valueHoverBg, 'dark variant: hover tones must match')
    assert.equal(light.rowHoverBg, light.valueHoverBg, 'light variant: hover tones must match')
})

test('resolveQuickFilterTone: brandAccentHex tints valueSelectedBg and indicators (light)', () => {
    const t = resolveQuickFilterTone('#0f172a', '#f5f5f5', '#e2e8f0', '#7c3aed')
    assert.match(t.valueSelectedBg, /^rgba\(124,\s*58,\s*237/)
    assert.match(t.indicatorActiveBg, /^rgba\(124,\s*58,\s*237/)
})

test('resolveQuickFilterTone: brandAccentHex tints valueSelectedBg (dark sidebar)', () => {
    const t = resolveQuickFilterTone('#ffffff', '#5b2d7e', '#3a1d52', '#a78bfa')
    assert.match(t.valueSelectedBg, /^rgba\(167,\s*139,\s*250/)
})

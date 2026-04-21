import test from 'node:test'
import assert from 'node:assert/strict'
import {
    buildFormatPresetGroups,
    formatSectionsForGenerateModal,
    recommendedPresetFormats,
} from './studioVersionFormatPresetGroups.mjs'

test('buildFormatPresetGroups orders by format_group_order', () => {
    const presets = {
        format_group_order: ['marketplace', 'social'],
        format_group_labels: { social: 'Social', marketplace: 'Marketplace' },
        preset_formats: [
            { id: 'a', group: 'social', label: 'A' },
            { id: 'b', group: 'marketplace', label: 'B' },
            { id: 'c', group: 'social', label: 'C' },
        ],
    }
    const g = buildFormatPresetGroups(presets)
    assert.equal(g.length, 2)
    assert.equal(g[0].group, 'marketplace')
    assert.equal(g[0].formats.length, 1)
    assert.equal(g[1].group, 'social')
    assert.equal(g[1].formats.length, 2)
})

test('ungrouped presets land in other', () => {
    const presets = {
        preset_formats: [{ id: 'x', label: 'X' }],
    }
    const g = buildFormatPresetGroups(presets)
    assert.ok(g.some((row) => row.group === 'other' && row.formats[0].id === 'x'))
})

test('recommendedPresetFormats filters recommended flag', () => {
    const formats = [
        { id: 'r', recommended: true },
        { id: 'n', recommended: false },
    ]
    assert.deepEqual(
        recommendedPresetFormats(formats).map((f) => f.id),
        ['r']
    )
})

test('recommendedPresetFormats empty uses [] when none flagged', () => {
    assert.deepEqual(recommendedPresetFormats([{ id: 'a' }]), [])
})

test('formatSectionsForGenerateModal removes recommended ids from groups', () => {
    const presets = {
        format_group_order: ['social', 'web'],
        preset_formats: [
            { id: 'rec', group: 'social', label: 'R', recommended: true },
            { id: 'other', group: 'social', label: 'O' },
            { id: 'w', group: 'web', label: 'W' },
        ],
    }
    const { recommended, groups } = formatSectionsForGenerateModal(presets)
    assert.deepEqual(
        recommended.map((f) => f.id),
        ['rec']
    )
    const social = groups.find((g) => g.group === 'social')
    assert.ok(social)
    assert.deepEqual(
        social.formats.map((f) => f.id),
        ['other']
    )
})

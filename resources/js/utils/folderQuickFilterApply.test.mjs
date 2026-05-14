import test from 'node:test'
import assert from 'node:assert/strict'
import {
    applyBoolean,
    applyMultiselectToggle,
    applySingleSelect,
    buildFilterKeysForQuickFilter,
    buildNextParamsForQuickFilter,
    selectedValuesFromUrl,
} from './folderQuickFilterApply.js'

// Phase 4.1 — exercise the contract used by the sidebar quick-filter flyout.
// These helpers are pure URL/string transforms; they intentionally do not
// import anything from React or @inertiajs so node --test can run them.

test('buildFilterKeysForQuickFilter: unions field_key onto schema keys', () => {
    const keys = buildFilterKeysForQuickFilter(['photo_type', 'subject_type'], 'environment_type')
    assert.deepEqual(keys, ['photo_type', 'subject_type', 'environment_type'])
})

test('buildFilterKeysForQuickFilter: deduplicates when key already in schema', () => {
    const keys = buildFilterKeysForQuickFilter(['photo_type', 'environment_type'], 'environment_type')
    assert.deepEqual(keys, ['photo_type', 'environment_type'])
})

test('buildFilterKeysForQuickFilter: handles null/undefined schema input', () => {
    assert.deepEqual(buildFilterKeysForQuickFilter(null, 'photo_type'), ['photo_type'])
    assert.deepEqual(buildFilterKeysForQuickFilter(undefined, 'photo_type'), ['photo_type'])
})

test('selectedValuesFromUrl: returns [] when key absent', () => {
    const sel = selectedValuesFromUrl('?other=1', 'photo_type', ['photo_type', 'other'])
    assert.deepEqual(sel, [])
})

test('selectedValuesFromUrl: single value returns 1-element array', () => {
    const sel = selectedValuesFromUrl(
        '?photo_type=studio',
        'photo_type',
        ['photo_type']
    )
    assert.deepEqual(sel, ['studio'])
})

test('selectedValuesFromUrl: bracketed multi-value returns string[]', () => {
    const sel = selectedValuesFromUrl(
        '?subject_type[0]=people&subject_type[1]=product',
        'subject_type',
        ['subject_type']
    )
    assert.deepEqual(sel, ['people', 'product'])
})

test('selectedValuesFromUrl: respects ?-stripping (works with or without leading ?)', () => {
    const a = selectedValuesFromUrl('?photo_type=studio', 'photo_type', ['photo_type'])
    const b = selectedValuesFromUrl('photo_type=studio', 'photo_type', ['photo_type'])
    assert.deepEqual(a, b)
})

// applyMultiselectToggle: exhaustive contract -----------------------------------

test('applyMultiselectToggle: adds a value to an empty draft', () => {
    const draft = {}
    applyMultiselectToggle(draft, 'subject_type', 'people')
    assert.deepEqual(draft, {
        subject_type: { operator: 'equals', value: ['people'] },
    })
})

test('applyMultiselectToggle: appends to existing array', () => {
    const draft = {
        subject_type: { operator: 'equals', value: ['people'] },
    }
    applyMultiselectToggle(draft, 'subject_type', 'product')
    assert.deepEqual(draft.subject_type.value, ['people', 'product'])
})

test('applyMultiselectToggle: removes when toggling existing value', () => {
    const draft = {
        subject_type: { operator: 'equals', value: ['people', 'product'] },
    }
    applyMultiselectToggle(draft, 'subject_type', 'people')
    assert.deepEqual(draft.subject_type.value, ['product'])
})

test('applyMultiselectToggle: deletes the entry when last value removed', () => {
    const draft = {
        subject_type: { operator: 'equals', value: ['people'] },
    }
    applyMultiselectToggle(draft, 'subject_type', 'people')
    assert.equal(draft.subject_type, undefined)
})

test('applyMultiselectToggle: promotes single-value scalar into array on toggle', () => {
    // Defensive: a previously-applied single-select value migrating to multi.
    const draft = {
        subject_type: { operator: 'equals', value: 'people' },
    }
    applyMultiselectToggle(draft, 'subject_type', 'product')
    assert.deepEqual(draft.subject_type.value, ['people', 'product'])
})

// applySingleSelect ---------------------------------------------------------

test('applySingleSelect: assigns when empty', () => {
    const draft = {}
    applySingleSelect(draft, 'photo_type', 'studio')
    assert.deepEqual(draft.photo_type, { operator: 'equals', value: 'studio' })
})

test('applySingleSelect: clears when same value reclicked', () => {
    const draft = { photo_type: { operator: 'equals', value: 'studio' } }
    applySingleSelect(draft, 'photo_type', 'studio')
    assert.equal(draft.photo_type, undefined)
})

test('applySingleSelect: replaces when different value', () => {
    const draft = { photo_type: { operator: 'equals', value: 'studio' } }
    applySingleSelect(draft, 'photo_type', 'outdoor')
    assert.equal(draft.photo_type.value, 'outdoor')
})

// applyBoolean --------------------------------------------------------------

test('applyBoolean: stores as "true"/"false" strings (URL-compatible)', () => {
    const draft = {}
    applyBoolean(draft, 'starred', true)
    assert.equal(draft.starred.value, 'true')
    applyBoolean(draft, 'starred', false)
    assert.equal(draft.starred.value, 'false')
})

test('applyBoolean: clears when same boolean reclicked', () => {
    const draft = { starred: { operator: 'equals', value: 'true' } }
    applyBoolean(draft, 'starred', true)
    assert.equal(draft.starred, undefined)
})

// Full URL round-trip via buildNextParamsForQuickFilter --------------------

test('buildNextParamsForQuickFilter: multi toggle round-trips into bracketed URL', () => {
    const params = buildNextParamsForQuickFilter(
        '?category=photography',
        'subject_type',
        (draft, key) => applyMultiselectToggle(draft, key, 'people'),
        ['subject_type']
    )
    assert.equal(params.category, 'photography')
    assert.equal(params['subject_type[0]'], 'people')
    assert.equal(params['subject_type[1]'], undefined)
    assert.equal(params['subject_type'], undefined)
})

test('buildNextParamsForQuickFilter: append multi value preserves existing values', () => {
    const params = buildNextParamsForQuickFilter(
        '?category=photography&subject_type[0]=people',
        'subject_type',
        (draft, key) => applyMultiselectToggle(draft, key, 'product'),
        ['subject_type']
    )
    assert.equal(params['subject_type[0]'], 'people')
    assert.equal(params['subject_type[1]'], 'product')
})

test('buildNextParamsForQuickFilter: removing last multi value drops the key entirely', () => {
    const params = buildNextParamsForQuickFilter(
        '?category=photography&subject_type[0]=people',
        'subject_type',
        (draft, key) => applyMultiselectToggle(draft, key, 'people'),
        ['subject_type']
    )
    assert.equal(params.category, 'photography')
    assert.equal(params['subject_type[0]'], undefined)
    assert.equal(params['subject_type'], undefined)
})

test('buildNextParamsForQuickFilter: single set produces flat key=value', () => {
    const params = buildNextParamsForQuickFilter(
        '?category=photography',
        'photo_type',
        (draft, key) => applySingleSelect(draft, key, 'studio'),
        ['photo_type']
    )
    assert.equal(params.photo_type, 'studio')
    assert.equal(params['photo_type[0]'], undefined)
})

test('buildNextParamsForQuickFilter: boolean writes "true"/"false" strings', () => {
    const params = buildNextParamsForQuickFilter(
        '?category=photography',
        'starred',
        (draft, key) => applyBoolean(draft, key, true),
        ['starred']
    )
    assert.equal(params.starred, 'true')
})

test('buildNextParamsForQuickFilter: schema strip preserves reserved/non-filter keys', () => {
    // category, sort, page are reserved — must survive untouched.
    const params = buildNextParamsForQuickFilter(
        '?category=photography&sort=name&page=2',
        'photo_type',
        (draft, key) => applySingleSelect(draft, key, 'studio'),
        ['photo_type']
    )
    assert.equal(params.category, 'photography')
    assert.equal(params.sort, 'name')
    assert.equal(params.page, '2')
    assert.equal(params.photo_type, 'studio')
})

test('buildNextParamsForQuickFilter: hidden-from-schema field still applies (Phase 4.1 fix)', () => {
    // Even when the per-category filterable_schema does NOT include a
    // quick filter's key, the helper must serialize it because we union
    // the key into filterKeys ourselves.
    const fieldKey = 'photo_type'
    const filterKeys = buildFilterKeysForQuickFilter([], fieldKey)
    const params = buildNextParamsForQuickFilter(
        '?category=photography',
        fieldKey,
        (draft, key) => applySingleSelect(draft, key, 'studio'),
        filterKeys
    )
    assert.equal(params.photo_type, 'studio')
})

test('buildNextParamsForQuickFilter: no duplicate query params after toggle round-trip', () => {
    // Apply multiselect, then again — should never end up with both
    // `subject_type=people` and `subject_type[0]=people` simultaneously.
    let params = buildNextParamsForQuickFilter(
        '?subject_type=people',
        'subject_type',
        (draft, key) => applyMultiselectToggle(draft, key, 'product'),
        ['subject_type']
    )
    // After toggle: should only have bracketed forms. Plain `subject_type`
    // must have been stripped.
    assert.equal(params['subject_type'], undefined)
    assert.equal(params['subject_type[0]'], 'people')
    assert.equal(params['subject_type[1]'], 'product')

    // Now toggle a third value off the result. We can't pipe params through
    // buildNextParamsForQuickFilter directly (it expects a search string), so
    // serialize and re-feed.
    const next = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) {
        if (Array.isArray(v)) v.forEach((vv) => next.append(k, vv))
        else next.append(k, v)
    }
    params = buildNextParamsForQuickFilter(
        `?${next.toString()}`,
        'subject_type',
        (draft, key) => applyMultiselectToggle(draft, key, 'people'),
        ['subject_type']
    )
    // Removing 'people' leaves just product, indexed at 0. No leftover [1].
    assert.equal(params['subject_type[0]'], 'product')
    assert.equal(params['subject_type[1]'], undefined)
    assert.equal(params['subject_type'], undefined)
})

/**
 * Classify filterable metadata fields into layout buckets for AssetGridSecondaryFilters.
 * Heuristics only — no URL or filter semantics changed.
 *
 * "Custom" here means tenant fields (`custom__` key prefix). System text/textarea/boolean filters
 * land in `other`, not `custom`, so the grid UI matches Manage → Fields terminology.
 */

import { resolve, CONTEXT, WIDGET } from './widgetResolver'

export function getFieldKey(field) {
    if (!field) return ''
    return field.field_key || field.key || ''
}

const VISUAL_FILTER_WIDGETS = new Set([WIDGET.COLOR_SWATCH, WIDGET.DOMINANT_COLORS])

/** Typical file / capture metadata keys → Asset Properties section */
const ASSET_PROPERTY_KEYS = new Set([
    'orientation',
    'aspect_ratio',
    'resolution',
    'codec',
    'duration',
    'file_format',
    'megapixels',
    'color_space',
    'lens',
    'camera_make',
    'camera_model',
    'frame_rate',
    'focal_length',
    'iso',
    'aperture',
    'shutter_speed',
])

/**
 * @param {object} field
 * @returns {boolean}
 */
function isAiOrSceneField(field) {
    const k = getFieldKey(field).toLowerCase()
    const label = String(field.display_label || field.label || '').toLowerCase()
    if (field.is_ai_trainable === true || field.ai_eligible === true) return true
    if (/^ai_/i.test(k) || /^scene_/i.test(k)) return true
    if (
        k.includes('environment_type') ||
        k.includes('subject_type') ||
        k.includes('caption') ||
        k.includes('detected_objects') ||
        k.includes('color_palette') ||
        k.includes('ai_detected')
    ) {
        return true
    }
    if (/\bai\b/.test(label) && label.length < 80) return true
    if (label.includes('scene classification')) return true
    return false
}

/**
 * @param {object} field
 * @returns {boolean}
 */
function isAssetPropertyField(field) {
    return ASSET_PROPERTY_KEYS.has(getFieldKey(field).toLowerCase())
}

/**
 * Structured / common dropdown-style fields → Basic section
 * @param {object} field
 * @returns {boolean}
 */
function isBasicStructuredField(field) {
    const t = field.type
    if (t === 'select' || t === 'multiselect' || t === 'rating' || t === 'number' || t === 'date') {
        return true
    }
    const k = getFieldKey(field)
    if (k === 'expiration_date') return true
    return false
}

/**
 * Merge primary + secondary visible fields (primary wins on duplicate keys).
 * @param {object[]} visiblePrimary
 * @param {object[]} visibleSecondary
 * @returns {object[]}
 */
export function mergeVisibleFields(visiblePrimary, visibleSecondary) {
    const map = new Map()
    for (const f of visiblePrimary || []) {
        const k = getFieldKey(f)
        if (k) map.set(k, f)
    }
    for (const f of visibleSecondary || []) {
        const k = getFieldKey(f)
        if (k && !map.has(k)) map.set(k, f)
    }
    return [...map.values()]
}

/**
 * @param {object[]} visiblePrimary
 * @param {object[]} visibleSecondary
 * @returns {{
 *   tagsField: object|null,
 *   starredField: object|null,
 *   visualFields: object[],
 *   basic: object[],
 *   assetProps: object[],
 *   aiScene: object[],
 *   custom: object[],
 *   other: object[],
 * }}
 */
export function partitionFilterLayoutFields(visiblePrimary, visibleSecondary) {
    const merged = mergeVisibleFields(visiblePrimary, visibleSecondary)

    let tagsField = null
    let starredField = null
    const visualFields = []
    const remainder = []

    for (const f of merged) {
        const w = resolve(f, CONTEXT.FILTER)
        const k = getFieldKey(f)

        if (w === WIDGET.TAG_MANAGER) {
            tagsField = f
            continue
        }
        if (k === 'starred') {
            starredField = f
            continue
        }
        if (VISUAL_FILTER_WIDGETS.has(w)) {
            visualFields.push(f)
            continue
        }
        if (w === WIDGET.EXCLUDED) continue

        remainder.push(f)
    }

    const basic = []
    const assetProps = []
    const aiScene = []
    /** Tenant-defined fields (keys must start with `custom__` per TenantMetadataFieldService). */
    const custom = []
    /** System + remaining field types (e.g. text, textarea, boolean) — not tenant "custom fields". */
    const other = []

    for (const f of remainder) {
        if (isAiOrSceneField(f)) {
            aiScene.push(f)
            continue
        }
        if (isAssetPropertyField(f)) {
            assetProps.push(f)
            continue
        }
        if (isBasicStructuredField(f)) {
            basic.push(f)
            continue
        }
        const k = getFieldKey(f)
        if (k.startsWith('custom__')) {
            custom.push(f)
        } else {
            other.push(f)
        }
    }

    return {
        tagsField,
        starredField,
        visualFields,
        basic,
        assetProps,
        aiScene,
        custom,
        other,
    }
}

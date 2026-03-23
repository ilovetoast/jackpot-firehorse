/**
 * Infer family / weight labels from font filenames (e.g. RBNo3.1-Book.otf, _Vendor - Family-Bold.ttf).
 * Binary parsing uses opentype.js when available (name table).
 */

const EXT = /\.(woff2?|ttf|otf|eot)$/i

/** @returns {boolean} */
function looksLikeWeightToken(s) {
    if (!s || s.length > 32) return false
    const lower = s.toLowerCase()
    if (/^\d+$/.test(lower)) return false
    const known =
        /^(thin|hairline|xlight|extralight|ultralight|light|book|regular|roman|normal|text|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique|it|bd|lt|md|rg)$/i
    return known.test(lower) || (lower.length <= 14 && /^[a-z0-9]+$/i.test(lower))
}

/** @param {string} raw */
function humanizeWeightToken(raw) {
    if (!raw) return null
    const t = raw.trim()
    if (!t) return null
    return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase()
}

/**
 * @returns {{ family: string, weightLabel: string | null, subfamily: string | null, italic: boolean }}
 */
export function parseFontFilename(filename) {
    const base = filename.replace(EXT, '').trim()
    let italic = /\bitalic\b|[-_]it$/i.test(base) || /\bOblique\b/i.test(base)

    // "... - RBNo3.1-Book" → take segment after last " - " when it looks like family-weight
    const dashParts = base.split(/\s-\s/)
    let core = dashParts.length >= 2 ? dashParts[dashParts.length - 1].trim() : base
    // Strip leading "_Vendor_" noise
    core = core.replace(/^_[^_]+_\s*/, '')

    let family = core
    let weightLabel = null

    const hyphenSplit = core.match(/^(.+)[-_]([^/\\]+)$/)
    if (hyphenSplit) {
        const right = hyphenSplit[2].trim()
        if (looksLikeWeightToken(right)) {
            family = hyphenSplit[1].trim()
            weightLabel = humanizeWeightToken(right.replace(/italic/i, '').trim()) || humanizeWeightToken(right)
            if (/italic/i.test(right)) italic = true
        }
    }

    if (family.length > 80) family = family.slice(0, 80)

    return {
        family,
        weightLabel,
        subfamily: weightLabel,
        italic,
    }
}

/**
 * @param {string | null | undefined} sub
 * @returns {string | null}
 */
function inferWeightLabelFromSubfamily(sub) {
    if (!sub) return null
    const s = sub.toLowerCase()
    if (/\bthin\b|hairline/.test(s)) return 'Thin'
    if (/extra\s*light|xlight|ultra\s*light/.test(s)) return 'Extra Light'
    if (/\blight\b/.test(s) && !/bold/.test(s)) return 'Light'
    if (/\bbook\b/.test(s)) return 'Book'
    if (/\bregular\b|\broman\b|\bnormal\b|\btext\b/.test(s)) return 'Regular'
    if (/\bmedium\b/.test(s)) return 'Medium'
    if (/semi\s*bold|demi/.test(s)) return 'Semi Bold'
    if (/\bbold\b/.test(s) && !/semi|extra/.test(s)) return 'Bold'
    if (/extra\s*bold|ultra\s*bold/.test(s)) return 'Extra Bold'
    if (/\bblack\b|\bheavy\b/.test(s)) return 'Black'
    if (/\bitalic\b|oblique/.test(s)) return 'Italic'
    return humanizeWeightToken(sub.split(/\s+/)[0] || '')
}

/**
 * @param {File} file
 * @returns {Promise<{ family: string, weightLabel: string | null, subfamily: string | null, italic: boolean }>}
 */
export async function extractFontMetadataFromFile(file) {
    const fromFilename = parseFontFilename(file.name)

    try {
        const opentype = await import('opentype.js')
        const buf = await file.arrayBuffer()
        const font = opentype.parse(buf)

        const pick = (rec) => {
            if (!rec) return null
            if (typeof rec === 'string') return rec
            return (
                rec.en
                || rec['en-US']
                || rec[1033]
                || rec[1]
                || Object.values(rec).find((v) => typeof v === 'string' && v.trim())
                || null
            )
        }

        const fam = pick(font.names?.fontFamily) || pick(font.names?.fullName)
        const sub = pick(font.names?.fontSubfamily) || pick(font.names?.typographicSubfamily)

        const italic = /\bitalic\b|oblique/i.test(sub || '') || fromFilename.italic
        const weightLabel = inferWeightLabelFromSubfamily(sub) || fromFilename.weightLabel

        return {
            family: (fam && fam.trim()) || fromFilename.family,
            subfamily: sub?.trim() || null,
            weightLabel,
            italic,
        }
    } catch {
        return {
            family: fromFilename.family,
            weightLabel: fromFilename.weightLabel,
            subfamily: fromFilename.subfamily,
            italic: fromFilename.italic,
        }
    }
}

/**
 * Merge parsed metadata into a font draft (first upload fills empty name; accumulates style tags).
 * @param {object} draft
 * @param {{ family: string, weightLabel: string | null, subfamily: string | null, italic: boolean }} meta
 */
export function mergeFontMetadataIntoDraft(draft, meta) {
    const next = { ...draft }
    const nameEmpty = !draft.name?.trim()
    if (nameEmpty && meta.family?.trim()) {
        next.name = meta.family.trim()
    }
    next.source = 'custom'

    const styles = new Set(Array.isArray(draft.styles) ? [...draft.styles] : [])
    const addTag = (t) => {
        const x = t?.trim()
        if (x && x.length <= 48 && styles.size < 24) styles.add(x)
    }

    if (meta.weightLabel) addTag(meta.weightLabel)
    if (meta.subfamily && meta.subfamily !== meta.weightLabel) {
        const short = meta.subfamily.split(/\s+/).slice(0, 3).join(' ')
        if (short.length <= 40) addTag(short)
    }
    if (meta.italic) addTag('Italic')

    next.styles = [...styles]
    return next
}

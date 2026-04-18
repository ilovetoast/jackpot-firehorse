/**
 * Per-pillar remediation CTAs for Brand Alignment.
 *
 * Maps backend `DimensionReasonCode` values -> short user-facing phrase
 * (<= 60 chars), an optional deep-link href, and a short CTA label.
 *
 * The backend enum lives in app/Enums/DimensionReasonCode.php. Codes are
 * append-only; add new entries here when new codes are introduced.
 *
 * Guardrails (must preserve):
 *  - phrases never imply visual detection that did not happen
 *  - phrases never say "weak" or "not aligned" when evidence is simply missing
 *  - phrases never say "aligned" for readiness-only signals
 */

/**
 * @typedef {object} Remediation
 * @property {string} phrase          Short, user-facing reason (<= 60 chars).
 * @property {string|null} href       Deep link to the place the user should fix.
 * @property {string|null} ctaLabel   Inline link label (e.g., "Add palette").
 */

const BRAND_DNA_TAB = (brandId, tab) =>
    brandId != null && brandId !== ''
        ? `/app/brands/${brandId}/edit?tab=${tab}`
        : null

const BRAND_DNA_ROOT = (brandId) =>
    brandId != null && brandId !== ''
        ? `/app/brands/${brandId}/dna`
        : null

const BRAND_REFERENCES = (brandId) =>
    brandId != null && brandId !== ''
        ? `/app/brands/${brandId}/edit?tab=references`
        : null

function shortBlank(phrase) {
    return { phrase, href: null, ctaLabel: null }
}

function link(phrase, href, ctaLabel) {
    return { phrase, href: href ?? null, ctaLabel: href ? ctaLabel : null }
}

/**
 * Resolve a per-pillar remediation for a given reason code.
 *
 * @param {string|null|undefined} reasonCode
 * @param {{brandId?: string|number|null, assetId?: string|number|null}} [ctx]
 * @returns {Remediation|null}
 */
export function remediationForReasonCode(reasonCode, ctx = {}) {
    const brandId = ctx.brandId ?? null
    if (!reasonCode || typeof reasonCode !== 'string') return null

    switch (reasonCode) {
        case 'identity.no_evidence':
            return link(
                'No logo match, no brand text — rerun OCR or add refs',
                BRAND_REFERENCES(brandId),
                'Manage references',
            )
        case 'identity.no_logo_references':
            return link(
                'Add approved logo references to enable detection',
                BRAND_REFERENCES(brandId),
                'Add logo refs',
            )
        case 'identity.metadata_only':
            return shortBlank('Only filename hinted at the brand — not visual')
        case 'identity.weak_logo_similarity':
            return shortBlank('Logo similar but below confidence threshold')
        case 'identity.evaluated':
            return null

        case 'color.missing_brand_palette':
            return link(
                'No brand palette configured',
                BRAND_DNA_TAB(brandId, 'brand_model'),
                'Define palette',
            )
        case 'color.missing_asset_palette':
            return shortBlank('Dominant colors not yet extracted from asset')
        case 'color.evaluation_failed':
            return shortBlank('Palette compare could not run; retry scoring')
        case 'color.evaluated':
            return null

        case 'typography.no_brand_no_asset':
            return link(
                'No brand fonts set and none readable on this asset',
                BRAND_DNA_TAB(brandId, 'brand_model'),
                'Add fonts',
            )
        case 'typography.no_asset_fonts':
            return shortBlank('Fonts cannot be read from this file type')
        case 'typography.pdf_fonts_unavailable':
            return shortBlank('No embedded fonts found in this PDF')
        case 'typography.missing_brand_config':
            return link(
                'Asset has fonts, but brand fonts are not configured',
                BRAND_DNA_TAB(brandId, 'brand_model'),
                'Add fonts',
            )
        case 'typography.evaluated':
            return null

        case 'style.embedding_missing':
            return shortBlank('No visual embedding yet; scoring is pending')
        case 'style.pdf_embedding_pending':
            return shortBlank('PDF page rendered; embedding still processing')
        case 'style.no_references':
            return link(
                'Add style reference images to score visual style',
                BRAND_REFERENCES(brandId),
                'Add style refs',
            )
        case 'style.embedding_empty':
            return shortBlank('Embedding vector is empty — regenerate asset')
        case 'style.reference_vectors_invalid':
            return shortBlank('Reference embeddings unavailable for compare')
        case 'style.evaluated':
            return null

        case 'copy.no_text':
            return shortBlank('No text read from this asset — try rerun OCR')
        case 'copy.text_too_short':
            return shortBlank('Too little text for a voice comparison')
        case 'copy.missing_brand_voice':
            return link(
                'Configure brand voice and tone to score copy',
                BRAND_DNA_TAB(brandId, 'brand_model'),
                'Add voice',
            )
        case 'copy.evaluated':
            return null

        case 'context.unclassified':
            return shortBlank('Asset purpose unclear — add campaign context')
        case 'context.pdf_approximate':
            return shortBlank('PDF preview gives a low-confidence read')
        case 'context.evaluated':
            return null
        // Stage 8a — peer-cohort fallback codes. The cohort lives outside the brand DNA (it's
        // other assets in the same collection/category), so these CTAs either point the user
        // at the library-ish actions they can take, or simply describe the blocker when no
        // clean deep link applies.
        case 'context.no_category':
            return shortBlank('Tag this asset with a category to enable context scoring')
        case 'context.cohort_too_small':
            return shortBlank('Add more assets to this category to enable context scoring')
        case 'context.cohort_no_signal':
            return link(
                'Atypical for this category — confirm category or add references',
                BRAND_REFERENCES(brandId),
                'Manage references',
            )
        case 'context.peer_cohort_evaluated':
            return null

        default:
            return null
    }
}

/**
 * Resolve a remediation for an entire dimension object (from breakdown_json.dimensions[key]).
 *
 * Prefers the structured `reason_code` field when present; falls back to a
 * generic per-status phrase so legacy rows without codes still render.
 *
 * @param {object|null|undefined} dim   One of breakdown.dimensions.* entries.
 * @param {{brandId?: string|number|null, assetId?: string|number|null}} [ctx]
 * @returns {Remediation|null}
 */
export function v2RemediationFor(dim, ctx = {}) {
    if (!dim || typeof dim !== 'object') return null

    const coded = remediationForReasonCode(dim.reason_code, ctx)
    if (coded) return coded

    // Fallbacks for backwards compatibility with older persisted rows
    const reason = typeof dim.status_reason === 'string' ? dim.status_reason : ''
    if (reason && reason.length > 0 && reason.length <= 60) {
        return shortBlank(reason)
    }

    if (dim.status === 'not_evaluable') return shortBlank('Missing data from this asset')
    if (dim.status === 'missing_reference') return shortBlank('Configure in brand guidelines')
    return null
}

<?php

namespace App\Enums;

/**
 * Machine-readable reason codes for DimensionResult outcomes.
 *
 * Paired with a short human phrase and a deep-link target on the frontend
 * (see jackpot/resources/js/Components/brandAlignmentRemediation.js).
 *
 * Naming: "<dimension>.<condition>" so the value parses cleanly on either side.
 * Codes are append-only. Do not remove or rename values; add new cases instead.
 *
 * These codes distinguish the three end-user meanings of "Missing data":
 *  - brand-DNA gap         -> configure something in brand guidelines
 *  - asset-input gap       -> rerun OCR / upload a better source / wait for embedding
 *  - pipeline-limitation   -> not extractable from this asset type today
 */
enum DimensionReasonCode: string
{
    case IDENTITY_NO_EVIDENCE = 'identity.no_evidence';
    case IDENTITY_NO_LOGO_REFERENCES = 'identity.no_logo_references';
    case IDENTITY_METADATA_ONLY = 'identity.metadata_only';
    case IDENTITY_WEAK_LOGO_SIMILARITY = 'identity.weak_logo_similarity';
    case IDENTITY_EVALUATED = 'identity.evaluated';

    case COLOR_MISSING_BRAND_PALETTE = 'color.missing_brand_palette';
    case COLOR_MISSING_ASSET_PALETTE = 'color.missing_asset_palette';
    case COLOR_EVALUATION_FAILED = 'color.evaluation_failed';
    case COLOR_EVALUATED = 'color.evaluated';

    case TYPOGRAPHY_NO_BRAND_NO_ASSET = 'typography.no_brand_no_asset';
    case TYPOGRAPHY_NO_ASSET_FONTS = 'typography.no_asset_fonts';
    case TYPOGRAPHY_PDF_FONTS_UNAVAILABLE = 'typography.pdf_fonts_unavailable';
    case TYPOGRAPHY_MISSING_BRAND_CONFIG = 'typography.missing_brand_config';
    case TYPOGRAPHY_EVALUATED = 'typography.evaluated';

    case STYLE_EMBEDDING_MISSING = 'style.embedding_missing';
    case STYLE_PDF_EMBEDDING_PENDING = 'style.pdf_embedding_pending';
    case STYLE_NO_REFERENCES = 'style.no_references';
    case STYLE_EMBEDDING_EMPTY = 'style.embedding_empty';
    case STYLE_REFERENCE_VECTORS_INVALID = 'style.reference_vectors_invalid';
    case STYLE_EVALUATED = 'style.evaluated';

    case COPY_NO_TEXT = 'copy.no_text';
    case COPY_TEXT_TOO_SHORT = 'copy.text_too_short';
    case COPY_MISSING_BRAND_VOICE = 'copy.missing_brand_voice';
    case COPY_EVALUATED = 'copy.evaluated';

    case CONTEXT_UNCLASSIFIED = 'context.unclassified';
    case CONTEXT_PDF_APPROXIMATE = 'context.pdf_approximate';
    case CONTEXT_EVALUATED = 'context.evaluated';
    // Peer-cohort fallback (Stage 8a): score context by similarity to peers in the same
    // collection/category when no campaign override and no strong VLM classification exists.
    case CONTEXT_NO_CATEGORY = 'context.no_category';
    case CONTEXT_COHORT_TOO_SMALL = 'context.cohort_too_small';
    case CONTEXT_COHORT_NO_SIGNAL = 'context.cohort_no_signal';
    case CONTEXT_PEER_COHORT_EVALUATED = 'context.peer_cohort_evaluated';
    // Campaign-context overlay (Pass A): when the asset belongs to a collection with a
    // scorable CollectionCampaignIdentity, blend the VLM/heuristic context with the
    // live campaign DNA (goal, description, tone, required motifs, exemplar refs).
    case CONTEXT_CAMPAIGN_ALIGNED = 'context.campaign_aligned';
    case CONTEXT_CAMPAIGN_MISALIGNED = 'context.campaign_misaligned';
    case CONTEXT_CAMPAIGN_NO_VLM = 'context.campaign_no_vlm';

    public function dimension(): AlignmentDimension
    {
        return match (true) {
            str_starts_with($this->value, 'identity.')   => AlignmentDimension::IDENTITY,
            str_starts_with($this->value, 'color.')      => AlignmentDimension::COLOR,
            str_starts_with($this->value, 'typography.') => AlignmentDimension::TYPOGRAPHY,
            str_starts_with($this->value, 'style.')      => AlignmentDimension::VISUAL_STYLE,
            str_starts_with($this->value, 'copy.')       => AlignmentDimension::COPY_VOICE,
            str_starts_with($this->value, 'context.')    => AlignmentDimension::CONTEXT_FIT,
        };
    }
}

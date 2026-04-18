/**
 * Brand Intelligence — compact signal summary with progressive disclosure.
 * Supports v1 (4-boolean gates) and v2 (6-dimension evidence model) layouts.
 */

import { useEffect, useMemo, useState } from 'react'
import { Link } from '@inertiajs/react'
import { v2RemediationFor } from './brandAlignmentRemediation'
import {
    ArrowPathIcon,
    CheckCircleIcon,
    ChevronDownIcon,
    ChevronUpIcon,
    ExclamationTriangleIcon,
    LanguageIcon,
    MinusCircleIcon,
    MinusSmallIcon,
    PhotoIcon,
    Squares2X2Icon,
    SwatchIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline'

const SIGNAL_KEYS = ['has_logo', 'has_brand_colors', 'has_typography', 'has_reference_similarity']

/** Short labels for inline row */
const SIGNAL_SHORT = {
    has_logo: 'Logo',
    has_brand_colors: 'Palette',
    has_typography: 'Type',
    has_reference_similarity: 'Style',
}

/** Phrases for “Missing: …” line */
const MISSING_PHRASE = {
    has_logo: 'Logo signal',
    has_brand_colors: 'Brand color match',
    has_typography: 'Typography',
    has_reference_similarity: 'Style references',
}

const SIGNAL_DEFS = [
    { key: 'has_logo', label: 'Logo signal', Icon: PhotoIcon },
    { key: 'has_brand_colors', label: 'Palette fit', Icon: SwatchIcon },
    { key: 'has_typography', label: 'Typography', Icon: LanguageIcon },
    { key: 'has_reference_similarity', label: 'Style compare', Icon: Squares2X2Icon },
]

const SIGNAL_POSITIVE_LABEL = {
    has_logo: 'Positive',
    has_brand_colors: 'On palette',
    has_typography: 'Ready to score',
    has_reference_similarity: 'References ready',
}

const SIGNAL_NEGATIVE_LABEL = {
    has_logo: 'No signal',
    has_brand_colors: 'Off palette',
    has_typography: 'Not configured',
    has_reference_similarity: 'Not ready',
}

function normalizeAlignmentState(raw) {
    const s = (raw || '').toString().trim().toUpperCase().replace(/-/g, '_')
    if (['ON_BRAND', 'PARTIAL_ALIGNMENT', 'OFF_BRAND', 'INSUFFICIENT_EVIDENCE'].includes(s)) {
        return s
    }
    return null
}

function alignmentStateFromLegacyLevel(level) {
    const l = (level || '').toString().toLowerCase()
    if (l === 'high') return 'ON_BRAND'
    if (l === 'medium') return 'PARTIAL_ALIGNMENT'
    if (l === 'low') return 'OFF_BRAND'
    if (l === 'unknown') return 'INSUFFICIENT_EVIDENCE'
    return null
}

function numericConfidenceToBand(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return null
    if (n > 0.8) return 'high'
    if (n >= 0.5) return 'moderate'
    return 'low'
}

const CONFIDENCE_SEGMENTS = 10

const CONFIDENCE_FILL = { low: 0.3, moderate: 0.6, high: 0.9 }

function shortenCreativeText(str, max = 40) {
    if (typeof str !== 'string') return '—'
    const t = str.trim()
    if (t === '') return '—'
    return t.length <= max ? t : `${t.slice(0, Math.max(0, max - 1))}…`
}

function copyAlignmentCompact(state) {
    if (!state || state === 'not_applicable') return '—'
    const map = {
        aligned: 'aligned',
        partial: 'partial',
        off_brand: 'off',
        insufficient: 'low',
        not_applicable: '—',
    }
    return map[state] || state.replace(/_/g, ' ')
}

function visualRowLabel(breakdown) {
    const va = breakdown?.visual_alignment
    if (va?.level) return String(va.level).replace(/_/g, ' ')
    const st = normalizeAlignmentState(breakdown?.alignment_state) || alignmentStateFromLegacyLevel(breakdown?.level)
    return st ? st.replace(/_/g, ' ') : '—'
}

function contextRowLabel(b) {
    const ctx = b?.context_analysis
    if (!ctx || typeof ctx !== 'object') return '—'
    const ai = ctx.context_type_ai
    const h = ctx.context_type_heuristic
    if (typeof ai === 'string' && ai.trim() !== '') return shortenCreativeText(ai, 28)
    if (typeof h === 'string' && h.trim() !== '') return shortenCreativeText(h, 28)
    if (typeof ctx.scene_type === 'string' && ctx.scene_type.trim() !== '') return shortenCreativeText(ctx.scene_type, 28)
    return '—'
}

function hasCreativeIntelligencePanel(b) {
    if (!b || typeof b !== 'object') return false
    return (
        b.ebi_ai_trace != null ||
        b.overall_summary != null ||
        b.creative_analysis != null ||
        b.dimension_weights != null ||
        b.copy_alignment != null ||
        b.context_analysis != null
    )
}

function normalizeConfidenceBand(bi, explicit) {
    if (explicit === 'low' || explicit === 'moderate' || explicit === 'high') {
        return explicit
    }
    return numericConfidenceToBand(bi?.confidence)
}

function normalizeReferenceTierUsage(raw) {
    if (!raw || typeof raw !== 'object') return null
    const t1 = raw.tier1 ?? raw.system
    const t2 = raw.tier2 ?? raw.promoted
    const t3 = raw.tier3 ?? raw.guideline
    if ([t1, t2, t3].every((v) => v === undefined || v === null)) {
        return null
    }
    return {
        system: Number(t1 ?? 0) || 0,
        promoted: Number(t2 ?? 0) || 0,
        guideline: Number(t3 ?? 0) || 0,
    }
}

function signalStatus(value) {
    if (value === true) return 'ok'
    if (value === false) return 'bad'
    return 'unknown'
}

/**
 * Plain-language: what each boolean checked (inputs to scoring, not four separate “found in the image” claims).
 * @param {string} signalKey
 * @param {object} [breakdown] — `breakdown_json` from Brand Intelligence; may include `logo_detection`
 */
function getSignalExplanation(signalKey, breakdown) {
    switch (signalKey) {
        case 'has_logo': {
            const ld = breakdown?.logo_detection
            if (ld && typeof ld === 'object') {
                if (ld.ocr_matched === true) {
                    return 'Brand name (or slug) appeared in the title, file name, or text we extracted from this asset — not “we drew a box around a logomark”.'
                }
                if (
                    ld.embedding_similarity != null &&
                    !Number.isNaN(Number(ld.embedding_similarity)) &&
                    ld.has_logo === true
                ) {
                    return 'Visual similarity to a logo reference image stored for this brand (embedding match), not metadata alone.'
                }
            }
            return 'Looks for your brand name in readable text and/or visual similarity to saved logo references — not whether a logo “exists” only in guidelines.'
        }
        case 'has_brand_colors':
            return 'Compares dominant colors in this artwork to the palette from your brand DNA / guidelines when analysis runs — “yes” means alignment passed, not only that a palette exists.'
        case 'has_typography':
            return 'Whether typography is set up in brand guidelines and/or this asset has type-related metadata the scorer can use — it does not mean we necessarily read fonts out of the image.'
        case 'has_reference_similarity':
            return 'This asset has an embedding and there are style-reference images (guideline, promoted, or system) we can compare to — readiness for style similarity, not a single “on-brand %” here.'
        default:
            return ''
    }
}

function isVideoAsset(asset) {
    const mime = asset && typeof asset.mime_type === 'string' ? asset.mime_type.toLowerCase() : ''
    return mime.startsWith('video/')
}

function countTruthySignals(signals) {
    if (!signals || typeof signals !== 'object') return null
    const n = SIGNAL_KEYS.filter((k) => signals[k] === true).length
    const hasAnyKey = SIGNAL_KEYS.some((k) => Object.prototype.hasOwnProperty.call(signals, k))
    return hasAnyKey ? n : null
}

function buildMissingSummary(signals) {
    if (!signals || typeof signals !== 'object') return null
    const missing = SIGNAL_KEYS.filter((k) => signals[k] === false).map((k) => MISSING_PHRASE[k])
    if (missing.length === 0) return null
    return `Missing: ${missing.join(', ')}`
}

function referenceSummaryLine(tier) {
    if (!tier) return null
    const parts = []
    if (tier.guideline > 0) parts.push(`${tier.guideline} guideline${tier.guideline === 1 ? '' : 's'}`)
    if (tier.promoted > 0) parts.push(`${tier.promoted} promoted`)
    if (tier.system > 0) parts.push(`${tier.system} system`)
    if (parts.length === 0) return 'References: none'
    return `References: ${parts.join(', ')}`
}

/** Overall tone for score dots: green | amber | slate */
function overallTone(alignment_state, signalScore) {
    if (alignment_state === 'ON_BRAND') return 'green'
    if (alignment_state === 'INSUFFICIENT_EVIDENCE') return 'slate'
    if (typeof signalScore === 'number' && signalScore >= 3) return 'amber'
    if (alignment_state === 'PARTIAL_ALIGNMENT') return 'amber'
    return 'amber'
}

function ScoreDots({ count, tone }) {
    const fill = {
        green: 'bg-emerald-500',
        amber: 'bg-amber-400',
        slate: 'bg-slate-400',
    }[tone] || 'bg-slate-400'
    const empty = 'bg-slate-200'
    return (
        <div className="flex items-center gap-0.5" aria-hidden>
            {[0, 1, 2, 3].map((i) => (
                <span
                    key={i}
                    className={`h-1.5 w-5 rounded-sm ${i < count ? fill : empty} transition-colors duration-300`}
                />
            ))}
        </div>
    )
}

/* ======================================================================
 *  V2 — 6-Dimension Evidence Model helpers
 * ====================================================================== */

const V2_DIMENSION_ORDER = ['identity', 'color', 'typography', 'visual_style', 'copy_voice', 'context_fit']

const V2_DIMENSION_LABELS = {
    identity: 'Identity',
    color: 'Color',
    typography: 'Type',
    visual_style: 'Style',
    copy_voice: 'Copy',
    context_fit: 'Context',
}

const V2_DIMENSION_ICONS = {
    identity: PhotoIcon,
    color: SwatchIcon,
    typography: LanguageIcon,
    visual_style: Squares2X2Icon,
    copy_voice: LanguageIcon,
    context_fit: Squares2X2Icon,
}

function v2StatusIcon(status, assetAnalysisStatus) {
    const processing = assetAnalysisStatus && !['complete', 'scoring'].includes(assetAnalysisStatus)
    switch (status) {
        case 'aligned':
            return <CheckCircleIcon className="h-3.5 w-3.5 text-emerald-600" aria-label="Aligned" />
        case 'partial':
            return <ExclamationTriangleIcon className="h-3.5 w-3.5 text-amber-500" aria-label="Partial" />
        case 'weak':
            return <MinusCircleIcon className="h-3.5 w-3.5 text-slate-400" aria-label="Weak evidence" />
        case 'not_evaluable':
        case 'missing_reference':
            if (processing) return <ArrowPathIcon className="h-3.5 w-3.5 animate-spin text-slate-400" aria-label="Pending" />
            return <MinusSmallIcon className="h-3.5 w-3.5 text-slate-400" aria-label="Not evaluated" />
        case 'fail':
            return <XCircleIcon className="h-3.5 w-3.5 text-red-500" aria-label="Not aligned" />
        default:
            return <MinusSmallIcon className="h-3.5 w-3.5 text-slate-400" />
    }
}

const V2_STATUS_LABELS = {
    aligned: 'Aligned',
    partial: 'Partial',
    weak: 'Weak evidence',
    not_evaluable: 'Not evaluated',
    missing_reference: 'No reference',
    fail: 'Not aligned',
}

function v2StatusLabel(status, dim, assetAnalysisStatus) {
    if (status === 'not_evaluable' || status === 'missing_reference') {
        const processing = assetAnalysisStatus && !['complete', 'scoring'].includes(assetAnalysisStatus)
        if (processing) return 'Pending'
    }
    return V2_STATUS_LABELS[status] || status
}

function v2StatusSubtext(status, dim, remediation) {
    if (status === 'not_evaluable' || status === 'missing_reference') {
        if (remediation?.phrase) return remediation.phrase
        const reason = dim?.status_reason
        if (typeof reason === 'string' && reason.length > 0 && reason.length <= 60) return reason
        if (status === 'not_evaluable') return 'Missing data from this asset'
        return 'Configure in brand guidelines'
    }
    return null
}

/**
 * Evidence hint for a dimension — uses status_reason when short enough,
 * otherwise falls back to a safe phrase based on primary_evidence_source.
 *
 * Wording guardrails (from plan):
 * - Never imply visual detection when evidence was text-based
 * - Never say "weak" or "not aligned" when the real issue is missing evidence
 * - Never say "aligned" for readiness-only signals
 * - Prefer hedged language ("appears", "suggests") when confidence is low
 */
function v2EvidenceHint(dim) {
    if (!dim) return null

    if (dim.status_reason && typeof dim.status_reason === 'string' && dim.status_reason.length <= 80) {
        return dim.status_reason
    }

    const lowConf = typeof dim.confidence === 'number' && dim.confidence < 0.4
    const src = dim.primary_evidence_source
    switch (src) {
        case 'visual_similarity':
            return lowConf ? 'Appears visually similar to logo reference' : 'Visually similar to logo reference'
        case 'extracted_text':
            return 'Brand text found via OCR'
        case 'palette_extraction':
            return dim.status === 'aligned'
                ? (lowConf ? 'Colors appear to be on palette' : 'Colors on brand palette')
                : 'Colors diverge from palette'
        case 'metadata_hint':
            return 'Filename suggests brand link'
        case 'configuration_only':
            return 'Config present, not evaluated'
        case 'ai_analysis':
            return lowConf ? 'Limited AI analysis' : 'Based on AI analysis'
        case 'not_evaluable':
            return 'Not enough evidence'
        default:
            return null
    }
}

function v2RatingTone(rating) {
    if (rating >= 4) return 'green'
    if (rating >= 3) return 'amber'
    if (rating >= 2) return 'amber'
    return 'slate'
}

function V2DimensionChips({ dimensions, assetAnalysisStatus }) {
    return (
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-600">
            {V2_DIMENSION_ORDER.map((key, idx) => {
                const dim = dimensions?.[key]
                const label = V2_DIMENSION_LABELS[key]
                return (
                    <span key={key} className="inline-flex items-center gap-0.5 whitespace-nowrap">
                        {idx > 0 ? <span className="text-slate-300">|</span> : null}
                        <span className="text-slate-500">{label}</span>
                        {v2StatusIcon(dim?.status, assetAnalysisStatus)}
                    </span>
                )
            })}
        </div>
    )
}

function V2DimensionGrid({ dimensions, assetAnalysisStatus, brandId = null, assetId = null }) {
    return (
        <div className="grid grid-cols-2 gap-x-2 gap-y-1.5">
            {V2_DIMENSION_ORDER.map((key) => {
                const dim = dimensions?.[key]
                if (!dim) return null
                const Icon = V2_DIMENSION_ICONS[key] || Squares2X2Icon
                const label = V2_DIMENSION_LABELS[key]
                const hint = v2EvidenceHint(dim)
                const remediation = v2RemediationFor(dim, { brandId, assetId })
                const statusText = v2StatusLabel(dim.status, dim, assetAnalysisStatus)
                const subtext = v2StatusSubtext(dim.status, dim, remediation)
                const processing = assetAnalysisStatus && !['complete', 'scoring'].includes(assetAnalysisStatus)
                const isPending = processing && (dim.status === 'not_evaluable' || dim.status === 'missing_reference')
                const statusColor =
                    dim.status === 'aligned'
                        ? 'text-emerald-700'
                        : dim.status === 'fail'
                          ? 'text-red-700'
                          : dim.status === 'partial'
                            ? 'text-amber-700'
                            : isPending
                              ? 'text-blue-500'
                              : 'text-slate-500'
                return (
                    <div
                        key={key}
                        className="flex items-start gap-1.5 rounded border border-slate-100/80 bg-slate-50/50 px-1.5 py-1.5"
                        title={dim.status_reason || undefined}
                    >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" aria-hidden />
                        <div className="min-w-0">
                            <div className="text-[10px] font-medium leading-tight text-slate-700">{label}</div>
                            <div className={`mt-0.5 text-[10px] leading-tight ${statusColor}`}>
                                {statusText}
                            </div>
                            {hint && !subtext && (
                                <p className="mt-0.5 line-clamp-2 text-[9px] leading-snug text-slate-500">{hint}</p>
                            )}
                            {subtext && (
                                <p className="mt-0.5 line-clamp-2 text-[9px] leading-snug text-slate-500">{subtext}</p>
                            )}
                            {remediation?.href && remediation?.ctaLabel && (
                                <Link
                                    href={remediation.href}
                                    className="mt-0.5 inline-flex text-[9px] font-medium text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-800"
                                >
                                    {remediation.ctaLabel}
                                </Link>
                            )}
                            {dim.evaluable && dim.confidence > 0 && (
                                <p className="mt-0.5 text-[9px] text-slate-400">
                                    conf {Math.round(dim.confidence * 100)}%
                                </p>
                            )}
                        </div>
                    </div>
                )
            })}
        </div>
    )
}

/* ======================================================================
 *  Campaign Alignment helpers
 * ====================================================================== */

const CAMPAIGN_CTA_MAP = {
    incomplete_identity: {
        label: 'Campaign identity is still incomplete — finish setup before campaign alignment can run.',
        action: 'settings',
    },
    partial_incomplete: { label: 'Complete campaign identity to improve scoring', action: 'settings' },
    partial_disabled: { label: 'Enable campaign scoring', action: 'settings' },
    ready_disabled: { label: 'Enable campaign scoring', action: 'settings' },
    unscored: {
        label: 'Not yet aligned with campaign identity for this asset — scoring will run after setup is ready (save Campaign identity or wait for the queue).',
        action: 'score',
    },
}

function campaignCtaState(campaignAlignment, collectionId) {
    if (!campaignAlignment || !collectionId) return null
    const ci = campaignAlignment.campaign_identity
    if (!ci) return null
    const { readiness_status, scoring_enabled } = ci
    if (readiness_status === 'incomplete') return 'incomplete_identity'
    if (readiness_status === 'partial' && !scoring_enabled) return 'partial_disabled'
    if (readiness_status === 'partial' && scoring_enabled && !campaignAlignment.campaign_score) return 'partial_incomplete'
    if (readiness_status === 'ready' && !scoring_enabled) return 'ready_disabled'
    if (scoring_enabled && !campaignAlignment.campaign_score) return 'unscored'
    return null
}

/**
 * Collection selected but API returned no campaign identity — alignment cannot be campaign-based.
 */
function CollectionWithoutCampaignNote({ collectionId }) {
    if (!collectionId) return null
    return (
        <div className="mt-1.5 rounded-md border border-slate-200 bg-slate-50/90 px-2 py-1.5 text-[10px] leading-snug text-slate-700">
            <p className="font-semibold text-slate-800">Master brand alignment only</p>
            <p className="mt-1">
                Jackpot scores alignment in two ways: <strong>master brand</strong> (published guidelines) and{' '}
                <strong>campaign</strong> (this collection&apos;s campaign identity). This collection does not have
                campaign identity set up, so there is <strong>no separate campaign alignment</strong> to show — only
                master brand scoring applies.
            </p>
            <p className="mt-1 text-[9px] text-slate-500">
                Add a campaign on{' '}
                <Link
                    href={`/app/collections/${collectionId}/campaign`}
                    className="font-medium text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-800"
                >
                    Campaign identity
                </Link>{' '}
                if you want palette, type, and goals from the campaign reflected in alignment.
            </p>
        </div>
    )
}

/**
 * Always-visible note when the drawer has collection context + campaign identity but the compact header is still “master first”.
 */
function CampaignCollectionCallout({
    collectionId,
    campaignAlignment,
    showCampaignPrimary,
    campaignAlignmentFetchSettled,
}) {
    if (showCampaignPrimary || !collectionId) {
        return null
    }
    if (!campaignAlignmentFetchSettled) {
        return null
    }
    // null after a settled fetch means the request failed — do not imply "no campaign identity".
    if (campaignAlignment === null) {
        return null
    }
    if (!campaignAlignment?.campaign_identity) {
        return <CollectionWithoutCampaignNote collectionId={collectionId} />
    }
    const ci = campaignAlignment.campaign_identity
    const name = ci.campaign_name
    const interpretation = campaignAlignment.interpretation
    const scored = campaignAlignment.campaign_score != null
    const summary =
        typeof interpretation?.interpretation_text === 'string' && interpretation.interpretation_text.trim() !== ''
            ? interpretation.interpretation_text
            : null

    return (
        <div className="mt-1.5 rounded-md border border-violet-200/90 bg-violet-50/80 px-2 py-1.5 text-[10px] leading-snug text-violet-950">
            <p className="text-[9px] font-semibold uppercase tracking-wide text-violet-800/90">Two alignment modes</p>
            <p className="mt-1 text-violet-900/95">
                <strong>Master brand</strong> = published guidelines. <strong>Campaign</strong> = this collection&apos;s
                campaign identity. The compact row above is still <strong>master brand</strong> until campaign scoring
                completes and surfaces campaign-first.
            </p>
            <div className="mt-1.5 font-semibold text-violet-900">
                Campaign identity{typeof name === 'string' && name.trim() !== '' ? ` · ${name}` : ''}
            </div>
            {summary && <p className="mt-0.5 text-violet-900/90">{summary}</p>}
            {!scored && (
                <p className="mt-1 text-violet-900/90">
                    The Logo / Palette / Type / Style row reflects your <strong>master brand</strong> only. To review
                    alignment with <strong>this campaign</strong>, campaign scoring must run for this asset in this
                    collection — open{' '}
                    <Link
                        href={`/app/collections/${collectionId}/campaign`}
                        className="font-semibold text-violet-800 underline decoration-violet-400 underline-offset-2 hover:text-violet-950"
                    >
                        Campaign identity
                    </Link>
                    , then save (or wait for the scoring queue).
                </p>
            )}
            {scored && (
                <p className="mt-0.5 text-violet-900/85">
                    Expand for campaign dimensions and combined interpretation. The row above still summarizes master brand
                    signals.
                </p>
            )}
            <p className="mt-1 border-t border-violet-200/60 pt-1 text-[9px] text-violet-800/80">
                <strong>Re-score</strong> below updates <strong>master brand</strong> alignment only; it does not by
                itself run campaign alignment.
            </p>
        </div>
    )
}

function CampaignCta({ campaignAlignment, collectionId }) {
    const state = campaignCtaState(campaignAlignment, collectionId)
    if (!state) return null
    const cta = CAMPAIGN_CTA_MAP[state]
    if (!cta) return null
    if (cta.action === 'settings' && collectionId) {
        return (
            <div className="mt-1.5 rounded border border-amber-200/80 bg-amber-50/70 px-2 py-1.5 text-[10px] text-amber-800">
                {cta.label}{' '}
                <Link href={`/app/collections/${collectionId}/campaign`} className="font-medium underline underline-offset-2">
                    Open Campaign identity
                </Link>
            </div>
        )
    }
    if (cta.action === 'score' && collectionId) {
        return (
            <div className="mt-1.5 rounded border border-amber-200/80 bg-amber-50/70 px-2 py-1.5 text-[10px] text-amber-800">
                {cta.label}{' '}
                <Link href={`/app/collections/${collectionId}/campaign`} className="font-medium underline underline-offset-2">
                    Open Campaign identity
                </Link>
            </div>
        )
    }
    return (
        <div className="mt-1.5 rounded border border-amber-200/80 bg-amber-50/70 px-2 py-1.5 text-[10px] text-amber-800">
            {cta.label}
        </div>
    )
}

function CampaignMasterSummary({ interpretation }) {
    if (!interpretation) return null
    const { master_rating, master_state, master_confidence } = interpretation
    if (master_rating == null) return null
    const stateLabel = master_state ? String(master_state).replace(/_/g, ' ') : '—'
    return (
        <div className="mt-1.5 flex items-center gap-2 rounded border border-slate-100 bg-slate-50/60 px-2 py-1.5 text-[10px]">
            <span className="font-medium text-slate-600">Master Brand</span>
            <ScoreDots count={Math.min(4, Math.max(0, master_rating))} tone={v2RatingTone(master_rating)} />
            <span className="capitalize text-slate-500">{stateLabel}</span>
            {master_confidence != null && (
                <span className="text-slate-400">conf {Math.round(master_confidence * 100)}%</span>
            )}
        </div>
    )
}

/**
 * @param {object} props
 * @param {object} [props.brandIntelligence]
 * @param {object} [props.data]
 * @param {string|number} [props.brandId] — for “Fix issues” navigation
 * @param {object} [props.asset] — optional; used for MIME (video notice) and signal source copy
 */
export default function BrandSignalBreakdown({
    brandIntelligence = null,
    data = null,
    brandId = null,
    asset = null,
    campaignAlignment = null,
    campaignAlignmentFetchSettled = true,
    collectionId = null,
    isRefreshing = false,
}) {
    const [expanded, setExpanded] = useState(false)
    const [refDetailOpen, setRefDetailOpen] = useState(false)
    const [confSeg, setConfSeg] = useState(0)
    const assetAnalysisStatus = asset?.analysis_status ?? ''

    const normalized = useMemo(() => {
        const flat = data && typeof data === 'object' ? data : null
        const bi = flat ?? brandIntelligence
        const breakdown = bi?.breakdown_json ?? {}

        const hasV2 = breakdown?.dimensions != null && typeof breakdown.dimensions === 'object'

        const signals =
            flat?.signals ??
            bi?.signal_breakdown ??
            breakdown?.consumer_signal_breakdown ??
            breakdown?.signal_breakdown ??
            breakdown?.signals ??
            {}

        let alignment_state =
            normalizeAlignmentState(flat?.alignment_state) ??
            normalizeAlignmentState(bi?.alignment_state) ??
            normalizeAlignmentState(breakdown?.alignment_state)
        if (!alignment_state) {
            alignment_state = alignmentStateFromLegacyLevel(bi?.level ?? breakdown?.level)
        }

        const confidenceBand = normalizeConfidenceBand(bi, flat?.confidence)

        const reference_tier_usage = normalizeReferenceTierUsage(
            flat?.reference_tier_usage ?? bi?.reference_tier_usage ?? breakdown?.reference_tier_usage,
        )

        const signal_score =
            flat?.signal_score ??
            bi?.signal_count ??
            breakdown?.signal_count ??
            countTruthySignals(signals)

        const v2Rating = hasV2 ? (breakdown.rating ?? null) : null
        const v2Dimensions = hasV2 ? breakdown.dimensions : null
        const v2Recommendations = hasV2 ? (breakdown.v2_recommendations ?? bi?.v2_recommendations ?? null) : null

        return {
            alignment_state,
            confidenceBand,
            signals,
            reference_tier_usage,
            signal_score,
            breakdown,
            confidence: typeof bi?.confidence === 'number' ? bi.confidence : null,
            hasV2,
            v2Rating,
            v2Dimensions,
            v2Recommendations,
        }
    }, [brandIntelligence, data])

    const { alignment_state, confidenceBand, signals, reference_tier_usage, signal_score, breakdown, confidence, hasV2, v2Rating, v2Dimensions, v2Recommendations } =
        normalized

    const hasCampaignScore = campaignAlignment?.campaign_score != null
    const campaignScore = campaignAlignment?.campaign_score
    const campaignDimensions = campaignScore?.dimensions ?? null
    const campaignRating = campaignScore?.v2_rating ?? campaignScore?.overall_score ?? null
    const campaignConfidence = campaignScore?.v2_overall_confidence ?? campaignScore?.confidence ?? null
    const interpretation = campaignAlignment?.interpretation
    const campaignName = campaignAlignment?.campaign_identity?.campaign_name
    const showCampaignPrimary = hasCampaignScore && interpretation?.primary_display === 'campaign'

    const creativePanel = useMemo(() => {
        if (!hasCreativeIntelligencePanel(breakdown)) return null
        const trace = breakdown.ebi_ai_trace && typeof breakdown.ebi_ai_trace === 'object' ? breakdown.ebi_ai_trace : {}
        return {
            overall: typeof breakdown.overall_summary === 'string' ? breakdown.overall_summary : null,
            visual: breakdown.visual_alignment,
            visualAi: breakdown.visual_alignment_ai,
            copy: breakdown.copy_alignment,
            context: breakdown.context_analysis,
            creative: breakdown.creative_analysis,
            weights: breakdown.dimension_weights,
            trace,
        }
    }, [breakdown])

    const missingSummary = useMemo(() => buildMissingSummary(signals), [signals])

    const tone = hasV2
        ? v2RatingTone(v2Rating ?? 0)
        : overallTone(alignment_state, typeof signal_score === 'number' ? signal_score : 0)

    const filledDots = hasV2
        ? Math.min(4, Math.max(0, v2Rating ?? 0))
        : (typeof signal_score === 'number' && !Number.isNaN(signal_score) ? Math.min(4, Math.max(0, signal_score)) : 0)

    const confFrac = confidenceBand ? CONFIDENCE_FILL[confidenceBand] ?? 0 : typeof confidence === 'number' ? confidence : 0
    const filledConfSegments = Math.round(confFrac * CONFIDENCE_SEGMENTS)

    useEffect(() => {
        setConfSeg(0)
        const id = requestAnimationFrame(() => setConfSeg(filledConfSegments))
        return () => cancelAnimationFrame(id)
    }, [filledConfSegments, expanded])

    const fixHref =
        brandId != null && brandId !== ''
            ? `/app/brands/${brandId}/dna`
            : null

    const recommendationShort = useMemo(() => {
        if (!alignment_state || alignment_state === 'INSUFFICIENT_EVIDENCE') {
            const persisted = breakdown?.recommendations
            if (Array.isArray(persisted) && typeof persisted[0] === 'string' && persisted[0].trim() !== '') {
                return persisted[0]
            }
            return null
        }
        if (alignment_state === 'PARTIAL_ALIGNMENT' && breakdown?.style_deviation_reason) {
            return breakdown.style_deviation_reason
        }
        return null
    }, [alignment_state, breakdown?.recommendations, breakdown?.style_deviation_reason])

    return (
        <div className="rounded-lg border border-slate-200/90 bg-white/95 shadow-sm ring-1 ring-slate-100/80">
            <div
                className={`overflow-hidden px-2.5 pt-2 pb-1.5 ${creativePanel ? 'max-h-[128px]' : 'max-h-[100px]'}`}
            >
                <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <h3 className="text-xs font-semibold text-slate-900">
                                {showCampaignPrimary ? 'Campaign Alignment' : 'Brand Alignment'}
                            </h3>
                            <ScoreDots
                                count={showCampaignPrimary ? Math.min(4, Math.max(0, campaignRating ?? 0)) : filledDots}
                                tone={showCampaignPrimary ? v2RatingTone(campaignRating ?? 0) : tone}
                            />
                            {isRefreshing && (
                                <span className="inline-flex items-center gap-1 text-[10px] text-slate-400">
                                    <ArrowPathIcon className="h-3 w-3 animate-spin" aria-hidden />
                                    Updating…
                                </span>
                            )}
                        </div>
                        <CampaignCollectionCallout
                            collectionId={collectionId}
                            campaignAlignment={campaignAlignment}
                            campaignAlignmentFetchSettled={campaignAlignmentFetchSettled}
                            showCampaignPrimary={showCampaignPrimary}
                        />
                        {showCampaignPrimary && campaignName && (
                            <div className="mt-0.5 text-[10px] text-slate-500">
                                <span className="inline-flex items-center gap-1 rounded bg-violet-50 px-1.5 py-0.5 text-violet-700 font-medium">
                                    {campaignName}
                                </span>
                                {interpretation?.master_rating != null && (
                                    <span className="ml-2 text-slate-400">
                                        Brand: {String(interpretation.master_state ?? '').replace(/_/g, ' ')}
                                    </span>
                                )}
                            </div>
                        )}
                        {showCampaignPrimary && campaignDimensions ? (
                            <V2DimensionChips dimensions={campaignDimensions} assetAnalysisStatus={assetAnalysisStatus} />
                        ) : hasV2 ? (
                            <V2DimensionChips dimensions={v2Dimensions} assetAnalysisStatus={assetAnalysisStatus} />
                        ) : (
                            <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-600">
                                {SIGNAL_KEYS.map((key, idx) => {
                                    const st = signalStatus(signals[key])
                                    const short = SIGNAL_SHORT[key]
                                    const sym =
                                        st === 'ok' ? (
                                            <span className="text-emerald-600" aria-label={`${short} ok`}>
                                                ✓
                                            </span>
                                        ) : st === 'bad' ? (
                                            <span className="text-slate-500" aria-label={`${short} missing`}>
                                                ✗
                                            </span>
                                        ) : (
                                            <span className="text-slate-400" aria-label={`${short} unknown`}>
                                                —
                                            </span>
                                        )
                                    return (
                                        <span key={key} className="inline-flex items-center gap-0.5 whitespace-nowrap">
                                            {idx > 0 ? <span className="text-slate-300">|</span> : null}
                                            <span className="text-slate-500">{short}</span>
                                            {sym}
                                        </span>
                                    )
                                })}
                            </div>
                        )}
                        {hasV2 && v2Recommendations && v2Recommendations.length > 0 ? (
                            <p className="mt-0.5 line-clamp-1 text-[10px] leading-snug text-slate-600">{v2Recommendations[0]}</p>
                        ) : missingSummary ? (
                            <p className="mt-0.5 line-clamp-1 text-[10px] leading-snug text-slate-600">{missingSummary}</p>
                        ) : (
                            <p className="mt-0.5 line-clamp-1 text-[10px] leading-snug text-slate-500">
                                Core signals look complete.
                            </p>
                        )}
                        {creativePanel && (
                            <p
                                className="mt-0.5 line-clamp-1 text-[9px] leading-snug text-slate-600"
                                title={
                                    creativePanel.overall ||
                                    [creativePanel.trace?.skip_reason].filter(Boolean).join(' ') ||
                                    undefined
                                }
                            >
                                <span className="font-medium text-slate-700">Creative</span>
                                <span className="text-slate-300"> · </span>
                                <span className="text-slate-500">Overall</span>{' '}
                                {shortenCreativeText(creativePanel.overall, 32)}
                                <span className="text-slate-300"> · </span>
                                <span className="text-slate-500">Visual</span> {visualRowLabel(breakdown)}
                                <span className="text-slate-300"> · </span>
                                <span className="text-slate-500">Copy</span>{' '}
                                {creativePanel.copy?.score != null && !Number.isNaN(Number(creativePanel.copy.score))
                                    ? `${Math.round(Number(creativePanel.copy.score))}%`
                                    : copyAlignmentCompact(creativePanel.copy?.alignment_state)}{' '}
                                <span className="text-slate-300">·</span>{' '}
                                <span className="text-slate-500">Context</span> {contextRowLabel(breakdown)}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => setExpanded((e) => !e)}
                        className="flex shrink-0 items-center gap-0.5 rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                        aria-expanded={expanded}
                        aria-label={expanded ? 'Collapse details' : 'Expand details'}
                    >
                        {expanded ? <ChevronUpIcon className="h-4 w-4" /> : <ChevronDownIcon className="h-4 w-4" />}
                    </button>
                </div>
            </div>

            <div
                className={`grid transition-[grid-template-rows] duration-300 ease-out ${
                    expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
                }`}
            >
                <div className="overflow-hidden min-h-0">
                    <div className="border-t border-slate-100 px-2.5 pb-2.5 pt-1.5 transition-opacity duration-300 ease-out">
                        {showCampaignPrimary ? (
                            <>
                                <div className="mb-2 rounded-md border border-violet-100 bg-violet-50/60 px-2 py-1.5 text-[10px] leading-snug text-slate-600">
                                    <p className="font-semibold text-violet-900">Campaign-based alignment (this collection)</p>
                                    <p className="mt-1">
                                        You are viewing alignment scored against{' '}
                                        <span className="font-medium text-violet-950">{campaignName ?? 'this campaign'}</span>
                                        &apos;s <strong>campaign identity</strong> (palette, typography, goals — not only
                                        master guidelines).
                                    </p>
                                    <p className="mt-1.5 border-t border-violet-200/70 pt-1.5 text-[9px] text-slate-600">
                                        <span className="font-medium text-slate-700">Two modes in Jackpot:</span>{' '}
                                        <strong>Master brand</strong> uses published brand guidelines everywhere.{' '}
                                        <strong>Campaign</strong> adds this collection&apos;s campaign rules when you open
                                        assets from that collection and campaign scoring has run. The dimension grid below
                                        reflects <strong>campaign</strong> evaluation; master brand is summarized under it.
                                    </p>
                                </div>
                                {isVideoAsset(asset) && (
                                    <div className="text-xs text-yellow-600 mb-2">
                                        Visual analysis limited for video assets.
                                    </div>
                                )}
                                <V2DimensionGrid
                                    dimensions={campaignDimensions}
                                    assetAnalysisStatus={assetAnalysisStatus}
                                    brandId={brandId}
                                    assetId={asset?.id ?? null}
                                />
                                <CampaignMasterSummary interpretation={interpretation} />
                                {interpretation?.interpretation_text && (
                                    <div className="mt-1.5 rounded border border-slate-100 bg-slate-50/60 px-2 py-1.5 text-[10px] leading-snug text-slate-600">
                                        {interpretation.interpretation_text}
                                        {interpretation.interpretation_caveat && (
                                            <p className="mt-0.5 text-[9px] text-slate-400">{interpretation.interpretation_caveat}</p>
                                        )}
                                    </div>
                                )}
                                <CampaignCta campaignAlignment={campaignAlignment} collectionId={collectionId} />
                            </>
                        ) : hasV2 ? (
                            <>
                                <div className="mb-2 rounded-md border border-slate-100 bg-slate-50/80 px-2 py-1.5 text-[10px] leading-snug text-slate-600">
                                    <span className="font-medium text-slate-700">6 alignment dimensions</span> — each
                                    shows what was evaluated, the evidence found, and confidence level. Labels reflect
                                    actual evidence, not just configuration presence.
                                </div>
                                {isVideoAsset(asset) && (
                                    <div className="text-xs text-yellow-600 mb-2">
                                        Visual analysis limited for video assets.
                                    </div>
                                )}
                                <V2DimensionGrid
                                    dimensions={v2Dimensions}
                                    assetAnalysisStatus={assetAnalysisStatus}
                                    brandId={brandId}
                                    assetId={asset?.id ?? null}
                                />
                                {campaignAlignment && !hasCampaignScore && (
                                    <CampaignCta campaignAlignment={campaignAlignment} collectionId={collectionId} />
                                )}
                            </>
                        ) : (
                            <>
                                <div className="mb-2 rounded-md border border-slate-100 bg-slate-50/80 px-2 py-1.5 text-[10px] leading-snug text-slate-600">
                                    <span className="font-medium text-slate-700">How to read these four tiles:</span> each one is a{' '}
                                    <span className="font-medium text-slate-700">check the scorer could run</span> using your
                                    guidelines, references, and this file — not four separate guarantees that something was
                                    literally detected inside the execution image.
                                </div>
                                {isVideoAsset(asset) && (
                                    <div className="text-xs text-yellow-600 mb-2">
                                        Visual analysis not applied to video assets.
                                    </div>
                                )}
                                <div className="grid grid-cols-2 gap-x-2 gap-y-1.5">
                                    {SIGNAL_DEFS.map(({ key, label, Icon }) => {
                                        const st = signalStatus(signals[key])
                                        const ok = SIGNAL_POSITIVE_LABEL[key]
                                        const bad = SIGNAL_NEGATIVE_LABEL[key]
                                        const explain = getSignalExplanation(key, breakdown)
                                        return (
                                            <div
                                                key={key}
                                                className="flex items-start gap-1.5 rounded border border-slate-100/80 bg-slate-50/50 px-1.5 py-1.5"
                                                title={explain}
                                            >
                                                <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-500" aria-hidden />
                                                <div className="min-w-0">
                                                    <div className="text-[10px] font-medium leading-tight text-slate-700">{label}</div>
                                                    <div className="mt-0.5 text-[10px] leading-tight">
                                                        {st === 'ok' && <span className="text-emerald-700">{ok}</span>}
                                                        {st === 'bad' && <span className="text-amber-800/90">{bad}</span>}
                                                        {st === 'unknown' && (
                                                            <span className="inline-flex items-center gap-0.5 text-slate-500">
                                                                <MinusSmallIcon className="h-3 w-3" aria-hidden />
                                                                Unknown
                                                            </span>
                                                        )}
                                                    </div>
                                                    {explain ? (
                                                        <p className="mt-1 line-clamp-5 text-[9px] leading-snug text-slate-500">{explain}</p>
                                                    ) : null}
                                                </div>
                                            </div>
                                        )
                                    })}
                                </div>
                                {campaignAlignment && (
                                    <CampaignCta campaignAlignment={campaignAlignment} collectionId={collectionId} />
                                )}
                            </>
                        )}

                        {creativePanel && (
                            <div className="mt-2 rounded-md border border-violet-100/90 bg-violet-50/35 px-2 py-1.5">
                                <div className="text-[10px] font-semibold uppercase tracking-wide text-violet-900/90">
                                    Creative intelligence
                                </div>
                                <dl className="mt-1.5 space-y-1.5 text-[10px] text-slate-700">
                                    <div>
                                        <dt className="font-medium text-slate-600">Overall</dt>
                                        <dd className="mt-0.5 leading-snug text-slate-800">
                                            {creativePanel.overall ? (
                                                creativePanel.overall
                                            ) : (
                                                <span className="text-slate-500">No summary yet.</span>
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-medium text-slate-600">Visual</dt>
                                        <dd className="mt-0.5 leading-snug">
                                            {creativePanel.visual?.label && (
                                                <span className="text-slate-500">{creativePanel.visual.label}: </span>
                                            )}
                                            <span className="text-slate-800">{visualRowLabel(breakdown)}</span>
                                            {creativePanel.visualAi?.summary && (
                                                <p className="mt-0.5 text-slate-600">{creativePanel.visualAi.summary}</p>
                                            )}
                                            {creativePanel.visualAi?.fit_score != null && (
                                                <span className="text-slate-500">
                                                    {' '}
                                                    (AI fit ~{Math.round(Number(creativePanel.visualAi.fit_score))}%)
                                                </span>
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-medium text-slate-600">Copy</dt>
                                        <dd className="mt-0.5 leading-snug text-slate-800">
                                            {creativePanel.copy?.score != null && !Number.isNaN(Number(creativePanel.copy.score))
                                                ? `${Math.round(Number(creativePanel.copy.score))}% · `
                                                : null}
                                            {copyAlignmentCompact(creativePanel.copy?.alignment_state)}
                                            {creativePanel.copy?.confidence != null && (
                                                <span className="text-slate-500">
                                                    {' '}
                                                    · conf {Math.round(Number(creativePanel.copy.confidence) * 100)}%
                                                </span>
                                            )}
                                        </dd>
                                        {Array.isArray(creativePanel.copy?.reasons) && creativePanel.copy.reasons.length > 0 && (
                                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-[9px] text-slate-600">
                                                {creativePanel.copy.reasons.slice(0, 6).map((r, i) => (
                                                    <li key={i}>{r}</li>
                                                ))}
                                            </ul>
                                        )}
                                        {creativePanel.creative && (
                                            <div className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-[9px] text-slate-600">
                                                {creativePanel.creative.headline_text && (
                                                    <span>
                                                        <span className="text-slate-500">Headline:</span> {creativePanel.creative.headline_text}
                                                    </span>
                                                )}
                                                {creativePanel.creative.supporting_text && (
                                                    <span>
                                                        <span className="text-slate-500">Support:</span> {creativePanel.creative.supporting_text}
                                                    </span>
                                                )}
                                                {creativePanel.creative.cta_text && (
                                                    <span>
                                                        <span className="text-slate-500">CTA:</span> {creativePanel.creative.cta_text}
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <dt className="font-medium text-slate-600">Context</dt>
                                        <dd className="mt-0.5 leading-snug text-slate-800">
                                            {creativePanel.context?.context_type_ai ?? creativePanel.context?.context_type_heuristic ?? '—'}
                                            {creativePanel.context?.scene_type && (
                                                <span className="text-slate-600"> · {creativePanel.context.scene_type}</span>
                                            )}
                                            {creativePanel.context?.lighting_type && (
                                                <span className="text-slate-600"> · {creativePanel.context.lighting_type}</span>
                                            )}
                                            {creativePanel.context?.mood && (
                                                <span className="text-slate-600"> · {creativePanel.context.mood}</span>
                                            )}
                                        </dd>
                                    </div>
                                    {creativePanel.weights && (
                                        <div className="border-t border-violet-100/80 pt-1.5 text-[9px] text-slate-500">
                                            Weights (visual / copy / context): {creativePanel.weights.visual ?? '—'} ·{' '}
                                            {creativePanel.weights.copy ?? '—'} · {creativePanel.weights.context ?? '—'}
                                        </div>
                                    )}
                                    {creativePanel.trace && Object.keys(creativePanel.trace).length > 0 && (
                                        <div className="border-t border-violet-100/80 pt-1.5 font-mono text-[9px] text-slate-500">
                                            <span>
                                                AI: {creativePanel.trace.creative_ai_ran ? 'yes' : 'no'}
                                            </span>
                                            {' · '}
                                            <span>copy extracted: {creativePanel.trace.copy_extracted ? 'yes' : 'no'}</span>
                                            {' · '}
                                            <span>copy scored: {creativePanel.trace.copy_alignment_scored ? 'yes' : 'no'}</span>
                                            {creativePanel.trace.skip_reason && (
                                                <span className="block text-slate-600">
                                                    skip: {String(creativePanel.trace.skip_reason)}
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </dl>
                            </div>
                        )}

                        <div className="mt-2 flex flex-wrap items-center gap-x-1.5 text-[10px] text-slate-600">
                            <span className="font-medium text-slate-700">Confidence</span>
                            <span className="inline-flex items-center gap-px font-mono leading-none" aria-hidden>
                                {Array.from({ length: CONFIDENCE_SEGMENTS }).map((_, i) => (
                                    <span
                                        key={i}
                                        className={i < confSeg ? 'text-emerald-600' : 'text-slate-300'}
                                    >
                                        ▬
                                    </span>
                                ))}
                            </span>
                            {confidenceBand ? (
                                <span className="capitalize text-slate-600">{confidenceBand}</span>
                            ) : (
                                <span className="text-slate-400">—</span>
                            )}
                        </div>

                        {reference_tier_usage && (
                            <div className="mt-2">
                                <button
                                    type="button"
                                    onClick={() => setRefDetailOpen((o) => !o)}
                                    className="w-full rounded border border-transparent px-0 text-left text-[10px] text-slate-600 hover:border-slate-100 hover:bg-slate-50"
                                >
                                    <span className="font-medium text-slate-700">{referenceSummaryLine(reference_tier_usage)}</span>
                                    <span className="ml-1 text-slate-400">{refDetailOpen ? '▴' : '▾'}</span>
                                </button>
                                {refDetailOpen && (
                                    <ul className="mt-1 space-y-0.5 pl-1 text-[10px] text-slate-600">
                                        <li>Guideline: {reference_tier_usage.guideline}</li>
                                        <li>Promoted: {reference_tier_usage.promoted}</li>
                                        <li>System: {reference_tier_usage.system}</li>
                                    </ul>
                                )}
                            </div>
                        )}

                        {recommendationShort && (
                            <p className="mt-2 line-clamp-3 text-[10px] leading-snug text-slate-600">{recommendationShort}</p>
                        )}

                        {fixHref && (
                            <div className="mt-2">
                                <Link
                                    href={fixHref}
                                    className="inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-medium text-slate-800 shadow-sm hover:bg-slate-50"
                                >
                                    Fix issues
                                </Link>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}

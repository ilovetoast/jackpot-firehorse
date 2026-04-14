# Brand Alignment v2 — Validation Plan

## Purpose

The v2 dimension-based scoring model uses calibration-default thresholds
(rating boundaries 0.75/0.55/0.35, confidence caps, category weights).
Before treating these scores as production-reliable, we need to validate
them against a curated set of real assets and document any threshold adjustments.

---

## 1. Build the Sample Set

Select 20–30 assets that span the following buckets:

| Bucket | Description | Expected outcome | Min count |
|--------|------------|-----------------|-----------|
| **Clearly on-brand** | Hero product shots, campaign ads with logo, approved palette, on-voice copy | Rating 3–4, `on_brand` | 5 |
| **Clearly off-brand** | Competitor assets, random stock, wrong palette, wrong brand name | Rating 1, `off_brand` | 5 |
| **Campaign variant** | Creative that intentionally deviates from master palette/style for a campaign | Should not false-fail; ideally rating 2–3 once campaign context is wired | 3 |
| **Low evidence** | Assets with no OCR, no references, thin metadata | Rating 1, `insufficient_evidence` — NOT a false positive | 4 |
| **PDF / multi-page** | Brand guidelines, presentations, sell sheets | Typography and copy should evaluate; color from rendered pages | 3 |
| **Video** | Branded video clips, social content | Style from keyframes, copy from transcript/OCR | 2 |
| **Ambiguous** | Assets that are borderline — some signals present, some absent | Should land in rating 2 with honest confidence | 3 |

For each asset, record a **human-reviewed expected rating** (1–4) and expected
alignment state before running the engine.

---

## 2. Run Scoring

Re-score each asset through the v2 engine:

```bash
# Single asset via tinker
sail artisan tinker --execute="
  \$asset = \App\Models\Asset::find('ASSET_UUID');
  \$engine = app(\App\Services\BrandIntelligence\BrandIntelligenceEngine::class);
  \$result = \$engine->scoreAsset(\$asset);
  dump([
    'rating' => \$result['breakdown_json']['rating'] ?? null,
    'v2_state' => \$result['breakdown_json']['v2_alignment_state'] ?? null,
    'weighted_score' => \$result['breakdown_json']['weighted_score'] ?? null,
    'confidence' => \$result['breakdown_json']['overall_confidence'] ?? null,
    'evaluable' => \$result['breakdown_json']['evaluable_proportion'] ?? null,
    'dimensions' => collect(\$result['breakdown_json']['dimensions'] ?? [])
        ->map(fn(\$d) => \$d['status'].' | '.(\$d['primary_evidence_source'] ?? '-'))
        ->all(),
  ]);
"
```

Or dispatch jobs in bulk and inspect `brand_intelligence_scores.breakdown_json` afterward.

---

## 3. Record Results

For each asset, capture:

| Field | Source |
|-------|--------|
| Asset ID | DB |
| Human expected rating | Manual |
| v2 rating | `breakdown_json.rating` |
| v2 alignment state | `breakdown_json.v2_alignment_state` |
| Weighted score | `breakdown_json.weighted_score` |
| Overall confidence | `breakdown_json.overall_confidence` |
| Evaluable proportion | `breakdown_json.evaluable_proportion` |
| Per-dimension status | `breakdown_json.dimensions.{dim}.status` |
| Per-dimension primary evidence | `breakdown_json.dimensions.{dim}.primary_evidence_source` |
| Surprising or incorrect? | Manual note |

---

## 4. Validation Checks

### 4a. Rating distribution
- Do clearly on-brand assets consistently score 3–4?
- Do clearly off-brand assets consistently score 1?
- Do low-evidence assets land in `insufficient_evidence` rather than false positives?
- Do ambiguous assets avoid rating 4?

### 4b. Identity dimension (critical)
- For assets where only the filename matches the brand: does Identity show `weak` with
  `primary_evidence_source = metadata_hint`?
- For assets with actual OCR text: does Identity show `aligned` or `partial` with
  `primary_evidence_source = extracted_text`?
- For assets with strong logo embedding similarity: does Identity show `aligned` with
  `primary_evidence_source = visual_similarity`?
- Is Identity never `aligned` from metadata alone?

### 4c. Typography humility
- For images: is Typography consistently `not_evaluable`?
- For PDFs with font metadata: does Typography attempt evaluation?
- Does Typography `not_evaluable` never drag down the overall rating?

### 4d. Copy / Voice: missing vs bad
- For assets with no text: is Copy `not_evaluable`, NOT `weak` or `fail`?
- For assets with extracted text and brand voice config: does Copy produce a
  meaningful `aligned`/`partial`/`weak`/`fail` based on AI analysis?

### 4e. Context Fit narrowness
- Is Context Fit never the primary driver of the rating?
- Is Context Fit `not_evaluable` when no context can be meaningfully assessed?

### 4f. Confidence honesty
- When evaluable_proportion < 0.5, does the rating stay ≤ 2?
- Are confidence values < 0.4 reflected in hedged UI language?

---

## 5. Threshold Tuning

If validation reveals systematic misalignment:

| Problem | Adjustment |
|---------|-----------|
| On-brand assets scoring 2 instead of 3 | Lower rating-3 threshold from 0.55 to 0.50 |
| Off-brand assets scoring 2 instead of 1 | Raise rating-2 threshold from 0.35 to 0.40 |
| Too many assets in `insufficient_evidence` | Review whether weight redistribution is too aggressive |
| Typography weight too high for images | Lower from 0.05 to 0.02 or 0.0 |
| Copy/Voice weight too high for images without text | Verify weight redistribution is zeroing it out |
| Confidence cap too tight | Loosen the `evaluable_proportion + 0.2` cap in `AlignmentScoreDeriver` |

Tuning targets live in `AlignmentScoreDeriver::deriveRating()` and
`EvaluationOrchestrator::WEIGHT_PROFILES`.

---

## 6. Sign-Off Criteria

The v2 scoring is considered validated when:

1. ≥ 80% of sample assets match their human-expected rating within ±1
2. Zero clearly on-brand assets score rating 1
3. Zero clearly off-brand assets score rating 4
4. Zero low-evidence assets produce `on_brand` alignment state
5. Identity dimension never shows `aligned` from metadata alone
6. Typography `not_evaluable` never penalizes the rating
7. Copy `not_evaluable` (no text) never shows as `fail` or `weak`

Once criteria are met, remove the "calibration defaults" caveat from the plan
and document the final thresholds.

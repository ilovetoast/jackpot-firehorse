# Legacy “Brand Compliance” — Deprecation Checklist

**Purpose:** Track **old** on-brand / compliance scoring that predates **Execution-Based Brand Intelligence (EBI)**. When EBI is fully implemented and data is migrated, use this list to remove dead code, tables, and outdated doc references.

**Do not delete prematurely:** Keep until traffic is on `brand_intelligence_scores` / execution scoring and product signs off.

**2026-03-20 cutover:** New rows are **not** written to `brand_compliance_scores` (table retained for audit/migration). Application scoring reads/writes **`brand_intelligence_scores`** only.

---

## Old functionality (summary)

| Area | What it does today |
|------|---------------------|
| **Asset-level compliance score** | **Retired for writes.** Historical rows may exist in `brand_compliance_scores`; live scores use **`brand_intelligence_scores`** (`BrandIntelligenceEngine`). |
| **Pipeline** | `analysis_status` still uses `scoring`; jobs dispatch **`ScoreAssetBrandIntelligenceJob`** (legacy `ScoreAssetComplianceJob` forwards). `BrandComplianceService::upsertScore` is a no-op. |
| **Brand aggregates** | `brand_compliance_aggregates` — per-brand averages (fed by rescore job) |
| **UI** | On-brand % in metadata, sort by compliance, brand dashboard top/bottom assets |
| **Coherence / Alignment (KEEP)** | `BrandCoherenceScoringService`, `BrandAlignmentEngine` score **Brand DNA drafts** — **not** the same as asset compliance; retire only references that confuse the two |

---

## Database — drop after migration & cutover

Run a **new** migration (do not edit historical migration files in git history; add a forward migration that drops when ready).

| Object | Notes |
|--------|--------|
| `brand_compliance_scores` | Per (`brand_id`, `asset_id`); columns include `overall_score`, dimension scores, `breakdown_payload`, `evaluation_status`, `alignment_confidence`, `debug_snapshot` |
| `brand_compliance_aggregates` | One row per `brand_id` — `avg_score`, counts, `last_scored_at` |

**Related migrations (reference only — drops happen in a new migration):**

- `2026_02_16_210000_create_brand_compliance_scores_table.php`
- `2026_02_16_220000_add_evaluation_status_to_brand_compliance_scores_table.php`
- `2026_02_16_220001_make_overall_score_nullable_in_brand_compliance_scores.php`
- `2026_02_17_120000_add_alignment_confidence_to_brand_compliance_scores.php`
- `2026_02_17_180000_add_debug_snapshot_to_brand_compliance_scores.php`
- `2026_02_16_240000_create_brand_compliance_aggregates_table.php`
- `2026_02_16_250000_make_brand_compliance_aggregates_avg_score_nullable.php`
- `2026_02_17_140000_add_analysis_status_to_assets_table.php` — **partial:** `analysis_status` is used beyond scoring; only remove scoring-specific states/usage if pipeline is redesigned |

**Keep (EBI / DNA):**

- `brand_model_versions.model_payload` — still holds DNA; `scoring_rules` may feed **deterministic layer** of EBI
- `brand_visual_references` — reference embeddings (may feed EBI reference pool)
- New EBI tables: `executions`, `execution_assets`, `brand_intelligence_scores`, `ai_usage_logs`, `categories.settings`

---

## Application code — remove or rewrite (grep anchors)

**PHP — core**

- `App\Services\BrandDNA\BrandComplianceService`
- `App\Models\BrandComplianceScore`
- `App\Models\BrandComplianceAggregate`
- `App\Jobs\ScoreAssetComplianceJob`
- `App\Jobs\RescoreBrandExecutionsJob` (name refers to “executions” but targets legacy asset compliance — replace with EBI job)
- References in: `FinalizeAssetJob`, `PopulateAutomaticMetadataJob`, `AssetStateReconciliationService`, `AssetSortService`, `AssetMetadataController`, `BrandController`, `DashboardController`, `DeliverableController`, `AdminAssetController`
- `App\Models\Asset` — scopes `withCompliance`, `scopeHighCompliance`, etc.
- `App\Models\Brand` — `brandComplianceAggregate` relation if present

**Enums / events**

- `EventType` — `ASSET_BRAND_COMPLIANCE_*` constants and any listeners using them

**Frontend**

- `resources/js/Components/SortDropdown.jsx` — compliance sort options
- `resources/js/Components/AssetMetadataDisplay.jsx` — on-brand score display
- `resources/js/Components/AssetTimeline.jsx` — compliance timeline copy
- `resources/js/utils/pipelineStatusUtils.js` — scoring step copy
- Any `usePermission` / grid filters tied to compliance sort

**Tests**

- `tests/Feature/BrandComplianceTest.php`
- `tests/Feature/BrandCompliancePipelineEdgeCasesTest.php`
- Assertions in `AnalysisStatusProgressionTest`, `ThumbnailDerivedMetadataTest`, `MetadataHealthAndReanalyzeTest`, `AssetEmbeddingTest` that assume legacy compliance

---

## Documentation — update or archive after cutover

These **mention** legacy/planned compliance scoring — refresh to describe EBI or mark historical:

| File | Action |
|------|--------|
| [FEATURES_AND_VALUE_PROPOSITION.md](FEATURES_AND_VALUE_PROPOSITION.md) | “On-Brand Scoring & Brand Ranking (Planned)” — align with EBI / Deliverables |
| [TODO.md](TODO.md) | Lines on compliance scoring / DNA — close or retarget to EBI |
| [admin/ai/phase-7-5-tenant-ai-capabilities.md](admin/ai/phase-7-5-tenant-ai-capabilities.md) | Guideline compliance / consistency scoring bullets |
| [BRAND_DNA_SETTINGS_REDESIGN_PLAN.md](BRAND_DNA_SETTINGS_REDESIGN_PLAN.md) | “Scoring rules” in Standards — clarify DNA rules vs EBI execution scoring |
| [BRAND_RESEARCH_FLOW.md](BRAND_RESEARCH_FLOW.md) / [BRAND_RESEARCH_ARCHITECTURE_AUDIT.md](BRAND_RESEARCH_ARCHITECTURE_AUDIT.md) | **Keep** coherence scoring docs; add one line that **asset compliance** moved to EBI |

**Keep without deletion:**

- [BRAND_INTELLIGENCE.md](BRAND_INTELLIGENCE.md) — canonical for the new system

---

## Verification before dropping tables

- [ ] No code references `BrandComplianceScore` / `BrandComplianceAggregate`
- [ ] No routes/jobs dispatch legacy scoring
- [ ] UI does not read `brand_compliance_scores`
- [ ] Background reconciliation / `analysis_status` documented for new pipeline
- [ ] Backup or migrate rows if historical scores matter for reporting

---

## Changelog

| Date | Note |
|------|------|
| 2026-03-21 | Initial checklist from codebase grep + migration inventory |

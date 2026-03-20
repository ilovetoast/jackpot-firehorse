# Brand Intelligence (Execution-Based Brand Intelligence — EBI)

**Status:** In progress — living document  
**Last updated:** 2026-03-21

This doc tracks the **new** Brand Intelligence system (product language: *Execution-Based Brand Intelligence*). It complements the codebase and should be updated as features land.

---

## Goals (target architecture)

- **Primary scoring target:** **Executions** (UI: *Deliverables*) — composed, finalized outputs.
- **Assets:** Optional scoring (category settings + manual “Analyze”); not scored by default.
- **Engine:** Capability-based signals (color, typography, tone, imagery, layout, …) with **confidence** and **LOW / MEDIUM / HIGH** UX bands — not end-user-facing fixed weights.
- **Layers:** Deterministic rules, embeddings (reference pool), AI (budget-guarded).
- **Storage:** New tables (`executions`, `execution_assets`, `brand_intelligence_scores`, `ai_usage_logs`) plus `categories.settings` — see migrations dated `2026_03_21_*` and refine `2026_03_22_100000_refine_ebi_tables_indexes_and_columns.php`.

**Do not confuse with:** *Brand DNA coherence* (draft guidelines completeness) or *Brand Alignment* (cross-field checks on the DNA payload). Those remain separate concerns; see [BRAND_RESEARCH_FLOW.md](BRAND_RESEARCH_FLOW.md).

---

## Phase 1 — Schema (implemented)

| Table | Purpose |
|-------|---------|
| `executions` | Deliverable / execution records (tenant, brand, optional category, status, context, optional `primary_asset_id`, `finalized_at`) |
| `execution_assets` | Pivot: assets attached to an execution (`sort_order`, `role`) |
| `brand_intelligence_scores` | Scores: optional `execution_id` and/or `asset_id`, `overall_score`, `confidence`, `level`, `breakdown_json`, `engine_version`, `ai_used` |
| `ai_usage_logs` | AI usage rows (`tenant_id`, optional `brand_id`, `type`, `created_at`). EBI vision insight uses `type = brand_intelligence_ai`. |
| `categories.settings` | Nullable JSON for per-category EBI toggles / future profile hints |

---

## Implementation log (append as you ship)

| Date | Change |
|------|--------|
| 2026-03-20 | Asset EBI default: `categories.settings.ebi_asset_scoring_enabled` defaults to **true** (was false). Manual `POST /assets/{id}/rescore` deletes asset BIS rows and dispatches `ScoreAssetBrandIntelligenceJob` with `forceRun` so scoring is not skipped by idempotency or category-off. `breakdown_json.recommendations` from `BrandIntelligenceEngine::generateRecommendations`. |
| 2026-03-20 | Legacy `brand_compliance_scores` writes disabled (`BrandComplianceService::upsertScore` no-op); UI and sorts use `brand_intelligence_scores` only; `ENGINE_VERSION` → `v1_reference_embedding_v3_legacy_compliance_retired`. |
| 2026-03-21 | Initial migrations for EBI tables + `categories.settings`; refine migration for indexes/column types (`status`/`level` strings, `engine_version`). |
| 2026-03-21 | `BrandIntelligenceEngine::scoreExecution` (stub: delegates to `BrandComplianceService::scoreAsset`); `ScoreExecutionBrandIntelligenceJob`; `ExecutionController` (`store`, `finalize`, `scoreNow`); routes `brands.executions.*`; finalize/score use `dispatchSync` so scoring runs without a queue worker. |
| 2026-03-21 | Execution scores: `updateOrCreate` on `execution_id` (no duplicate rows); persist `asset_id` + `breakdown_json.source_asset_id`; `[EBI] Execution scored` log line. |
| 2026-03-21 | `BrandIntelligenceEngine`: multi-asset scoring via `execution->assets` — average of valid `scoreAsset` results; confidence `min(0.9, 0.6 + 0.1×scored)`; multi breakdown `multi_asset_aggregation` + `asset_id` null; single-asset keeps `legacy_asset_score` + concrete `asset_id`. |
| 2026-03-21 | Pivot empty + `primary_asset_id` set → fallback to `primaryAsset`; breakdown includes `asset_ids_considered` (all attempted) vs `source_asset_ids` (valid scores only). |
| 2026-03-21 | `BrandIntelligenceEngine`: per-asset `signals` (has_text, has_typography, has_visual) + aggregate `signals` + `per_asset` (`tone_applicable` / `typography_applicable`); no change to compliance math or `BrandComplianceService`. |
| 2026-03-20 | `breakdown_json.scoring_basis`: `single_asset` \| `multi_asset` (constants on `BrandIntelligenceEngine`); persisted `engine_version` / idempotency use `BrandIntelligenceEngine::ENGINE_VERSION` only. |
| 2026-03-20 | `BrandIntelligenceEngine`: `breakdown_json.reference_similarity` from `BrandVisualReference` + `AssetEmbedding` (cosine, mean of top 3); fields `normalized`, `used`; optional ±5 on `overall_score` when `confidence` > 0.5 and normalized similarity is above 0.8 or below 0.4 (`ENGINE_VERSION` bump). |

---

## Related documentation

| Doc | Relevance |
|-----|-----------|
| [BRAND_INTELLIGENCE_LEGACY_DEPRECATION.md](BRAND_INTELLIGENCE_LEGACY_DEPRECATION.md) | **Legacy “brand compliance” scoring** — code/DB to retire after EBI cutover |
| [BRAND_RESEARCH_FLOW.md](BRAND_RESEARCH_FLOW.md) | Brand DNA research, builder, **coherence** scoring (draft quality — not asset execution scoring) |
| [BRAND_RESEARCH_ARCHITECTURE_AUDIT.md](BRAND_RESEARCH_ARCHITECTURE_AUDIT.md) | Gaps in coherence / snapshot / scoring_rules |
| [BRAND_DNA_SETTINGS_REDESIGN_PLAN.md](BRAND_DNA_SETTINGS_REDESIGN_PLAN.md) | Brand DNA settings UI; mentions scoring rules in Standards |
| [FEATURES_AND_VALUE_PROPOSITION.md](FEATURES_AND_VALUE_PROPOSITION.md) | Marketing copy on planned “on-brand” scoring |
| [TODO.md](TODO.md) | Historical notes on compliance scoring / DNA integration |
| [admin/ai/phase-7-5-tenant-ai-capabilities.md](admin/ai/phase-7-5-tenant-ai-capabilities.md) | Planned guideline compliance / consistency scoring |

---

## Naming

| Term | Meaning |
|------|---------|
| **Execution** | First-class entity for multi-asset / contextual deliverables (`executions` table). |
| **Deliverable** | UI label for executions; simple single-file cases may use `Asset` type `deliverable` without a row in `executions` (product rules). |
| **Brand Intelligence score** | Row in `brand_intelligence_scores` (replaces legacy `brand_compliance_scores` long-term). |

---

## Next steps (engineering checklist — not exhaustive)

- [ ] Eloquent models + policies for `Execution`, pivot, `BrandIntelligenceScore`
- [ ] Scoring engine service + pipeline jobs (replace/gate legacy compliance jobs)
- [ ] Category `settings` schema (EBI flags) documented in code or here
- [ ] API + Inertia UI for Deliverables and score display (bands + recommendations)
- [ ] Data migration from `brand_compliance_scores` → `brand_intelligence_scores` (if applicable)
- [ ] Remove legacy stack per [BRAND_INTELLIGENCE_LEGACY_DEPRECATION.md](BRAND_INTELLIGENCE_LEGACY_DEPRECATION.md)

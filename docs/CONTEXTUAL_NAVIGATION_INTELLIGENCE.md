# Contextual Navigation Intelligence

System reference for the Phase 6 Contextual Navigation Intelligence (CNI)
layer. CNI generates **reviewable** recommendations for folder × quick-filter
configuration: which filters to enable, pin, suppress, or warn about. It
does **not** mutate state on its own — every actionable suggestion ends up
in a queue an admin must approve.

This doc is the source of truth for how the pieces fit together. Service
files contain the per-method specifics; this doc is the map.

---

## High-level flow

```
┌──────────────────────────┐
│  Tenant scheduled tick   │  routes/console.php (weekly Mon 04:55)
│  or admin "Analyze" btn  │  POST /app/api/ai/review/contextual/run
└──────────────┬───────────┘
               │
               ▼
┌──────────────────────────┐
│ RunContextualNavigation  │  app/Jobs/
│ InsightsJob              │  - master/insights gates
│                          │  - min_assets gate
│                          │  - cooldown gate (Cache: tenant:{id}:contextual_navigation_insights:last_run_at)
└──────┬─────────┬─────────┘
       │         │
       │         └────► ContextualNavigationStaleResolver
       │                  (pending/deferred → stale when state already matches
       │                   recommendation, or last_seen_at older than TTL)
       │
       ▼
┌──────────────────────────┐
│ ContextualNavigation     │  Statistical only.
│ Recommender              │  • countFolderAssets
│                          │  • countAssetsWithFieldPopulated
│                          │  • countDistinctValuesInFolder
│                          │  • countAliases (delegates to MetadataCanonicalizationService)
│                          │  • duplicateCandidates (MetadataDuplicateDetector)
│                          │  • computeFromCounters → score bundle
│                          │  • upsert into contextual_navigation_recommendations
└──────────────┬───────────┘
               │  candidate IDs
               ▼
┌──────────────────────────┐
│ ContextualNavigation     │  Optional, gated by `use_ai_reasoning`.
│ AiReasoner               │  • selectBorderlineForAi (recommender helper)
│                          │  • AiUsageService::checkUsage  (per call)
│                          │  • AIService::executeAgent (contextual_navigation_intelligence)
│                          │  • AiUsageService::trackUsageWithCost (on success)
│                          │  Updates reason_summary, source=hybrid, confidence.
└──────────────────────────┘
```

Every AI call **must** flow through `AIService::executeAgent` — there are no
direct provider clients in this system. Credit accounting routes through
`AiUsageService` (feature key `contextual_navigation`), which writes to the
`ai_usage` ledger and `ai_agent_runs` audit table for free.

---

## Component map

### Backend

| Path | Role |
|---|---|
| `config/contextual_navigation_insights.php` | Single config surface: gates, thresholds, cooldown, AI behavior, queue. |
| `config/ai.php` (`agents.contextual_navigation_intelligence`) | AI agent registration: model, scope, default permissions. |
| `config/ai_credits.php` (`weights.contextual_navigation`) | Credit cost per AI reasoning call. |
| `app/Enums/AITaskType.php::CONTEXTUAL_NAVIGATION_REASONING` | Task type used by `AIService::executeAgent`. |
| `app/Models/ContextualNavigationRecommendation.php` | Eloquent model, type/status/source enums, `isActionable()`. |
| `app/Services/ContextualNavigation/ContextualNavigationScoringService.php` | Pure stats — coverage, narrowing power, etc. |
| `app/Services/ContextualNavigation/ContextualNavigationRecommender.php` | Iterates folders × fields, applies thresholds, upserts pending rows. |
| `app/Services/ContextualNavigation/ContextualNavigationAiReasoner.php` | Optional rationale enrichment via `AIService` + credit ledger. |
| `app/Services/ContextualNavigation/ContextualNavigationStaleResolver.php` | pending/deferred → stale when redundant or expired. |
| `app/Services/ContextualNavigation/ContextualNavigationApprovalService.php` | Approval router → delegates to `FolderQuickFilterAssignmentService`. |
| `app/Services/ContextualNavigation/ContextualNavigationPayloadService.php` | Read-only helpers for Inertia payloads (overview / inline hints). |
| `app/Jobs/RunContextualNavigationInsightsJob.php` | Orchestrator job. Cooldowns, gates, calls services in order. |
| `app/Http/Controllers/Insights/ContextualNavigationReviewController.php` | List / approve / reject / defer / run endpoints. |
| `app/Http/Controllers/AiReviewController.php` | Owns `type=contextual` tab counting + delegation. |

### Frontend

| Path | Role |
|---|---|
| `resources/js/utils/contextualNavigationRecommendations.js` | Single source of truth for recommendation_type → label / intent / score formatting. |
| `resources/js/Components/insights/ContextualNavigationReviewTab.jsx` | Insights → Review "Contextual navigation" tab. Self-contained fetch + actions. |
| `resources/js/Components/insights/ContextualNavigationOverviewCard.jsx` | Insights → Overview lightweight summary card (top 4). |
| `resources/js/Components/Metadata/FolderSchemaHelp.jsx` (`ContextualNavHintsRow`) | Inline manage hints below quick-filter rows. |
| `resources/js/contexts/InsightsCountsContext.jsx` | Tracks tab counts globally; `contextual` is one of `tags / categories / values / fields / contextual`. |
| `resources/js/Pages/Insights/Review.jsx` | Review page tab switcher; `mergedAiTabCounts` + `aiSuggestionsGrandTotal`. |

---

## Recommendation types

The model defines exactly the types the recommender emits — no orphans.

**Actionable** (approval mutates `metadata_field_visibility` via
`FolderQuickFilterAssignmentService`):

- `suggest_quick_filter` → `enableQuickFilter(source: 'ai_suggested')`
- `suggest_pin_quick_filter` → `enableQuickFilter(pinned: true)`
- `suggest_unpin_quick_filter` → `setQuickFilterPinned(false)`
- `suggest_disable_quick_filter` → `disableQuickFilter`
- `suggest_move_to_overflow` → `updateQuickFilterWeight(9999)` so it falls below `max_visible_per_folder`

**Warning** (informational only — `approve()` throws):

- `warn_high_cardinality`
- `warn_low_navigation_value`
- `warn_metadata_fragmentation`
- `warn_low_coverage`

> A `warn_duplicate_contextual_filter` was reserved during Phase 6 design
> but never wired into the recommender. It was removed in the consolidation
> pass to keep the type list 1:1 with what's emitted. Re-introduce it
> together with a producer (cross-field similarity scorer) when the work is
> actually scoped.

---

## Lifecycle states

```
       (recommender run)              (admin review)
  ╔══════════════╗  approve  ╔════════════╗
  ║              ║──────────►║  accepted  ║
  ║              ║  reject   ╠════════════╣
  ║   pending    ║──────────►║  rejected  ║
  ║              ║  defer    ╠════════════╣
  ║              ║──────────►║  deferred  ║──┐ next run can re-promote to pending
  ╚══════╤═══════╝           ╚════════════╝  │
         │                                    │
         │ stale resolver (state matches OR   │
         │  last_seen_at < now - TTL)         │
         ▼                                    ▼
  ╔══════════════╗                    ╔══════════════╗
  ║    stale     ║◄───────────────────║              ║
  ╚══════════════╝   only pending/deferred move to stale.
                     accepted/rejected are NEVER touched.
```

`accepted` and `rejected` are terminal. `deferred` is a soft hold — the next
recommender run upserts the row back to `pending` if it still corroborates
(see `ContextualNavigationRecommender::upsertRecommendation`).

---

## AI credit attribution

| Concern | Where it lives |
|---|---|
| Master kill switch | `tenant.settings['ai_enabled']` (checked by job + `AiUsageService::checkUsage`) |
| Per-feature gate | `tenants.ai_insights_enabled` (job-level) |
| Quota | `AiUsageService` against feature key `contextual_navigation` |
| Per-call check | `AiUsageService::checkUsage($tenant, 'contextual_navigation', 1)` before each `executeAgent` |
| Per-call debit | `AiUsageService::trackUsageWithCost(...)` ONLY on success |
| Audit | `ai_agent_runs` row written by `AIService::executeAgent` (entity_type = `contextual_navigation_recommendation`, entity_id = recommendation id) |

Statistical-only runs don't call `executeAgent`, don't call `trackUsageWithCost`,
and produce zero `ai_agent_runs` rows. This is the steady-state behavior:
`use_ai_reasoning` defaults to `false`. Tests assert this contract.

---

## Operational knobs

All in `config/contextual_navigation_insights.php`:

- `enabled` — master kill switch for the whole feature.
- `scheduled_enabled` — disables the weekly dispatcher only (manual still works).
- `min_assets_per_tenant` / `min_assets_per_folder` — eligibility thresholds.
- `min_distinct_values_per_field` — ignore single-value fields entirely.
- `run_cooldown_hours` — per-tenant; admin "Analyze" can pass `force=1` to bypass.
- `recommendation_ttl_days` — TTL for pending rows that aren't re-emitted.
- `max_recommendations_per_run` — cap on writes per run (default 200).
- `score_thresholds.{type}` — per-type promotion thresholds.
- `use_ai_reasoning` (default false) — turns on `ContextualNavigationAiReasoner`.
- `ai_reasoning_borderline_band` — only runs the reasoner on rows whose score is within ± band of the threshold.
- `max_ai_calls_per_run` — hard cap regardless of credit pool size.
- `queue` — job queue name (defaults `default`).
- `agent_key` / `usage_feature` — AI agent and credit feature identifiers.

---

## Permissions

| Action | Required |
|---|---|
| List `type=contextual` | `metadata.suggestions.view` OR `metadata.review_candidates` (contributor path) OR tenant `metadata.tenant.visibility.manage` |
| Approve / reject / defer | `metadata.suggestions.apply` OR tenant `metadata.tenant.visibility.manage` |
| Manual run trigger | tenant `metadata.tenant.visibility.manage` (admin only — kicks off a job) |

The "manage" tenant permission is intentionally a superset, so workspace
admins always have access without needing the editorial permissions.

---

## Cache keys

- `tenant:{id}:contextual_navigation_insights:last_run_at` — cooldown stamp (forever-cached, gated by `run_cooldown_hours` against `now()`).

Mirrors `RunMetadataInsightsJob`'s `tenant:{id}:metadata_insights:last_run_at`.
Always tenant-prefixed; safe across tenants.

---

## Testing

`tests/Feature/ContextualNavigation/` covers:

- Scoring: high-coverage success, high-cardinality collapse, persisted high-card flag, fragmentation penalty, unused filter, zero coverage, empty folder.
- Recommender: strong-filter emission, pinned-but-monotone unpin, no double-suggest for already enabled, idempotent reruns, min-assets folder skip.
- Approval: enable/pin/disable visibility mutation through assignment service, reject is non-mutative, warnings can't be approved, finalised rows can't be re-acted.
- Stale resolver: already-applied → stale, TTL → stale, accepted/rejected untouched.
- Job: master AI gate, ai_insights gate, statistical-only run does not debit credits, cooldown enforcement, force bypass.
- Review controller: list, approve routes to assignment service, reject status-only, run dispatches job, permission gating returns 403, contextual count appears in `/api/ai/review/counts`.

---

## Anything intentionally deferred

- Cross-field "duplicate contextual filter" detection (the producer for the
  reserved-then-removed `warn_duplicate_contextual_filter` type).
- Post-metadata-change opportunistic runs (only weekly + manual today).
- Tenant-onboarding bootstrap auto-trigger.
- Hybrid-source score blending (current AI behavior is rationale rewrite
  only; `source` flips to `hybrid` but the score is still the recommender's).

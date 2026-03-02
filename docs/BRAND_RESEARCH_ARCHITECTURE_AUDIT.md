# Brand Research + Research Insights — Architecture Audit & Restoration Plan

## Phase 1 — Architecture Audit

### 1.1 Snapshot Creation

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `RunBrandResearchJob.php`, `BrandResearchSnapshot` model, `brand_research_snapshots` table | Same |
| **Trigger** | POST `trigger-research` with `{ url }` → dispatches `RunBrandResearchJob` | Same |
| **Creation** | Creates snapshot with `brand_id`, `brand_model_version_id`, `source_url`, `status: running` | Same |
| **Snapshot data** | After `sleep(2)`, stores **placeholder** `snapshot`: `{ logo_url: null, primary_colors: [], detected_fonts: [], hero_headlines: [], brand_bio: null }` | Crawler would populate from website |
| **Version scoping** | Snapshot correctly stores `brand_model_version_id` | Same |

**Gaps:**
- Crawler step is a stub; no real extraction
- Snapshot fields are never populated
- No `source_snapshot_id` passed to `getOrCreateInsightState` when research completes

---

### 1.2 Suggestion Generation

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `RunBrandResearchJob::generateSuggestions()` | Same + AI service (future) |
| **Input** | Draft payload only | Draft + snapshot (brand_bio, hero_headlines) |
| **Output** | `recommended_archetypes` — keyword match on `identity.industry` + `identity.mission` | Same + `mission_suggestion`, `positioning_suggestion` from AI |
| **Logic** | `suggestArchetypesFromText()` — simple keyword hints; fallback `['Creator','Everyman','Sage']` | ML/AI inference from crawl text |

**Gaps:**
- `mission_suggestion`, `positioning_suggestion` expected by Builder UI but never generated
- Snapshot data (`brand_bio`, `hero_headlines`) not used
- No SUG: prefix on suggestion keys (user requirement: normalized keys)

---

### 1.3 Coherence Scoring

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `BrandCoherenceScoringService.php` | Same |
| **Input** | `draftPayload`, `snapshotSuggestions`, `snapshotRaw`, `brand`, `brandMaterialCount` | Same |
| **Snapshot usage** | `snapshotRaw` (3rd param) is **never read** — dead parameter | Compare draft vs snapshot; boost/penalize based on crawl alignment |
| **Suggestion usage** | `suggestionBoost = min(10, count(suggestions)*2)` — only count used | Same + use suggestion content for scoring |
| **Sections** | background, archetype, purpose, expression, positioning, standards — all from draft | Same; snapshot could inform confidence |

**Gaps:**
- `snapshotRaw` is dead code — never influences scoring
- No comparison of draft colors vs `snapshot.primary_colors`
- No comparison of draft fonts vs `snapshot.detected_fonts`
- Risk IDs (COH:WEAK_*) not prefixed (e.g. FND: or COH:)

---

### 1.4 Alignment Engine

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `BrandAlignmentEngine.php` | Same |
| **Input** | Draft payload only | Draft + optionally snapshot for cross-check |
| **Checks** | archetype↔tone, purpose↔audience, standards↔mood, typography↔voice, positioning complete | Same |
| **Output** | `findings[]` with `id`, `severity`, `title`, `detail`, `affected_paths`, `suggestion` | Same |

**Gaps:**
- Snapshot not used — could validate draft vs crawled voice/positioning
- Finding IDs (ALIGN:*) not prefixed with FND: when stored in dismissed
- `suggestion.path` uses dot notation (e.g. `scoring_rules.tone_keywords`) — Apply logic works

---

### 1.5 Insight Persistence

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `BrandModelVersionInsightState`, `BrandDNABuilderController::dismissInsight`, `acceptInsight` | Same |
| **Storage** | `dismissed[]`, `accepted[]` — raw keys (e.g. `ALIGN:ARCHETYPE_TONE_MISMATCH`) | Normalized: `FND:ALIGN:...`, `SUG:...` |
| **Scope** | Per `brand_model_version_id` — correct | Same |
| **source_snapshot_id** | Never set — `getOrCreateInsightState()` called without snapshot ID | Set when research completes; link state to snapshot |
| **Inline dismissals** | `dismissedInlineSuggestions` — **local React state only**, not persisted | Should persist or use insight state |

**Gaps:**
- Keys not normalized (SUG:, FND:)
- `source_snapshot_id` never populated
- Inline suggestion dismissals (archetype, mission, positioning) lost on refresh
- `onAccept` exists but is not wired in ResearchInsightsPanel UI

---

### 1.6 ResearchInsightsPanel Rendering

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Files** | `ResearchInsightsPanel.jsx` | Same |
| **Props** | `crawlerRunning`, `latestSnapshotLite`, `latestCoherence`, `latestAlignment`, `latestSuggestions`, `insightState`, `stepKeys`, `onDismiss`, `onAccept`, `onApplySuggestion`, `onJumpToStep` | Same |
| **Dismiss filter** | `!dismissed.includes(f.id) && !dismissed.includes(\`FND:${f.id}\`)` | Accepts both; backend stores raw |
| **Jump-to-step** | `RISK_TO_STEP` maps risk/suggestion keys → step keys; `onJumpToStep(stepKey)` | Same |
| **Apply suggestion** | `onApplySuggestion(f)` — patches local payload via `finding.suggestion.path` + `value` | Same; requires user to click Next to persist |

**Gaps:**
- `onAccept` passed but never used in panel (no Accept button)
- Coherence risks not dismissable (no Dismiss on risk items)
- Suggestion pills only jump — no Apply for suggestion-type items from `latestSuggestions`
- Inline suggestions (step-level) use separate `dismissedInlineSuggestions` — not in insight state

---

### 1.7 Snapshot Query & Version Scoping

| Aspect | Current State | Intended State |
|--------|---------------|----------------|
| **Query** | `BrandResearchSnapshot::where('brand_id', $brand->id)->where('status','completed')->latest()->first()` | Should also filter `brand_model_version_id = $draft->id` |
| **Result** | May return snapshot from a different draft version | Must return snapshot for current draft |

**Gaps:**
- **Critical:** Snapshot query ignores `brand_model_version_id` — multi-version architecture violated

---

### 1.8 Dead Code & Unused Fields

| Item | Location | Status |
|------|----------|--------|
| `snapshotRaw` param | BrandCoherenceScoringService::score() | Never used |
| `snapshot.logo_url` | RunBrandResearchJob | Always null |
| `snapshot.primary_colors` | RunBrandResearchJob | Always [] |
| `snapshot.detected_fonts` | RunBrandResearchJob | Always [] |
| `snapshot.hero_headlines` | RunBrandResearchJob | Always [] |
| `snapshot.brand_bio` | RunBrandResearchJob | Always null |
| `source_snapshot_id` | BrandModelVersionInsightState | Never set |
| `onAccept` | ResearchInsightsPanel | Passed but unused |
| `mission_suggestion`, `positioning_suggestion` | Builder.jsx InlineSuggestionBlock | Expected, never in suggestions |

---

### 1.9 Draft Fields Expected but Not in Normalizer Defaults

| Field | Used By | In Normalizer Defaults? |
|-------|---------|-------------------------|
| `identity.market_category` | scorePositioning, BrandAlignmentEngine | No |
| `identity.competitive_position` | scorePositioning, BrandAlignmentEngine | No |
| `personality.brand_look` | scoreExpression | No |
| `personality.voice_description` | scoreExpression | No |

*Note: Normalizer only adds missing keys; existing keys preserved. These are written by patch when user fills them. Absence from defaults means new payloads won't have them until user input — acceptable but could be added for clarity.*

---

## Phase 2 — Intended Architecture Definition

### Layer 1: Crawl Layer (Raw Extraction)

| Aspect | Detail |
|--------|--------|
| **Input** | Website URL, optional: sitemap, robots.txt |
| **Output** | Raw extraction: HTML, CSS, images, text blocks |
| **Status** | **Missing** — stub only |
| **Restore/Implement** | Implement crawler service that fetches URL, parses DOM, extracts: logo URLs, CSS color values, font-family declarations, h1/h2 text, meta description, about sections |

---

### Layer 2: Snapshot Layer (Structured Data Model)

| Aspect | Detail |
|--------|--------|
| **Input** | Raw crawl output |
| **Output** | `snapshot` JSON: `{ logo_url, primary_colors[], detected_fonts[], hero_headlines[], brand_bio }` |
| **Status** | **Partial** — schema defined, values stubbed |
| **Restore/Implement** | Wire crawler output → snapshot; ensure `brand_model_version_id` scoping on all snapshot queries |

---

### Layer 3: Interpretation Layer (AI / Signal Inference)

| Aspect | Detail |
|--------|--------|
| **Input** | Snapshot + draft payload |
| **Output** | `suggestions`: `{ recommended_archetypes[], mission_suggestion?, positioning_suggestion?, tone_suggestions? }` |
| **Status** | **Partial** — `recommended_archetypes` from draft keywords only |
| **Restore/Implement** | Use `snapshot.brand_bio`, `hero_headlines` for archetype/mission/positioning inference; add AI/ML when available |

---

### Layer 4: Comparison Layer (Coherence + Alignment)

| Aspect | Detail |
|--------|--------|
| **Input** | Draft payload, snapshot, suggestions |
| **Output** | `coherence`: sections, overall score, strengths, risks; `alignment`: findings with suggestions |
| **Status** | **Partial** — coherence and alignment run but ignore snapshot |
| **Restore/Implement** | Use `snapshotRaw` in coherence (draft vs snapshot comparison); optionally in alignment; normalize IDs (FND:, COH:, SUG:) |

---

### Layer 5: Presentation Layer (ResearchInsightsPanel)

| Aspect | Detail |
|--------|--------|
| **Input** | `latestCoherence`, `latestAlignment`, `latestSuggestions`, `insightState` |
| **Output** | Rendered panel: status, coherence score, risks, alignment findings, suggestion pills |
| **Status** | **Complete** — renders correctly |
| **Restore/Implement** | Wire Accept button; persist inline dismissals; ensure jump links work for all step keys; add Apply for suggestion-type items where applicable |

---

## Phase 3 — Gap Analysis

### Critical (Breaks Core Logic)

| ID | Gap | Impact |
|----|-----|--------|
| C1 | Snapshot query does not filter by `brand_model_version_id` | Research insights can show data from wrong draft version |
| C2 | `source_snapshot_id` never set on InsightState | Cannot correlate dismissed/accepted with snapshot that produced them |
| C3 | Apply suggestion only updates local state; requires Next to persist | Expected — but if user navigates away without Next, changes lost |

### Functional (Feature Missing, System Works)

| ID | Gap | Impact |
|----|-----|--------|
| F1 | Crawler is stub — no extraction | Snapshot always empty; no prefill from website |
| F2 | `mission_suggestion`, `positioning_suggestion` never generated | InlineSuggestionBlock for Purpose step never shows AI suggestions |
| F3 | Coherence ignores `snapshotRaw` | No draft-vs-crawl comparison |
| F4 | Alignment ignores snapshot | No cross-check of draft vs crawled signals |
| F5 | Inline dismissals (archetype, mission, positioning) not persisted | User must re-dismiss on refresh |

### UX (Panel Behavior, Suggestion UX)

| ID | Gap | Impact |
|----|-----|--------|
| U1 | No Accept button in ResearchInsightsPanel | `onAccept` unused |
| U2 | Coherence risks not dismissable | User cannot hide risks |
| U3 | Suggestion pills only jump — no Apply for `latestSuggestions` items | User must manually copy suggestion content |
| U4 | RISK_TO_STEP may miss some keys | Some suggestions might not map to valid step |

### Data Integrity (Versioning, Keys, Normalization)

| ID | Gap | Impact |
|----|-----|--------|
| D1 | Insight keys not normalized (SUG:, FND:) | Inconsistent key format; harder to reason about |
| D2 | `market_category`, `competitive_position`, `brand_look`, `voice_description` not in normalizer defaults | Minor — patch adds them when user fills |
| D3 | Draft and active DNA separation | Correct — no gap |

---

## Phase 4 — Phased Restoration Plan

### Phase A — Restore Core Data Flow Integrity

**Goal:** Ensure snapshot and insights are correctly version-scoped and linked.

| Task | Files | Services | Tests | Success |
|------|-------|----------|-------|---------|
| Filter snapshot query by `brand_model_version_id` | `BrandDNABuilderController.php` (show, researchInsights, researchDebug) | — | `BrandGuidelinesBuilderTest`: assert research-insights returns snapshot for current draft only | Snapshot returned matches draft version |
| Set `source_snapshot_id` when research completes | `RunBrandResearchJob.php` | — | Assert insight state has source_snapshot_id after job | InsightState links to snapshot |
| Call `getOrCreateInsightState($snapshot->id)` after snapshot save | `RunBrandResearchJob.php` | `BrandModelVersion::getOrCreateInsightState` | — | State linked to snapshot |

---

### Phase B — Restore Snapshot-to-Scoring Connection

**Goal:** Coherence and alignment use snapshot data when available.

| Task | Files | Services | Tests | Success |
|------|-------|----------|-------|---------|
| Use `snapshotRaw` in coherence: compare draft colors vs `snapshot.primary_colors` | `BrandCoherenceScoringService.php` | — | Unit test: coherence score differs when snapshot has colors | Snapshot influences scoring |
| Use `snapshotRaw` in coherence: compare draft fonts vs `snapshot.detected_fonts` | `BrandCoherenceScoringService.php` | — | Same | Font alignment affects score |
| Add optional snapshot input to alignment (future) | `BrandAlignmentEngine.php` | — | — | Alignment can use snapshot when provided |
| Remove or document dead `snapshotRaw` if not yet used | — | — | — | No dead params |

---

### Phase C — Restore Suggestion-to-Draft Patch Logic

**Goal:** Apply suggestion persists correctly; inline dismissals persisted.

| Task | Files | Services | Tests | Success |
|------|-------|----------|-------|---------|
| Normalize insight keys: store `FND:{id}` for findings, `SUG:{key}` for suggestions | `BrandDNABuilderController::dismissInsight`, `ResearchInsightsPanel` | — | Assert dismissed contains FND: prefix | Keys normalized |
| Update ResearchInsightsPanel filter to expect normalized keys | `ResearchInsightsPanel.jsx` | — | — | Dismiss works with FND: |
| Persist inline dismissals to insight state (e.g. `purpose:mission`, `archetype:recommended`) | `Builder.jsx`, `BrandDNABuilderController` | — | Assert dismissed includes inline keys after dismiss | Inline dismissals survive refresh |
| Add Apply for suggestion-type items (e.g. from `latestSuggestions.recommended_archetypes`) | `ResearchInsightsPanel.jsx`, `Builder.jsx` | — | — | User can Apply from panel |
| Wire Accept button if needed | `ResearchInsightsPanel.jsx` | — | — | Accept used or removed |

---

### Phase D — Reintroduce Minimal Crawler Signals

**Goal:** Populate snapshot with real data from URL.

| Task | Files | Services | Tests | Success |
|------|-------|----------|-------|---------|
| Create `BrandWebsiteCrawlerService` | New: `app/Services/BrandDNA/BrandWebsiteCrawlerService.php` | — | Unit test: extracts colors, fonts, headlines from HTML | Crawler returns structured data |
| Call crawler in RunBrandResearchJob | `RunBrandResearchJob.php` | `BrandWebsiteCrawlerService` | Integration test: job produces non-empty snapshot | Snapshot populated |
| Map crawler output to snapshot schema | `RunBrandResearchJob.php` | — | Assert snapshot has logo_url or primary_colors when site has them | Schema populated |

---

### Phase E — Advanced AI Inference

**Goal:** Generate mission_suggestion, positioning_suggestion from crawl text.

| Task | Files | Services | Tests | Success |
|------|-------|----------|-------|---------|
| Add `mission_suggestion` to generateSuggestions when snapshot.brand_bio present | `RunBrandResearchJob.php` | Optional: AI/LLM service | — | Purpose step shows AI suggestion |
| Add `positioning_suggestion` similarly | `RunBrandResearchJob.php` | — | — | Same |
| Improve archetype suggestions using brand_bio | `RunBrandResearchJob.php` | — | — | Better archetype recommendations |

---

## Summary Table

| Phase | Focus | Critical Path |
|-------|-------|----------------|
| A | Data flow integrity | Snapshot version scoping, source_snapshot_id |
| B | Snapshot → scoring | Coherence uses snapshotRaw |
| C | Suggestion UX | Key normalization, inline persistence, Apply |
| D | Crawler | Real extraction → snapshot |
| E | AI | mission/positioning suggestions |

---

## File Reference

| Component | Primary Files |
|-----------|---------------|
| Snapshot creation | `RunBrandResearchJob.php`, `BrandResearchSnapshot.php` |
| Suggestion generation | `RunBrandResearchJob::generateSuggestions()` |
| Coherence scoring | `BrandCoherenceScoringService.php` |
| Alignment engine | `BrandAlignmentEngine.php` |
| Insight persistence | `BrandModelVersionInsightState.php`, `BrandDNABuilderController::dismissInsight`, `acceptInsight` |
| Research panel | `ResearchInsightsPanel.jsx` |
| Builder integration | `Builder.jsx`, `InlineSuggestionBlock.jsx` |
| Controller | `BrandDNABuilderController.php` |
| Draft service | `BrandDnaDraftService.php` |
| Payload normalizer | `BrandDnaPayloadNormalizer.php` |

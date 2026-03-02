# Brand Research — Process & Flow

## Overview

Brand research runs when a user enters a website URL in the **Background** step and clicks **Analyze & Prefill**. It creates a snapshot, (intended to) crawl the site, then computes suggestions, coherence, and alignment. Results appear in the **Research Insights** panel.

---

## Current State: Crawl is a Stub

**The website crawler is not implemented.** `RunBrandResearchJob` currently:

1. Creates a `BrandResearchSnapshot` with `status: running`
2. Sleeps 2 seconds (simulates crawl)
3. Stores a **placeholder snapshot** with empty arrays:
   - `logo_url` → null
   - `primary_colors` → []
   - `detected_fonts` → []
   - `hero_headlines` → []
   - `brand_bio` → null
4. Generates **suggestions** from the **draft payload** (industry + mission) — keyword-based archetype hints
5. Computes **coherence** from the draft payload (no snapshot data used)
6. Computes **alignment** from the draft payload (cross-field validation rules)

---

## Planned Snapshot Schema (Crawl Output)

When a real crawler is implemented, these fields are intended to be populated:

| Field | Type | Description |
|-------|------|-------------|
| `logo_url` | string | URL of detected logo image |
| `primary_colors` | string[] | Hex colors extracted from site (CSS, images) |
| `detected_fonts` | string[] | Font families from CSS |
| `hero_headlines` | string[] | Main headlines from page |
| `brand_bio` | string | About/description text |

**Future:** AI could derive `industry`, `voice_tone`, `mission_suggestion`, `positioning_suggestion` from `brand_bio` and `hero_headlines`.

---

## Planned Suggestions Schema

| Key | Source | Description |
|-----|--------|-------------|
| `recommended_archetypes` | draft (industry + mission) | Keyword-based archetype suggestions |
| `mission_suggestion` | *(future)* snapshot.brand_bio | AI-generated Why statement |
| `positioning_suggestion` | *(future)* snapshot.brand_bio | AI-generated What statement |

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│ 1. User enters website URL in Background step                            │
│    → POST /brands/{brand}/brand-dna/builder/trigger-research { url }      │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 2. RunBrandResearchJob dispatched                                       │
│    - Creates BrandResearchSnapshot (status: running)                     │
│    - [CRAWLER]: Would fetch URL, extract logo, colors, fonts, etc.       │
│    - Currently: sleep(2), placeholder snapshot                           │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 3. Draft payload loaded (from BrandModelVersion.model_payload)           │
│    - sources, identity, personality, typography, visual, scoring_rules  │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 4. Generate suggestions (from draft)                                    │
│    - recommended_archetypes: keyword match on industry + mission        │
│    - mission_suggestion, positioning_suggestion: not yet implemented   │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 5. BrandCoherenceScoringService.score(draft, suggestions, snapshot)      │
│    - Scores: background, archetype, purpose, expression, positioning,   │
│      standards                                                           │
│    - Uses snapshot only for suggestionBoost (count of suggestions)       │
│    - All section scores come from draft payload                          │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 6. BrandAlignmentEngine.analyze(draft)                                   │
│    - Cross-field validations: archetype↔tone, positioning, typography   │
│    - Produces findings with severity and suggestions                    │
│    - Does not use snapshot data                                         │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 7. Snapshot updated with snapshot, suggestions, coherence, alignment    │
│    - Stored in brand_research_snapshots                                 │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 8. Frontend polls GET /brands/{brand}/brand-dna/builder/research-insights│
│    - Returns: latestSnapshotLite, latestSuggestions, latestCoherence,   │
│      latestAlignment, crawlerRunning                                    │
└─────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────┐
│ 9. Research Insights panel displays                                     │
│    - Brand Coherence: section scores, strengths, risks                   │
│    - Top risks: from coherence.risks                                   │
│    - Alignment Findings: suggestions with Apply / Dismiss               │
│    - Suggestion pills: jump to step (archetype, purpose, etc.)          │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Brand Guidelines ↔ Research Insights

| Research Insight | Source | Draft Field(s) |
|------------------|--------|----------------|
| Brand Coherence score | BrandCoherenceScoringService | `sources`, `identity`, `personality`, `typography`, `visual`, `scoring_rules` |
| Top risks | coherence.risks | Weak sections (e.g. COH:WEAK_PURPOSE) |
| Alignment findings | BrandAlignmentEngine | archetype↔tone, purpose↔audience, positioning, typography |
| Recommended archetypes | RunBrandResearchJob | `identity.industry` + `identity.mission` |

**Apply suggestion:** When user clicks "Apply suggestion", the frontend patches the draft payload at the suggested path (e.g. `scoring_rules.tone_keywords`).

---

## How to Debug Locally

### Option 1: Debug endpoint (see below)

Add a route and controller method that returns the full snapshot, draft, and computed outputs.

### Option 2: Inspect database

```sql
SELECT id, brand_id, source_url, status, snapshot, suggestions, coherence, alignment, created_at
FROM brand_research_snapshots
WHERE brand_id = ? ORDER BY created_at DESC LIMIT 1;
```

### Option 3: Run job synchronously

In `app/Providers/AppServiceProvider` or a test, dispatch sync:

```php
RunBrandResearchJob::dispatchSync($brandId, $url);
```

### Option 4: Add logging

In `RunBrandResearchJob::handle`:

```php
\Log::info('Brand research snapshot', [
    'brand_id' => $this->brandId,
    'url' => $this->sourceUrl,
    'snapshot' => $snapshot->snapshot,
    'suggestions' => $suggestions,
    'coherence' => $coherence,
    'alignment' => $alignment,
]);
```

---

## Debug Endpoint

A debug endpoint is available at:

```
GET /brands/{brand}/brand-dna/builder/research-debug
```

Returns full snapshot, draft payload, computed coherence, alignment, and suggestions. **Only available when `APP_DEBUG=true`.**

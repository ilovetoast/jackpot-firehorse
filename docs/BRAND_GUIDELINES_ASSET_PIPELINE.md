# Brand Guidelines: Asset Pipeline & Research Insights

This document outlines how Brand Guidelines grabs assets, how they get processed, the pipeline that runs once they're ready, and how research insights get applied. Use this for alignment on the full flow.

---

## Part 1: Asset Acquisition & Contexts

### How Assets Are Grabbed

| Source | Context | How It's Attached |
|--------|---------|-------------------|
| **Brand Guidelines PDF** | `guidelines_pdf` | User uploads via `BuilderUploadDropzone` → `POST /app/uploads/initiate` → finalize → `POST /brands/{brand}/brand-dna/builder/attach-asset` with `builder_context=guidelines_pdf` |
| **Website URL** | — | User enters in Background step; stored in `model_payload.sources.website_url` |
| **Social URLs** | — | User enters in Background step; stored in `model_payload.sources.social_urls` |
| **Brand Materials** | `brand_material` | User selects from asset library via `BrandAssetSelectorModal` → `attach-asset` with `builder_context=brand_material` |

Assets are linked to the draft via `brand_model_version_assets` with a `builder_context`. The PDF is the only one that triggers extraction; website/social trigger the crawler; materials are passed to ingestion when it runs.

---

## Part 2: PDF Processing Pipeline — OCR vs Vision Switch

The PDF pipeline has **two paths** depending on whether we can extract selectable text:

```
                    ┌─────────────────────────────────────────────────────────┐
                    │ 1. User uploads PDF → Attach to draft (guidelines_pdf)  │
                    │    POST /app/assets/{asset}/pdf-text-extraction         │
                    └─────────────────────────────────────────────────────────┘
                                                │
                                                ▼
                    ┌─────────────────────────────────────────────────────────┐
                    │ 2. ExtractPdfTextJob runs pdftotext (poppler-utils)      │
                    │    → Extracts selectable text from PDF                   │
                    └─────────────────────────────────────────────────────────┘
                                                │
                        ┌───────────────────────┴───────────────────────┐
                        │                                                 │
                        ▼                                                 ▼
        ┌───────────────────────────────┐               ┌───────────────────────────────────────┐
        │ PATH A: Text-based PDF        │               │ PATH B: Image-based / Scanned PDF     │
        │ character_count > 0          │               │ character_count === 0 (or < 500)      │
        │                               │               │                                       │
        │ PdfTextExtraction.status =   │               │ PdfTextExtraction.status = failed     │
        │   complete                    │               │   (empty) OR complete (short text)   │
        └───────────────────────────────┘               └───────────────────────────────────────┘
                        │                                                 │
                        │                                                 │ dispatchVisionFallback()
                        │                                                 ▼
                        │                               ┌───────────────────────────────────────┐
                        │                               │ RunBrandPdfVisionExtractionJob        │
                        │                               │ - Download PDF to temp                │
                        │                               │ - Render pages (pdftoppm → images)    │
                        │                               │ - Create BrandPdfVisionExtraction     │
                        │                               │ - Dispatch AnalyzeBrandPdfPageJob     │
                        │                               │   per page                            │
                        │                               └───────────────────────────────────────┘
                        │                                                 │
                        │                                                 ▼
                        │                               ┌───────────────────────────────────────┐
                        │                               │ AnalyzeBrandPdfPageJob (per page)      │
                        │                               │ - VisionExtractionService.extract()   │
                        │                               │ - AI analyzes rendered page image     │
                        │                               │ - Increment pages_processed           │
                        │                               │ - When all done → MergeBrandPdf...    │
                        │                               └───────────────────────────────────────┘
                        │                                                 │
                        │                                                 ▼
                        │                               ┌───────────────────────────────────────┐
                        │                               │ MergeBrandPdfExtractionJob            │
                        │                               │ - Merge all page extractions         │
                        │                               │ - BrandPdfVisionExtraction COMPLETED  │
                        │                               │ - Dispatch RunBrandIngestionJob      │
                        │                               └───────────────────────────────────────┘
                        │                                                 │
                        ▼                                                 ▼
        ┌───────────────────────────────────────────────────────────────────────────────────────┐
        │ RunBrandIngestionJob                                                                  │
        │ - Prefers BrandPdfVisionExtraction.extraction_json if vision COMPLETED                │
        │ - Else uses PdfTextExtraction.extracted_text → BrandGuidelinesProcessor               │
        │ - Merges PDF + website + materials → snapshot + suggestions                          │
        └───────────────────────────────────────────────────────────────────────────────────────┘
```

### The Switch: When to Use Vision

| Condition | Path | What We Wait For |
|-----------|------|------------------|
| `character_count === 0` | Vision fallback | **Page render** (pdftoppm) → **AI per-page analysis** → **Merge** → Ingestion |
| `character_count < 500` (guidelines_pdf) | Vision fallback (in addition to text) | Same as above; text path may also run ingestion |
| `character_count >= 500` | Text only | Text extraction → Ingestion |

**Critical:** For Path B (vision), the user **must not proceed** until:
1. Page render job finishes (all pages rendered to images)
2. All `AnalyzeBrandPdfPageJob` jobs finish (AI has analyzed each page)
3. `MergeBrandPdfExtractionJob` completes
4. `RunBrandIngestionJob` completes (snapshot exists)

The processing gate (`overallStatus`) should block until all of the above are done when the vision path is active.

---

## Part 3: Processing UI States

The `ProcessingView` shows status per stage. Each stage has its own progress indicator so users know when processing is actually done.

| Stage | Status Labels | Progress Detail |
|-------|---------------|-----------------|
| **Upload** | Complete | Only shown when there are 2+ PDF-related stages (e.g. PDF processing or AI Summary running); omitted when just one item |
| **PDF (text path)** | Extracting text… → Complete | Indeterminate bar during extraction |
| **PDF (vision path)** | Rendering pages… → Analyzing page X of Y → Complete | Indeterminate during render; progress bar `pages_processed / pages_total` during AI analysis |
| **AI Summary** | Generating insights and suggestions… → Complete | Indeterminate bar; shown when `ingestionProcessing` |
| **Website** | Pending → Analyzing… → Complete | — |
| **Brand Materials** | Pending → Processing X / Y → Complete | `assets_processed / assets_total` |
| **Social** | Pending → Analyzing… → Complete | — |

**Blocking message:** "You cannot proceed to the next step until processing is complete."

---

## Part 4: Completion Gate (overallStatus)

The user can proceed to Archetype only when `overallStatus === 'completed'`.

**Current logic (to align / fix):**

- `pdfComplete` = true when:
  - No PDF attached, OR
  - Vision batch exists and status is `COMPLETED` or `FAILED`, OR
  - No vision batch and `PdfTextExtraction` status is not `pending`/`processing`
- `websiteComplete` = true when no website/social URLs, OR latest snapshot exists and no crawler running
- `materialsComplete` = true when no materials, OR latest ingestion record is not `processing`
- `allSourcesComplete` = pdfComplete && websiteComplete && materialsComplete
- `overallStatus` = `allSourcesComplete ? 'completed' : 'processing'`

**Gap:** `overallStatus` does not currently wait for `ingestionProcessing`. When vision completes and `MergeBrandPdfExtractionJob` dispatches `RunBrandIngestionJob`, the snapshot may not exist yet. The gate should also require `!ingestionProcessing` and ideally a completed snapshot before allowing progression.

**Vision path edge case:** When `PdfTextExtraction.status === 'failed'` (empty) and no vision batch exists yet (job just dispatched), we must **not** consider PDF complete. We must wait for the vision batch to exist and reach `COMPLETED` or `FAILED`.

---

## Part 5: Research Insights — How They Get Applied

### Data Flow After Ingestion

```
RunBrandIngestionJob
    │
    ├─ processPdf() → BrandGuidelinesProcessor (text) OR BrandPdfVisionExtraction.extraction_json (vision)
    ├─ process website (crawler) → WebsiteExtractionProcessor
    ├─ process materials → BrandMaterialProcessor
    │
    ├─ BrandExtractionSchema::merge(...) → unified extraction
    ├─ ExtractionSuggestionService::generateSuggestions() → recommended_archetypes, etc.
    ├─ BrandCoherenceScoringService::score() → coherence
    ├─ BrandAlignmentEngine::analyze() → alignment findings
    │
    └─ BrandResearchSnapshot created
        - snapshot (logo_url, primary_colors, detected_fonts, hero_headlines, brand_bio)
        - suggestions (recommended_archetypes, mission_suggestion, etc.)
        - coherence (section scores, risks)
        - alignment (findings with severity)
```

### How Insights Are Consumed

| Consumer | Data | Action |
|----------|------|--------|
| **Archetype step** | `latestSuggestions.recommended_archetypes` | Inline suggestion pills; user can apply |
| **Research Insights panel** | `latestCoherence`, `latestAlignment` | Brand Coherence scores, Top risks, Alignment findings |
| **Apply suggestion** | `finding.suggestion.path`, `finding.suggestion.value` | Frontend patches draft payload at path |

### Polling

The frontend polls `GET /brands/{brand}/brand-dna/builder/research-insights` every 2s when:
- On processing step, OR
- PDF extraction polling, OR
- Ingestion polling, OR
- Research/crawler polling

Response includes: `pdf`, `website`, `social`, `materials` (with status, progress), `overall_status`, `ingestionProcessing`, `latestSuggestions`, `latestCoherence`, `latestAlignment`.

---

## Part 6: Key Files

| Component | Path |
|-----------|------|
| Upload / attach | `Builder.jsx` — `PdfGuidelinesUploadCard`, `BuilderUploadDropzone` |
| Processing UI | `Builder.jsx` — `ProcessingView` |
| Text extraction | `ExtractPdfTextJob`, `PdfTextExtractionService` |
| Vision fallback | `RunBrandPdfVisionExtractionJob`, `AnalyzeBrandPdfPageJob`, `MergeBrandPdfExtractionJob` |
| Page render | `PdfPageRenderer`, `PdfPageRenderingService` |
| Visual pipeline | `PdfPageClassificationService`, `PdfPageVisualExtractionService`, `BrandExtractionFusionService` |
| Ingestion | `RunBrandIngestionJob`, `BrandGuidelinesProcessor`, `VisionExtractionService` |
| Suggestions | `ExtractionSuggestionService` |
| Coherence / alignment | `BrandCoherenceScoringService`, `BrandAlignmentEngine` |
| Fusion observability | `ExtractionEvidenceMapBuilder`, `PageThumbnailGenerator` |
| Research API | `BrandDNABuilderController::researchInsights` |
| Completion gate | `BrandDNABuilderController::show` (overallStatus), `researchInsights` |

---

## Part 7: Fusion Trust & Observability

We now have **two interpreters**: section-aware text pipeline and visual page pipeline. Quality problems will come from:

- Duplicate findings
- Conflicting findings
- One pipeline overpowering the other
- Unclear final evidence for why a field won

**Focus: fusion trust and observability, not more extraction.**

### Field-Level Winner Reporting

For every final extracted field, the developer panel exposes:

- `evidence_map` — per-field winner reporting:

```json
{
  "identity.positioning": {
    "final_value": "…",
    "winning_source": "pdf_visual",
    "winning_page": 12,
    "winning_reason": "higher confidence + page type match",
    "candidates": [
      { "source": "pdf_text", "section": "BRAND POSITIONING", "confidence": 0.71 },
      { "source": "pdf_visual", "page": 12, "page_type": "positioning", "confidence": 0.86 }
    ]
  }
}
```

- `winning_source`: `pdf_visual` | `pdf_text` | `website` | `materials`
- `winning_page` / `winning_section`: where the value came from
- `winning_reason`: why it won (e.g. "higher weight", "page type match")
- `candidates`: all sources that contributed, including losers

### Page Thumbnails in Developer Mode

For each classified page in the developer panel:

- `page_classifications` — each entry includes:
  - `page` — page number
  - `thumbnail_base64` — small data URL for human QA
  - `page_type` — classified type
  - `confidence` — classification confidence
  - `title` — page title if detected

**Use case:** "Did the classifier understand the page I'm looking at?" — obvious with thumbnails.

### Page-Type QA Metrics

`page_type_metrics` — counts per page type:

```
cover: 1
table_of_contents: 1
brand_story: 4
typography: 2
color_palette: 1
example_gallery: 18
unknown: 3
```

Immediately shows when the classifier is drifting (e.g. too many `unknown`, `example_gallery` dominance).

### Key Files for Fusion & Observability

| Component | Path |
|-----------|------|
| Evidence map builder | `ExtractionEvidenceMapBuilder` |
| Page thumbnails | `PageThumbnailGenerator`, `MergeBrandPdfExtractionJob` |
| Developer payload | `BrandDNABuilderController::researchInsights` — `developer_data.evidence_map`, `page_classifications`, `page_type_metrics` |

---

## Part 8: Summary — Intended Invariants

1. **OCR first:** Always try pdftotext. If empty (or &lt; 500 chars for guidelines), switch to vision.
2. **Vision path:** Wait for page render → per-page AI analysis → merge → ingestion before allowing progression.
3. **Text path:** Wait for text extraction → ingestion before allowing progression.
4. **Ingestion:** User cannot proceed until `RunBrandIngestionJob` has completed and snapshot exists.
5. **UI:** Show only applicable sources; show "Extracting text…" or "Reading page X of Y" as appropriate.
6. **Fusion observability:** Field-level winner reporting, page thumbnails, and page-type metrics in developer mode for debugging.

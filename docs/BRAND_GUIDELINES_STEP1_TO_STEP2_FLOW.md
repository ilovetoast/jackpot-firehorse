# Brand Guidelines Builder: Step 1 (Background) → Step 2 (Archetype) Flow

When a Brand Guidelines PDF is uploaded, this document outlines the full process from upload through extraction, ingestion, suggestions, and progression to the Archetype step.

---

## Overview

```
Step 1 (Background)                    Processing                         Step 2 (Archetype)
┌─────────────────────┐               ┌─────────────────────┐            ┌─────────────────────┐
│ 1. Upload PDF       │──────────────▶│ 2. Text Extraction  │───────────▶│ 7. Archetype        │
│ 2. Attach to draft  │               │    (pdftotext)      │            │    selection with   │
│ 3. Trigger extract  │               │ 3. Trigger         │            │    suggestions      │
│                     │               │    ingestion        │            │                     │
│                     │               │ 4. RunBrandIngestion │            │                     │
│                     │               │    Job              │            │                     │
│                     │               │ 5. BrandGuidelines  │            │                     │
│                     │               │    Processor       │            │                     │
│                     │               │ 6. Suggestions      │            │                     │
│                     │               │    generated       │            │                     │
└─────────────────────┘               └─────────────────────┘            └─────────────────────┘
```

---

## Phase 1: Upload & Attach (Step 1 — Background)

| Step | Action | Endpoint / Component | Result |
|------|--------|----------------------|--------|
| 1.1 | User drops or selects PDF | `BuilderUploadDropzone` | File uploaded to S3 via `POST /app/uploads/initiate` → `PUT` to presigned URL |
| 1.2 | Finalize upload | `POST /app/assets/upload/finalize` | Asset created with `builder_staged=true`, `builder_context=guidelines_pdf` |
| 1.3 | Attach to draft | `POST /brands/{brand}/brand-dna/builder/attach-asset` | Asset linked to draft via `brand_model_version_assets` with `builder_context=guidelines_pdf` |

---

## Phase 2: Text Extraction (OCR / pdftotext)

| Step | Action | Endpoint / Job | Result |
|------|--------|----------------|--------|
| 2.1 | Trigger extraction | `POST /app/assets/{asset}/pdf-text-extraction` | Creates `PdfTextExtraction` record (status=pend), dispatches `ExtractPdfTextJob` |
| 2.2 | Extract text | `ExtractPdfTextJob` | Uses `PdfTextExtractionService` → **pdftotext** (poppler-utils) to extract text from PDF |
| 2.3 | Save result | `PdfTextExtraction` model | `extracted_text`, `character_count`, `status=complete`, `extraction_source=pdftotext` |
| 2.4 | Frontend polls | `GET /app/assets/{asset}/pdf-text-extraction` | Polls every 2s until `status=complete` or `failed` |

**Note:** Text extraction uses **pdftotext** (selectable text from PDFs). Scanned/image-based PDFs have no selectable text and return empty; the UI shows "This PDF appears to be scanned or has no selectable text" with a manual paste option.

---

## Phase 3: Trigger Ingestion

| Step | Action | Endpoint | Result |
|------|--------|----------|--------|
| 3.1 | When extraction completes | `onTriggerIngestion({ pdf_asset_id })` | Called from `PdfGuidelinesUploadCard` when `ext.status === 'complete'` |
| 3.2 | Start ingestion | `POST /brands/{brand}/brand-dna/builder/trigger-ingestion` | Dispatches `RunBrandIngestionJob` with `pdf_asset_id` |

---

## Phase 4: RunBrandIngestionJob (Backend)

| Step | Action | Service / Logic | Result |
|------|--------|-----------------|--------|
| 4.1 | Create record | `BrandIngestionRecord` | `status=processing` |
| 4.2 | Process PDF | `BrandGuidelinesProcessor::process($extractedText)` | Parses text for: mission, vision, positioning, industry, tagline, archetype, traits, tone, colors, fonts |
| 4.3 | Merge sources | `BrandExtractionSchema::merge(...)` | Combines PDF + website (if any) + materials (if any) |
| 4.4 | Generate suggestions | `ExtractionSuggestionService::generateSuggestions()` | Produces `recommended_archetypes`, coherence, alignment |
| 4.5 | Create snapshot | `BrandResearchSnapshot` | `snapshot`, `suggestions`, `coherence`, `alignment` |
| 4.6 | Complete record | `BrandIngestionRecord` | `status=completed` |

**BrandGuidelinesProcessor** extracts (deterministic heuristics, no AI):

- **Identity:** mission, vision, positioning, industry, tagline, target_audience, beliefs, values
- **Personality:** primary_archetype, traits, tone_keywords
- **Visual:** primary_colors (hex), fonts, logo_detected

---

## Phase 5: Processing UI (Blocking)

| Step | Action | UI Behavior |
|------|--------|-------------|
| 5.1 | While processing | `ProcessingView` shown on Background step |
| 5.2 | Steps displayed | Extracting text → Processing PDF and materials → (ingestion records) |
| 5.3 | Next blocked | `hasProcessing` = true → Next disabled |
| 5.4 | Redirect if URL | If user navigates to step 2 via URL, redirect back to Background |

---

## Phase 6: Optional Prefill (Manual)

| Step | Action | Endpoint / Service | Result |
|------|--------|--------------------|--------|
| 6.1 | User clicks "Apply from PDF" | `POST /brands/{brand}/brand-dna/builder/prefill-from-guidelines-pdf` | `GuidelinesPdfToBrandDnaMapper::map()` |
| 6.2 | Map to draft | `BrandDnaDraftService::applyPrefillPatch()` | Fills empty fields in draft with extracted values |

**Prefill** is optional and user-triggered. It uses `GuidelinesPdfToBrandDnaMapper` to map extracted text into draft fields (identity, personality, typography, branding colors, etc.).

---

## Phase 7: Step 2 (Archetype)

| Step | Action | Data Source |
|------|--------|-------------|
| 7.1 | User proceeds to Archetype | Next enabled when `hasProcessing` = false |
| 7.2 | Suggestions shown | `latestSuggestions.recommended_archetypes` from `BrandResearchSnapshot` |
| 7.3 | Inline suggestions | `InlineSuggestionBlock` for recommended archetypes |
| 7.4 | Brand Guidelines PDF summary | Compact card: "Imported from Background step" |

---

## Summary: End-to-End Flow

1. **Upload** → PDF uploaded, asset created, attached to draft with `guidelines_pdf` context.
2. **Extract** → `ExtractPdfTextJob` runs pdftotext; text stored in `PdfTextExtraction`.
3. **Frontend polls** → `pdf-text-extraction.show` until complete.
4. **Trigger ingestion** → `RunBrandIngestionJob` with `pdf_asset_id`.
5. **Process PDF** → `BrandGuidelinesProcessor` parses text into structured schema.
6. **Suggestions** → `ExtractionSuggestionService` generates `recommended_archetypes`, etc.
7. **Snapshot** → `BrandResearchSnapshot` created with snapshot + suggestions.
8. **Blocking** → User stays on Background until processing completes.
9. **Proceed** → Step 2 (Archetype) shows suggestions from snapshot.
10. **Optional** → User can manually "Apply from PDF" to prefill draft fields via `GuidelinesPdfToBrandDnaMapper`.

---

## Key Files

| Component | Path |
|-----------|------|
| Upload UI | `resources/js/Pages/BrandGuidelines/Builder.jsx` – `PdfGuidelinesUploadCard`, `BuilderUploadDropzone` |
| Processing UI | `resources/js/Pages/BrandGuidelines/Builder.jsx` – `ProcessingView` |
| Text extraction trigger | `AssetPdfTextExtractionController` |
| Extract job | `ExtractPdfTextJob` | `PdfTextExtractionService` |
| Ingestion trigger | `BrandDNABuilderController::triggerIngestion` |
| Ingestion job | `RunBrandIngestionJob` |
| PDF processor | `BrandGuidelinesProcessor` |
| Suggestions | `ExtractionSuggestionService` |
| Prefill | `BrandGuidelinesPdfPrefillController` | `GuidelinesPdfToBrandDnaMapper` |

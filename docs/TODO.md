Must Have



- sentry full loop
-delete, restore
- performance stats
- glacvier for version and archive
- clean up poling to much
- generate all pages of pdf for ocr
- combine down and selects with floating dialog like actions
- change details view to be full screen dark, shopify esk with deatil on the side
- see trashed files
- Enterprise TODO (Single Sentence Summary)

Here’s your clean one-liner:

Enterprise buckets will use a per-tenant CloudFront distribution and key pair, isolating each tenant at the CDN layer while preserving the same AssetDeliveryService abstraction.

That’s the future extension. Clean. No schema changes needed today.

grid swipe load
login screen, splash screen
CloudFront CDN implementation
S3 image versioning support
AI-generated recommended categories from tags
Reliability Engine stabilization and hardening
SVG raster quality finalization
Performance response-time instrumentation (page + image load)
Enterprise Admin Asset Operations panel (mass actions, advanced search)
Permission engine full delegation refactor (single canonical resolver)
Visual metadata integrity SLO enforcement
Incident auto-recovery rate monitoring
MTTR production monitoring validation
Unified asset health scoring standardization


Good To Have
Vector-native preview mode (true SVG rendering where safe)
Advanced Reliability analytics dashboard expansion
Recovery attempt analytics reporting
Admin dashboard tile reorganization (enterprise layout pass)
Thumbnail generation profiling + timing logs
Asset lifecycle audit explorer
Incident trend heatmap (7/30/90 days)
Background image lazy-loading experiment
Deferred asset URL loading experiment
Extended embedding support for PDF/video
SVG XML dimension parsing fallback (passthrough cases)


## Phase 11 — Bulk Metadata System Refactor

We will:

Create proper route:

- `POST /assets/bulk-update`

Create:

- `BulkMetadataController@update`

Rebuild BulkMetadataService with:

- Version-aware logic
- Category change handling
- ApprovalResolver properly injected
- Permission checks server-side
- Per-item result reporting
- Transaction wrapping

Return structured response:

```json
{
  "success": 12,
  "failed": [
    { "id": "...", "error": "..." }
  ]
}
```

Frontend:

- Remove GET fallback
- Call correct POST endpoint
- Use proper error reporting
- Clear selection on success

🚫 For Now: Bulk metadata submit is disabled until Phase 11 backend is wired.

------------------------------------------------------------------------------------------------


ai bg for ocr
FUTURE PHASE — AI Structuring Layer

Create pdf_text_ai_structures processing job (ProcessPdfTextWithAiJob)

Structured JSON extraction for brand guideline documents

AI confidence scoring model

Manual “Analyze with AI” button in AssetDrawer

Store AI model name + processing status

Reprocess AI without re-running OCR

Add document-type classification (guideline / deck / spec / unknown)

Add structured summary generation (bullet summary)

Add AI re-run audit trail

📌 FUTURE PHASE — Tag & Metadata Integration

Inject AI-derived metadata into asset_metadata_candidates

Inject AI-derived tag candidates with confidence weighting

Map detected primary/secondary colors to brand metadata candidates

Inject “guideline” category when document_type = brand_guideline

Inject governance flags when confidentiality detected

Add candidate source type = pdf_ai

Add AI confidence threshold before candidate injection

Prevent AI from auto-writing committed metadata

Add event: asset.pdf_ai_structured

Integrate AI-derived data into compliance scoring engine

📌 FUTURE PHASE — Brand DNA Integration

Auto-detect brand guideline documents by filename/category

Use AI structured JSON to pre-fill Brand DNA builder fields

Diff structured JSON across versions to detect guideline changes

Trigger asset.guideline_changed event

Notify brand admins on guideline change

Cache parsed brand rules for upload-time validation

Use guideline colors to influence AI color validation

Use guideline fonts to validate typography compliance
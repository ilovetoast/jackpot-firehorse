Must Have

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

---

- versioning fixed, test all options, restore, new verions file type diff, delete, restore
- performance stats
- glacvier for version and archive
- clean up pooling to much
- generate all pages of pdf for ocr
- combine down and selects with floating dialog like actions
- change details view to be full screen dark, shopify esk with deatil on the side
- see trashed files

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
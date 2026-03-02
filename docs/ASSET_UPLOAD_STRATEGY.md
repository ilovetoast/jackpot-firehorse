# Builder Asset Upload Strategy

## Problem
When users upload assets via the Builder (Brand Materials, Visual References), those assets need a clear home. They should not "float in space."

## Recommended Approach: Builder-Staged + Optional Add-to-Library

### 1. Upload Flow
- **On upload**: Create asset with `builder_staged: true`, `builder_context: <context>` (brand_material, visual_reference, etc.)
- **Pivot**: Attach to draft via `brand_model_version_assets` with same `builder_context`
- **Category**: Leave `category_id` null (uncategorized) OR assign to a "Builder Uploads" / "Uncategorized" category
- **Publication**: Leave unpublished (`published_at` null) until user explicitly publishes

### 2. Where They Appear
- **Builder modal/selector**: Always show builder-staged assets for the brand (filter by brand_id + builder_staged OR by pivot)
- **Main asset grid**: Option A: Exclude builder-staged from default view. Option B: Show in a "From Builder" or "Uncategorized" section
- **Assets library**: Add a filter/tab "Builder uploads" that shows assets with `builder_staged = true` and/or `category_id` null

### 3. Lifecycle Options

| Option | Pros | Cons |
|--------|------|------|
| **A) Unpublished until manual publish** | Explicit control, no accidental exposure | Extra step; user must go to Assets to publish |
| **B) Auto-publish on Guidelines publish** | Seamless | May expose unfinished assets |
| **C) "Add to library" on Publish** | User chooses; clear intent | Extra UI in publish flow |

**Recommendation: Option A** — Keep unpublished. When user goes to Assets, they see builder uploads in "Uncategorized" or a "Builder" filter. They can then categorize, publish, or discard. No automatic exposure.

### 4. Implementation Notes
- `builder_staged` already exists on upload session
- Ensure finalize flow sets `metadata.builder_staged = true` on asset
- Asset grid: add `?lifecycle=unpublished` or `?source=builder` filter
- Consider a "Builder uploads" category (slug: `builder-uploads`) — auto-assign on upload, user can move later

# Features

This document combines **value positioning**, **feature-area overview**, and **product-level behavior** (URLs, gates, production caveats) so humans and tools have one place to start. Deeper architecture lives in [TECHNICAL_OVERVIEW.md](TECHNICAL_OVERVIEW.md); host packages and environments are in [environments/SERVER_REQUIREMENTS.md](environments/SERVER_REQUIREMENTS.md).

---

## Platform positioning

This DAM is designed for **brands that care about consistency, scale, and control**.

It goes beyond "file storage" by combining:

- Brand governance
- AI assistance with human oversight
- Metadata intelligence
- Enterprise safety and auditability

The result is a system that doesn't just store assets — it **protects brand integrity** and **accelerates teams without chaos**.

---

## Core feature categories

### 1. Brand-centric asset organization

**Built for brands, not folders**

- Organize assets by brand, category, and use case
- Shared system structure with brand-specific customization
- Clear separation between:
  - raw assets
  - final marketing assets
  - collections and campaigns

**Value:** Teams spend less time searching and less time guessing which asset is "correct."

---

### 2. Smart metadata (without the headaches)

**Metadata that actually works**

- Structured, consistent metadata across all assets
- Required fields ensure assets are usable from day one
- Bulk editing for large libraries
- Saved views for common workflows

**Value:** Assets become searchable, filterable, and reusable — not dead weight.

---

### 3. AI that assists, not replaces

**AI with guardrails**

- AI suggests metadata, never forces it
- Confidence scoring on AI suggestions
- Human approval required before AI changes go live
- AI respects brand rules and permissions

**Value:** You get speed without sacrificing trust or control.

---

### 4. Brand guidelines (internal & external)

**One source of brand truth**

- Centralized brand guidelines:
  - logo usage
  - colors
  - typography
  - photography style
- Accessible to internal teams and external partners
- Guidelines tied directly to assets

**Planned enhancements:**

- Brand guideline enforcement during upload
- AI-assisted brand compliance checks
- External-facing brand portals

**Value:** Brand consistency across teams, agencies, and markets.

---

### 5. On-brand scoring & brand ranking (planned)

**Know when assets match the brand — and when they don't**

- AI-powered "on-brand" scoring
- Rank assets by brand alignment
- Flag off-brand or risky assets
- Compare assets across campaigns or time periods

**Value:** Creative teams get feedback faster, brand managers get confidence, and off-brand usage is reduced.

Engineering detail for execution-based intelligence: [BRAND_INTELLIGENCE.md](BRAND_INTELLIGENCE.md).

---

### 6. Approval & governance workflows

**Control without bottlenecks**

- Metadata approval workflows (live)
- Role-based review and approval
- Full audit trails for compliance

**Planned: Pro-staff / upload approval module**

- Brand manager review before assets go live
- Approvals, rejections, and comments
- Ideal for ambassadors, field reps, and external contributors

**Value:** You maintain brand standards even with large, distributed teams.

---

### 7. Collections & campaign views

**Assets assembled with intent**

- Create collections without duplicating files
- Campaign-based asset groupings
- Share collections internally or externally
- Download ready-to-use bundles

**Value:** Campaign execution becomes faster and more organized.

---

### 8. Advanced search & discovery

**Find the right asset fast**

- Metadata-driven filters
- Saved search views
- Category-aware filtering

**Planned: smart discovery**

- "Find similar assets"
- Semantic search using natural language
- Visual similarity search

**Value:** Less hunting, more creating.

---

### 9. Analytics & insights

**Understand your asset library**

- Metadata completeness and quality
- AI effectiveness and trust metrics
- Rights and usage risk visibility
- Asset freshness and relevance insights

**Value:** Make better decisions about what to create, reuse, or retire.

---

### 10. Rights & risk management

**Avoid costly mistakes**

- Track usage rights and expiration dates
- Identify expired or expiring assets
- Plan for future alerts and workflows

**Value:** Reduce legal risk and prevent accidental misuse.

---

## Public collections (product behavior)

Public collections allow shareable, read-only views of a collection via a URL. No auth required; access is limited to assets that are in the collection and visible under existing visibility rules.

### URLs

- **Format:** `/b/{brand_slug}/collections/{collection_slug}`
- **Uniqueness:** Brand-namespaced so the same collection slug in different brands does not collide.
- **Download:** `/b/{brand_slug}/collections/{collection_slug}/assets/{asset}/download` (redirects to signed S3 URL).
- **Thumbnail:** `/b/{brand_slug}/collections/{collection_slug}/assets/{asset}/thumbnail` (validates, then redirects to signed S3 thumbnail URL).

### Behavior

- **Thumbnails:** Each asset on the public page gets a `thumbnail_url` pointing at the public thumbnail route. The route validates that the asset is in the public collection, then redirects to a short-lived signed S3 URL. Only allowed assets get a valid thumbnail.
- **Download:** Opens in a new window (`_blank`). Each hit to the public download route is logged (collection_id, asset_id, brand_slug) for tracking.
- **Copy link:** The “Copy public link” control uses the Clipboard API with a fallback (textarea + `execCommand('copy')`) so it works in more contexts (e.g. HTTP, iframes).

### Production notes

- **Thumbnails and S3:** Public collection thumbnails assume the **default S3 disk** and that thumbnail paths in asset metadata are object keys in that bucket. If you use **multiple buckets per tenant** (e.g. asset `storageBucket` varies), the public thumbnail redirect in `PublicCollectionController::thumbnail()` would need to be updated to build the signed URL for the asset’s bucket (e.g. using the asset’s `storageBucket` and a bucket-aware S3 client or disk), instead of `Storage::disk('s3')->temporaryUrl($path, ...)`.
- **Download tracking:** Today, public downloads are recorded only in logs (`Public collection download` with collection_id, asset_id, brand_slug). To support metrics or dashboards, add a dedicated event, metric table, or analytics integration when the public download route is hit.
- **Feature gate:** Public collections are gated by tenant plan (`public_collections_enabled` in `config/plans.php`). When disabled, public routes return 404 and the in-app “Public” toggle is hidden.

---

## Key value propositions

### Built for scale

Designed for multi-brand, multi-team organizations — not just small creative teams.

### Trustworthy AI

AI helps, humans decide. No black boxes.

### Brand protection

From metadata to approvals to analytics, brand integrity is the core focus.

### Enterprise-ready

Audit trails, permissions, approvals, and future SLA support.

### Future-proof

Designed to grow into:

- AI generation
- Pro-staff workflows
- Brand compliance automation
- Semantic discovery

---

## Ideal customers

- Consumer brands with multiple product lines
- Marketing teams managing large asset libraries
- Agencies supporting multiple brands
- Organizations with ambassadors or field reps
- Brands that care deeply about consistency

---

## Current status

**What's live**

- Core DAM functionality
- Structured metadata system
- AI-assisted metadata suggestions
- Approval workflows
- Computed metadata
- Analytics and insights

**What's planned**

- Pro-staff upload approval
- Brand compliance scoring
- Semantic and visual discovery
- Rights automation
- AI-generated content modules

---

## Bottom line

This platform is not just a place to store assets —  
it's a **brand intelligence system** that grows smarter over time.

It helps teams move faster **without losing control**.

---

## Related documentation

| Doc | Purpose |
|-----|---------|
| [README.md](README.md) | **Documentation index** — consolidated operations guides |
| [PHASE_INDEX.md](PHASE_INDEX.md) | Map of legacy “Phase X” docs → merged locations |
| [TECHNICAL_OVERVIEW.md](TECHNICAL_OVERVIEW.md) | Architecture, stack, principles |
| [BRAND_INTELLIGENCE.md](BRAND_INTELLIGENCE.md) | Execution-based brand intelligence (EBI) |
| [environments/SERVER_REQUIREMENTS.md](environments/SERVER_REQUIREMENTS.md) | OS packages by role (web, worker) |
| [DEV_TOOLING.md](DEV_TOOLING.md) | Local-only dev commands and utilities |

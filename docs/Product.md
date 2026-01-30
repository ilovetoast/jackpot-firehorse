# Product Notes

Product and deployment notes for DAM features. Use this for production considerations, limitations, and follow-up work.

---

## Public Collections

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

### Production Notes

- **Thumbnails and S3:** Public collection thumbnails assume the **default S3 disk** and that thumbnail paths in asset metadata are object keys in that bucket. If you use **multiple buckets per tenant** (e.g. asset `storageBucket` varies), the public thumbnail redirect in `PublicCollectionController::thumbnail()` would need to be updated to build the signed URL for the asset’s bucket (e.g. using the asset’s `storageBucket` and a bucket-aware S3 client or disk), instead of `Storage::disk('s3')->temporaryUrl($path, ...)`.
- **Download tracking:** Today, public downloads are recorded only in logs (`Public collection download` with collection_id, asset_id, brand_slug). To support metrics or dashboards, add a dedicated event, metric table, or analytics integration when the public download route is hit.
- **Feature gate:** Public collections are gated by tenant plan (`public_collections_enabled` in `config/plans.php`). When disabled, public routes return 404 and the in-app “Public” toggle is hidden.

---

## Other product docs

- [Features & Value Proposition](FEATURES_AND_VALUE_PROPOSITION.md) — marketing overview
- [Technical Overview](TECHNICAL_OVERVIEW.md) — architecture and stack

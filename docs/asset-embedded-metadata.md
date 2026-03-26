# Embedded file metadata (DAM)

## Purpose

Uploaded files can carry native metadata (EXIF, IPTC, PDF info, container tags). This feature **preserves** that data for audit, exposes a **governed subset** for search, and **never** turns arbitrary keys into schema columns or automatic filters.

## Three layers

1. **Canonical / system metadata** — Existing asset columns and approved `asset_metadata` (orientation, dimensions, etc.). Authoritative for governance and filters.
2. **Raw embedded payload (Layer B)** — Table `asset_metadata_payloads`, `source = embedded`, JSON grouped by namespace (`exif`, `iptc`, `pdf`, …). Full-fidelity (minus binary), UTF-8 normalized. The `other` bucket may contain extractor warnings (e.g. `download`, `ffprobe_unavailable`, per-extractor errors).
3. **Derived index (Layer C)** — Table `asset_metadata_index`. Not the source of truth. Built only from `config/asset_embedded_metadata.php` allowlist via `EmbeddedMetadataRegistry`.

## `captured_at` (nullable)

Optional asset column filled only when the registry maps embedded EXIF datetime with `fill_if_empty` (or other explicit modes). **No sorts, grid columns, or exports assume this is non-null** — treat as optional everywhere.

## Provenance (canonical mapping)

When a registry `map_to_system` applies (e.g. `captured_at` ← `exif.DateTimeOriginal`), a small audit record is written to **`metadata.embedded_system_map`**, e.g.:

```json
{
  "captured_at": {
    "source_fq": "exif.DateTimeOriginal",
    "system_map_mode": "fill_if_empty",
    "mapped_at": "2026-03-25T12:00:00+00:00"
  }
}
```

## Governance

- Unknown keys: stored in Layer B only; **not** indexed; **not** exposed as filters.
- Allowlist: `config/asset_embedded_metadata.php` keys (`exif.Make`, …). `EmbeddedMetadataRegistry` applies sensitivity rules (`sensitive_fq_keys`, `sensitivity: stored_only`).
- System mapping: only explicit `map_to_system` entries with `system_map_mode` (`fill_if_empty`, `overwrite_if_nullish`, `never`, `trusted_overwrite`).

## Search index normalization

- **`search_text`** is always produced via `EmbeddedMetadataSearchTextNormalizer`: lowercasing, optional accent stripping (intl `Normalizer`), and collapsing punctuation/slashes to spaces so values like `24-70mm`, `f/2.8`, and `©` tokenize consistently for `LIKE` search.
- Technical fields (`iso`, `focal_length`, `aperture`, `exposure_time`) use **`EmbeddedMetadataTechnicalNormalizer`** for stable `value_string` / numeric storage before search normalization.

## Adding a new mapping

1. Add extraction in the appropriate `App\Assets\Metadata\Extractors\*` (preserve original keys in the namespaced bucket).
2. Add a **fully qualified** registry key `namespace.Key` in `config/asset_embedded_metadata.php` with `normalized_key`, `type`, and flags.
3. Run processing or call `EmbeddedMetadataExtractionService::extractAndPersist()` — index rebuilds idempotently (delete all index rows for the asset, then insert allowlisted rows only).

## Search

`AssetSearchService` AND-tokens match title, filename, tags, collections, and **`asset_metadata_index.search_text`** (LIKE/ILIKE). No per-key UI filters yet; `normalized_key` is stored for future scoped queries.

## Privacy / sensitivity

- Sensitive FQ keys (e.g. GPS) are listed under `sensitive_fq_keys` and/or `sensitivity: stored_only`. They may remain in raw JSON when present in the file, but are **not** indexed or shown in the visible summary by default.
- API: `embedded_metadata_raw` requires **`embedded_metadata.view_raw`** or (legacy fallback) **`metadata.edit_post_upload`**. Prefer granting `embedded_metadata.view_raw` for least privilege.

## Pipeline

After `ExtractMetadataJob`, `ExtractEmbeddedMetadataJob` runs. Failures are logged; they do **not** fail the chain.

**Stale index rows:** Every successful run persists Layer B and **rebuilds** Layer C from the current normalized payload. If extractors return less data later (e.g. ffprobe missing), previous video/audio index rows are removed because the rebuild deletes all rows for the asset before re-inserting allowlisted keys. If the file cannot be downloaded, we still persist empty namespaces + `other.download` / warnings and rebuild the index so nothing stale remains.

## PDF extraction

PDF metadata via Imagick is **best-effort**; property names and availability depend on ImageMagick/Ghostscript. Do not assume parity across environments.

## Admin debug

Site admin / engineering: **Admin → Assets → open asset** → tab **“Embedded meta”** shows `embedded_metadata_debug` (namespaces, `other` warnings, index row sample, `embedded_system_map`, canonical `captured_at`).

## Reprocessing / idempotency

Re-run extraction overwrites the `asset_metadata_payloads` row for `source=embedded` and rebuilds `asset_metadata_index` for that asset.

## Version awareness

Current implementation is **asset-level** (matches current pipeline). A future phase may attach payloads to `asset_version_id`; see migration TODO.

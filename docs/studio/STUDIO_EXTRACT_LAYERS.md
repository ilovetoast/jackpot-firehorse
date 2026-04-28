# Studio: Extract layers (segmentation + optional background fill)

This feature turns a raster layer into one or more cutout image layers (masks + previews) and, optionally, a full-frame “filled background” layer beneath them. Behavior is split into **extraction** (find subject masks) and **inpainting** (reconstruct the full plate where foreground was removed).

## Local vs AI (user-selectable)

Users choose an extraction **method** per run (`local` or `ai` on `POST .../extract-layers`). The app **does not** call Fal or other remote segmenters for every run just because `FAL_KEY` is set.

| Method | What runs | Billing (`studio_layer_extraction`) |
|--------|-----------|-------------------------------------|
| **`local`** | `FloodfillStudioLayerExtractionProvider` (GD flood-fill, local) | Charged only if `STUDIO_LAYER_EXTRACTION_BILL_FLOODFILL=true` (default: free / local) |
| **`ai`** | `SamStudioLayerExtractionProvider` with a remote **Fal** client when `STUDIO_LAYER_EXTRACTION_SAM_ENABLED` and the SAM client is available, plus tenant/brand `allow_ai` policy. | Pre-checked; charged only **after a successful** run with Fal masks (metadata `segmentation_engine: fal_sam2`), not for failures, validation errors, or empty results. |

- **`STUDIO_LAYER_EXTRACTION_DEFAULT_METHOD`** — `local` (default) or `ai` when the AI path is available.
- **`STUDIO_LAYER_EXTRACTION_ALLOW_AI=false`** — hides/rejects the AI method at runtime.
- `GET .../extract-layers/options` and extract responses return **`available_methods`**, default method, and estimated app credits (not raw vendor $ as the primary unit).

`STUDIO_LAYER_EXTRACTION_PROVIDER` is **legacy/ops**; per-request `method` wins. The service container’s default `StudioLayerExtractionProviderInterface` is floodfill so ad-hoc resolution does not silently bind SAM.

## Providers (extraction)

| Config / engine | What it is |
|-----------------|------------|
| **`floodfill` (per-request `method=local`)** | Local GD flood-fill + heuristics. **No external API** — the local-only workflow. **Does not bill** `studio_layer_extraction` unless you set `STUDIO_LAYER_EXTRACTION_BILL_FLOODFILL=true`. |
| **`sam`** + `STUDIO_LAYER_EXTRACTION_SAM_ENABLED=true` + per-request `method=ai` | SAM-style **contract** (multi-mask, point / refine / box, metadata). With **`FAL_KEY`** and `STUDIO_LAYER_EXTRACTION_SAM_PROVIDER=fal`, masks come from the configured **Fal** SAM2 HTTP endpoint (see `config('services.fal.sam2_endpoint')`). If the AI path is unavailable, the user sees a disabled or missing AI option, or a **422** if they explicitly request `ai`. The floodfill **shim** (when SAM is enabled but the remote call is not used) is still local; billing follows `shouldBillExtractionForSession()` and actual Fal run success. |
| **`sam` + `sam_provider=replicate`**| Reserved: **`ReplicateSamSegmentationClient`** is wired but returns `isAvailable(): false` until a model and HTTP layer are implemented. Use Fal in the meantime. |

## Inpainting (background fill)

Background fill is **not** the same as the extraction provider. It is served by `StudioLayerExtractionInpaintBackgroundInterface`:

- **`heuristic`**: local, API-free gray fill (useful in dev / staging).
- **`clipdrop`**: **`ClipdropInpaintBackgroundProvider`** — sends **original** + **union** foreground mask PNG to Clipdrop Cleanup. Mask **255** = “clean up / fill” in Clipdrop; our merged mask uses white = foreground, aligned with that.

The UI “Create filled background layer” is enabled when:

- `STUDIO_LAYER_INPAINT_ENABLED=true`, and
- the bound inpaint class returns `supportsBackgroundFill(): true` (e.g. heuristic or Clipdrop with a key).

`STUDIO_LAYER_BACKGROUND_FILL_CREDITS_ENABLED=false` **disables** pre-checking and **tracking** of `studio_layer_background_fill` (provider calls still run; use when you do not want AI credits to gate that step).

## Environment variables (commonly set)

| Variable | Purpose |
|----------|---------|
| `STUDIO_LAYER_EXTRACTION_DEFAULT_METHOD` | `local` or `ai` (latter only when allowed + SAM + client available) |
| `STUDIO_LAYER_EXTRACTION_ALLOW_AI` | When false, AI method is not offered / fails closed |
| `STUDIO_LAYER_EXTRACTION_SAM_ESTIMATED_COST_USD` | Optional static USD for admin/estimate display when Fal pricing API is off |
| `STUDIO_LAYER_EXTRACTION_SAM_PRICING_API` | When true, `FalModelPricingService` may best-effort call a Fal pricing endpoint; off by default |
| `FAL_API_BASE` / `FAL_SAM2_PRICING_MODEL_ID` | Base URL and model id for optional pricing lookups |
| `STUDIO_LAYER_EXTRACTION_PROVIDER` | **Legacy** `floodfill` or `sam` (per-request `method` wins for the editor flow) |
| `STUDIO_LAYER_EXTRACTION_SAM_ENABLED` | Must be `true` for the SAM **facade** to be bound |
| `STUDIO_LAYER_EXTRACTION_SAM_PROVIDER` | `fal` or `replicate` (Replicate is TODO until `isAvailable()` is true) |
| `STUDIO_LAYER_EXTRACTION_SAM_MODEL` | Model label stored in session metadata (e.g. `sam2`) |
| `STUDIO_LAYER_EXTRACTION_SAM_MAX_SOURCE_MB` | Reject very large source bytes before calling Fal |
| `STUDIO_LAYER_EXTRACTION_SAM_TIMEOUT` | HTTP timeout (seconds) for Fal |
| `FAL_KEY` / `FAL_SAM2_ENDPOINT` | Fal API key; optional override for the `fal-ai/sam2/image` base URL (details stay in config) |
| `REPLICATE_API_TOKEN` | For future Replicate driver |
| `STUDIO_LAYER_INPAINT_ENABLED` / `STUDIO_LAYER_INPAINT_PROVIDER` | e.g. `none`, `heuristic`, `clipdrop` |
| `STUDIO_LAYER_INPAINT_MAX_SOURCE_MB` / `STUDIO_LAYER_INPAINT_TIMEOUT` | Inpaint safety limits (Clipdrop) |
| `CLIPDROP_API_KEY` / `CLIPDROP_CLEANUP_ENDPOINT` | Clipdrop Cleanup |
| `STUDIO_LAYER_EXTRACTION_BILL_FLOODFILL` | When true, bills `studio_layer_extraction` for local floodfill |
| `STUDIO_LAYER_BACKGROUND_FILL_CREDITS_ENABLED` | Gate/track `studio_layer_background_fill` credits (default on) |
| `STUDIO_LAYER_EXTRACTION_QUEUE` / `STUDIO_LAYER_EXTRACTION_ALWAYS_QUEUE` / `STUDIO_LAYER_EXTRACTION_ASYNC_PIXEL_THRESHOLD` | Extraction is queued on the **AI** queue, not the images queue |

## Credits

- **Segmentation:** `studio_layer_extraction` is pre-checked for billable runs (e.g. `method=ai`, or `method=local` with `BILL_FLOODFILL` on) and charged only **after** a successful, billable run (candidates materialized, session `ready`), per `AiLayerExtractionService::shouldBillExtractionForSession()`. A failed or rejected remote call should not charge; confirm flow does not create cutouts on extraction failure in the same request. **Fal** segmentation is **not** token-based; optional **USD** is for internal cost hints (`FalModelPricingService` + `STUDIO_LAYER_EXTRACTION_SAM_ESTIMATED_COST_USD`), not end-user “token” pricing in the product UI.
- **Filled background:** pre-check and **post-success** track `studio_layer_background_fill` (unless `STUDIO_LAYER_BACKGROUND_FILL_CREDITS_ENABLED` is off). A failed or empty fill does not charge.

## Point-pick refinement

For **point-picked** rows (`pick_*`) with the **floodfill shim**, refine reuses the local engine. For **Fal** (`metadata.segmentation_engine` = `fal_sam2`), refine and box flows call the same remote client with normalized `positive_points` / `negative_points` / `boxes` mapped in `SamPromptMapper` and the Fal client.

## Queues

`StudioExtractLayersJob` is dispatched to the **AI** queue. Use `STUDIO_LAYER_EXTRACTION_ALWAYS_QUEUE=true` when you want all extractions to run in workers. Do not run paid vendors on the image thumbnail / lightweight worker if your infra splits queues that way.

## Safety and logging

- Enforce **max source size** and **allowed MIME** types before HTTP (see `SamLayerExtractionImage` and each driver).
- Optionally downscale a copy for Fal; masks are rescaled back to the original dimensions.
- **Do not** log signed URLs, S3 keys, or raw third-party image URLs. Structured logs are limited to provider, model, duration, `candidate_count`, `prompt_type`, and high-level error type.

## Manual / developer checks

- **Floodfill:** leave `STUDIO_LAYER_EXTRACTION_PROVIDER=floodfill` and confirm the existing UX.
- **SAM (remote):** set `provider=sam`, `STUDIO_LAYER_EXTRACTION_SAM_ENABLED=true`, `FAL_KEY`, run extract + pick + box + refine, then (optional) Clipdrop inpaint and confirm the layer order: **cutouts above** filled background **above** original, original unchanged.

### Extract layers modal (UI)

1. **Default to local with FAL key present:** set `STUDIO_LAYER_EXTRACTION_DEFAULT_METHOD=local`, `STUDIO_LAYER_EXTRACTION_SAM_ENABLED=true`, and `FAL_KEY` set. Open Extract layers: the **Local mask detection — Free** segment is selected; the app does **not** run Fal until the user picks **AI segmentation** and **Auto detect** (or leaves default and uses local only).
2. **AI option visible when SAM is available:** with SAM enabled and a valid client (e.g. Fal key), the **AI segmentation — Uses credits** control appears and is enabled; otherwise it is disabled with a reason.
3. **Request method:** choosing Local then **Auto detect** issues `POST` with `method: local`. Choosing AI then **Auto detect** issues `method: ai`.
4. **Tools:** **Pick point**, **Draw box**, and **Refine selected** are visible in the **Tools** row (with a persistent **Source preview** when a source image URL is available). **Refine selected** requires a point-picked candidate and a highlighted (ring) target card.
5. **Background fill:** with `STUDIO_LAYER_INPAINT_ENABLED=false` or no inpaint API key, the “Create filled background layer” control stays disabled and copy explains why. With a working inpaint provider, the checkbox is enabled and uses `studio_layer_background_fill` on confirm.

## Tests

Unit tests use fakes (e.g. `RecordingFakeSamSegmentationClient`, `Http::fake` for Clipdrop) — the suite does not call real Fal/Clipdrop endpoints in CI by default.

# Production worker software (single checklist)

Use this document when **provisioning or auditing staging/production machines** (or container images) that run **queue workers** for Jackpot: **raster thumbnails**, **PDF** thumbnails and text, **SVG** rasterization, **video** previews and processing, **image OCR**, optional **Python/OpenCV** jobs, and **Studio** headless Chromium workloads on dedicated lanes.

**Web tier:** Often shares the same image as workers. If web does not run workers, it still needs any CLI tool invoked on the request path; matching the worker image avoids drift.

**Deeper behavior** (pipeline stages, formats, retries) is not duplicated here — see [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md), [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md), and [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) for architecture only.

---

## Role matrix

| Role | Typical processes | What this checklist covers |
|------|-------------------|----------------------------|
| **Worker** | `queue:work`, Horizon, `ProcessAssetJob`, thumbnails, transcoding | All **OS packages**, **PHP/ImageMagick policy**, **Node + Playwright** (when Studio video-heavy features are on) below |
| **Web** | PHP-FPM, HTTP | Same stack if workers are colocated; otherwise match versions where shared code paths shell out to CLIs |

---

## PHP runtime (worker)

Install the **same PHP version and extensions** the application expects in production (see `composer.json` / deploy image). Minimum for media pipelines:

| Requirement | Purpose |
|-------------|---------|
| **PHP CLI / FPM** | Runs Laravel and Horizon |
| **imagick** extension | Image and PDF rasterization via Imagick; requires **system ImageMagick** (see [Packages](#packages)) |

If **imagick** is missing or system ImageMagick/Ghostscript is absent, raster and PDF thumbnails fail at runtime.

---

## Packages

Ubuntu/Debian-style names; adjust for RHEL, Alpine, or AMI equivalents.

### Worker (asset pipeline: images, PDF, SVG, video, OCR)

| Package | Purpose |
|---------|---------|
| **imagemagick** | Raster thumbnails, conversions (`convert` / `magick`), many formats |
| **ghostscript** | Delegate ImageMagick needs for **PDF** read/write |
| **poppler-utils** | PDF: `pdftotext`, `pdftoppm`, `pdfinfo` (text extraction and fallbacks) |
| **librsvg2-bin** | **SVG → raster** (`rsvg-convert`). Without it, SVG thumbnails do not generate |
| **librsvg2-dev** | Only if you compile extensions against librsvg on the host |
| **ffmpeg** | Video thumbnails, previews, and FFmpeg-driven processing (`ffprobe` typically ships with the same package) |
| **python3-opencv** | Only if workers run Python code using `import cv2` |
| **tesseract-ocr** | `ImageOcrService` / `ExtractImageOcrJob` — install on workers that run OCR |

### One-shot install (typical Ubuntu worker)

```bash
sudo apt-get update
sudo apt-get install -y \
  imagemagick ghostscript poppler-utils \
  librsvg2-bin librsvg2-dev \
  ffmpeg \
  python3-opencv \
  tesseract-ocr
```

Omit **python3-opencv** if no OpenCV-backed jobs run on that host.

### ImageMagick PDF policy (required for PDF thumbnails)

Default ImageMagick packages on Debian/Ubuntu often ship a **policy** that **denies** PDF read/write (`/etc/ImageMagick-6/policy.xml` or ImageMagick-7 equivalent). For PDF thumbnails you must **allow PDF** operations for ImageMagick (and understand the security tradeoff — PDFs can embed complex content). After editing policy, no PHP restart is required for the next `convert`/`magick` invocation.

Verify with:

```bash
grep -i pdf /etc/ImageMagick-6/policy.xml || grep -i pdf /etc/ImageMagick-7/policy.xml
```

Use `php artisan pdf:verify` from the deployed app tree when the app is configured (see [Verification](#verification)).

### Retrofit (SVG thumbnails missing)

```bash
sudo apt-get update && sudo apt-get install -y librsvg2-bin
which rsvg-convert && rsvg-convert --version
```

---

<a id="nodejs--playwright-video-heavy--studio"></a>

## Node.js / Playwright (video-heavy + studio)

Required only on **queue workers** (or dedicated services) that run **Studio** workloads using **headless Chromium** — not for the PHP-only thumbnail lane.

| Feature | Typical queue | Entry |
|--------|----------------|-------|
| **Studio canvas-runtime video export** | `video-heavy` (or `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE` if set) | `scripts/studio-canvas-export.mjs` via `StudioCompositionCanvasRuntimeVideoExportService` |
| **Studio animation official renderer** | Studio animation queue from config | Playwright script (see [studio-animation-rollout.md](../internal/studio-animation-rollout.md)) |

**Checklist**

1. **Node.js** — Version pinned to what `jackpot/package.json` and CI use; `node` and `npm` on `PATH` for the user that runs Horizon / `queue:work`.
2. **Application JS** — From the **`jackpot/`** directory (same as `artisan`), run **`npm ci`** during image bake or deploy so `node_modules/playwright` matches the lockfile.
3. **Chromium + OS libraries** — After `npm ci`, from **`jackpot/`**:

   **`npx playwright install --with-deps chromium`**

   Bake into the **image**, not only at container start on every replica. Optionally set **`PLAYWRIGHT_BROWSERS_PATH`** to a cached path for reproducible builds.

**CI / image preflight (no download)**

```bash
cd /path/to/jackpot
npm ci
npx playwright install --with-deps --dry-run chromium
```

**Typical worker image layer**

```bash
cd /path/to/jackpot
npm ci
npx playwright install --with-deps chromium
```

Further rollout detail: [CANVAS_RUNTIME_EXPORT.md](../studio/CANVAS_RUNTIME_EXPORT.md), [studio-animation-rollout.md](../internal/studio-animation-rollout.md).

---

## Verification

Run as the **same user** that executes queue workers.

### Binaries on PATH

```bash
which convert magick pdftotext rsvg-convert ffmpeg ffprobe tesseract
ffmpeg -version
tesseract --version
rsvg-convert --version
python3 -c "import cv2; print(cv2.__version__)"  # omit if OpenCV not installed
```

### Artisan checks (from deployed `jackpot/`)

```bash
cd /var/www/jackpot/current   # or your release path
php artisan svg:verify
php artisan pdf:verify
```

### Playwright (Studio hosts)

```bash
cd /path/to/jackpot
npx playwright --version
npx playwright install --with-deps --dry-run chromium
```

Optional: `node scripts/studio-canvas-export.mjs --help` on canvas-runtime workers.

---

## Related documentation (not required for install lists)

- [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md) — format and pipeline behavior
- [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md) — queues and jobs
- [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) — which worker tiers carry video-heavy / Studio load

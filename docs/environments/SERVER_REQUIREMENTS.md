# Server requirements (third-party binaries)

Processing (thumbnails, PDF text, SVG, video frames) depends on **CLI tools** installed on the machine or container that runs **queue workers**. The web tier may run minimal packages if all heavy work goes through workers—many deployments use the **same** image for `app` and `queue` for simplicity.

**Source of truth (development):** `docker/8.5/Dockerfile` — keep this file aligned with what you document below when dependencies change.

---

## Role matrix

| Role | Typical processes | Required packages (summary) |
|------|-------------------|------------------------------|
| **Worker** | `queue:work`, `ProcessAssetJob`, thumbnail generation | ImageMagick, Poppler (`pdftotext`, etc.), `librsvg`, FFmpeg, `python3-opencv` (if Python uses OpenCV) — see [Packages](#packages). **Video-heavy / studio canvas lanes** also need [Node.js / Playwright](#nodejs--playwright-video-heavy--studio) when those features are enabled. |
| **Web** | PHP-FPM, HTTP | Often same image as worker; if split, web still benefits from matching versions for any inline processing |
| **Local (Sail)** | Docker Compose services | Uses Dockerfile above |

Staging and production should use the **same** worker dependency set unless you intentionally diverge (document why in your runbook).

---

## Packages

Ubuntu/Debian-style install examples. Adjust for your distribution.

### Worker (required for asset pipeline)

| Package | Purpose |
|---------|---------|
| **imagemagick** | Raster thumbnails, conversions (`convert` / `magick`) |
| **ghostscript** | Required for ImageMagick to read/write PDF files |
| **poppler-utils** | PDF: `pdftotext`, `pdftoppm`, `pdfinfo` |
| **librsvg2-bin** | **SVG rasterization (`rsvg-convert`).** Without this, `ThumbnailGenerationService::renderSvgViaRsvg()` throws and SVG assets never get thumbnails. |
| **librsvg2-dev** | Only if building extensions or compiling against librsvg on bare metal |
| **ffmpeg** | Video thumbnails and preview generation |
| **python3-opencv** | OpenCV Python bindings (`import cv2`) for computer-vision or image-processing scripts run on workers (Sail installs this in `docker/8.5/Dockerfile`) |
| **tesseract-ocr** | OCR binary used by `ImageOcrService` / `ExtractImageOcrJob` to extract text from image assets. Only needs to be installed on the queue worker — the web tier does not invoke tesseract directly. |

### Full install (fresh staging/production host)

```bash
sudo apt-get update
sudo apt-get install -y \
  imagemagick ghostscript poppler-utils \
  librsvg2-bin librsvg2-dev \
  ffmpeg \
  python3-opencv \
  tesseract-ocr
```

Omit `python3-opencv` on hosts that never run Python code using OpenCV; local Sail matches `docker/8.5/Dockerfile`, which includes it.

### Retrofitting an existing server (SVG-only)

If the host already serves assets but SVG thumbnails have never worked, the missing package is almost always `librsvg2-bin`. Safe to run on a live box:

```bash
sudo apt-get update && sudo apt-get install -y librsvg2-bin
which rsvg-convert && rsvg-convert --version   # expect: /usr/bin/rsvg-convert, v2.50+
```

No service restart required — PHP shells out to the binary per request; Horizon picks it up on the next job. Existing SVG assets stuck in a broken state can be recovered via the in-app "Retry Processing" button, which self-heals bucket/state and re-dispatches the pipeline.

### Web

If the web container does **not** run queue workers, it may omit heavy tools **only** if no request path invokes them. In practice, matching the worker image avoids drift.

---

## Node.js / Playwright (video-heavy + studio)

Use this on **queue workers** that run **Studio** workloads driven by headless Chromium (not the PHP-only thumbnail lane):

| Feature | Queue / service | App entry |
|--------|-------------------|-----------|
| **Studio canvas-runtime video export** | `video-heavy` (or `QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE` if set) | `scripts/studio-canvas-export.mjs` via `StudioCompositionCanvasRuntimeVideoExportService` |
| **Studio animation official renderer** | Configured studio animation queue | Locked-frame Playwright script (see `docs/internal/studio-animation-rollout.md`) |

**Production software checklist**

1. **Node.js** — LTS or version pinned to what `jackpot/package.json` / CI expects; on the worker image, `node` and `npm` must be on `PATH` for the same user that runs Horizon/`queue:work`.
2. **Application JS dependencies** — from the **`jackpot/`** app root (same path as `artisan`), run `npm ci` in the image bake or deploy hook so `node_modules/playwright` matches lockfile.
3. **Chromium + OS libraries (server-side)** — from **`jackpot/`** after `npm ci`, use Playwright’s combined install so **browser binaries and system packages** match the pinned `playwright` version:

   **`npx playwright install --with-deps chromium`**

   Prefer baking this into the **worker image** (not at container start on every replica). Optionally set **`PLAYWRIGHT_BROWSERS_PATH`** to a shared or cached directory so builds are reproducible and downloads are controlled.

   *Alternative:* `docker/8.5/Dockerfile` uses a standalone **`npx playwright install-deps`** layer for Sail; on bare production workers the **`--with-deps`** form above is the usual single command.

**Verify installs without downloading (required for production docs / CI gates)**

Run from the **`jackpot/`** directory after `npm ci`:

```bash
# Print planned browser + system-dependency steps, without apt/dnf or browser download
npx playwright install --with-deps --dry-run chromium
```

Use this in **CI or image-build smoke steps** to confirm the Playwright CLI resolves, the lockfile version matches expectations, and the dependency plan is acceptable on hardened hosts.

**Full install (typical worker image layer)**

```bash
cd /path/to/jackpot
npm ci
npx playwright install --with-deps chromium
```

**Related docs**

- [Studio canvas-runtime export](../studio/CANVAS_RUNTIME_EXPORT.md) — rollout, queues, env flags.
- [Studio animation Playwright rollout](../internal/studio-animation-rollout.md) — official renderer env vars.

---

## Verification

After deploy, confirm binaries are on `PATH` for the user that runs workers:

```bash
which convert magick pdftotext rsvg-convert ffmpeg tesseract
ffmpeg -version
tesseract --version
rsvg-convert --version
python3 -c "import cv2; print(cv2.__version__)"
```

### SVG smoke test (end-to-end)

Prefer the built-in artisan verification — it runs the exact pipeline the worker uses (rsvg-convert → PNG → Imagick → WebP) and reports the first step that fails:

```bash
cd /var/www/jackpot/current
php artisan svg:verify
```

Expect a green final line: `✅ All checks passed. SVG thumbnail generation is ready on this host.` If any step fails, the output names the missing package or broken coder.

Manual one-liner equivalent (no artisan):

```bash
rsvg-convert -w 512 public/jp-wordmark-inverted.svg -o /tmp/svg-smoke.png && file /tmp/svg-smoke.png
# Expect: "PNG image data, 512 x NNN, 8-bit/color RGBA, non-interlaced"
```

A sibling command exists for PDFs: `php artisan pdf:verify`.

### Playwright (studio / canvas workers)

After `npm ci` in **`jackpot/`** (same directory as `artisan`):

```bash
npx playwright --version
npx playwright install --with-deps --dry-run chromium
```

Expect the dry-run output to list the Chromium revision and dependency steps Playwright would run. For a host that already baked browsers, optionally smoke **`node scripts/studio-canvas-export.mjs --help`** (canvas) or the official animation script path from env (see internal rollout doc).

---

## Related documentation

- [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md) — thumbnails, PDFs, formats
- [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md) — worker processes and pipeline
- [DEV_TOOLING.md](../DEV_TOOLING.md) — local development utilities
- [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) — worker tiers; **video-heavy** hosts should satisfy this file’s FFmpeg **and** (when Studio flags are on) Node/Playwright sections

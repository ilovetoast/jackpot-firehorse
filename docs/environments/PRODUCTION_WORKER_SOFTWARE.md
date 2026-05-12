# Production worker software (single checklist)

Use this document when **provisioning or auditing staging/production machines** (or container images) that run **queue workers** for Jackpot: **raster thumbnails**, **PDF** thumbnails and text, **Office** document previews (LibreOffice → PDF), **SVG** rasterization, **video** previews and processing, **image OCR**, optional **Python/OpenCV** jobs, and **Studio** workloads: **FFmpeg-native composition export** (video + text rasterization) and/or **headless Chromium** on dedicated lanes where enabled.

**Web tier:** Often shares the same image as workers. If web does not run workers, it still needs any CLI tool invoked on the request path; matching the worker image avoids drift.

**Deeper behavior** (pipeline stages, formats, retries) is not duplicated here — see [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md), [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md), and [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) for architecture only.

---

## Role matrix

| Role | Typical processes | What this checklist covers |
|------|-------------------|----------------------------|
| **Worker** | `queue:work`, Horizon, `ProcessAssetJob`, thumbnails, transcoding, Studio export | All **OS packages**, **PHP/ImageMagick policy**, **FFmpeg + font/image libs** for native Studio export, **Node + Playwright** (when Studio canvas/browser capture is on) below |
| **Web** | PHP-FPM, HTTP | Same stack if workers are colocated; otherwise match versions where shared code paths shell out to CLIs |

---

## PHP runtime (worker)

Install the **same PHP version and extensions** the application expects in production (see `composer.json` / deploy image). Minimum for media pipelines:

| Requirement | Purpose |
|-------------|---------|
| **PHP CLI / FPM** | Runs Laravel and Horizon |
| **imagick** extension | Image and PDF rasterization via Imagick; requires **system ImageMagick** (see [Packages](#packages)) |
| **php{x}-xml** | **DOM**, **SimpleXML**, and other XML APIs Laravel and dependencies use on workers — install the package that matches your PHP major (e.g. **`php8.5-xml`** on Ubuntu when PHP 8.5 runs Horizon) |

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
| **libreoffice-nogui** | **Office** → PDF for thumbnails/previews (`soffice`); see [Office documents](#office-worker-libreoffice) |
| **fonts-dejavu**, **fonts-liberation**, **fonts-noto** | **Basic font pack** — improves LibreOffice/PDF layout and glyph coverage on minimal server images (often missing desktop fonts) |
| **librsvg2-bin** | **SVG → raster** (`rsvg-convert`). Without it, SVG thumbnails do not generate |
| **librsvg2-dev** | Only if you compile extensions against librsvg on the host |
| **ffmpeg** | Video thumbnails, previews, FFmpeg-driven processing, and **Studio `ffmpeg_native` composition export**; **`ffprobe`** is included with **`ffmpeg`** on Ubuntu/Debian (verify with `command -v ffprobe`) |
| **fontconfig** | Font configuration for stacks that resolve fonts via fontconfig (useful alongside Imagick/GD text) |
| **libfreetype6** | **FreeType** — required for text rendering in **GD** (and common Imagick text paths) |
| **libjpeg-turbo8** / **libpng16-16** | JPEG/PNG codecs used by **Imagick** / **GD** when rasterizing Studio text overlays to PNG (exact package names can differ by Ubuntu LTS; install equivalents on Debian/RHEL) |
| **python3-opencv** | Only if workers run Python code using `import cv2` |
| **tesseract-ocr** | `ImageOcrService` / `ExtractImageOcrJob` — install on workers that run OCR |


### One-shot install (typical Ubuntu worker)

```bash
sudo apt-get update
sudo apt-get install -y \
  imagemagick ghostscript poppler-utils \
  libreoffice-nogui \
  fonts-dejavu fonts-liberation fonts-noto \
  librsvg2-bin librsvg2-dev \
  ffmpeg \
  fontconfig libfreetype6 libjpeg-turbo8 libpng16-16 \
  python3-opencv \
  tesseract-ocr
```

Omit **python3-opencv** if no OpenCV-backed jobs run on that host.

<a id="studio-ffmpeg-native-export-workers"></a>

### Studio FFmpeg-native export (staging + production workers)

Workers that run **`STUDIO_RENDERING_DRIVER=ffmpeg_native`** (or otherwise execute `FfmpegNativeCompositionRenderer` / `TextOverlayRasterizer`) need **FFmpeg**, libraries for **PNG/JPEG text overlays**, and **either Imagick or GD with FreeType** for PHP-side text rasterization. Tenant fonts are staged as local **TTF/OTF** files; see [FFMPEG_NATIVE_EXPORT.md](../studio/FFMPEG_NATIVE_EXPORT.md).

**Typical Ubuntu (staging and production worker hosts)**

```bash
sudo apt update
sudo apt install -y \
  ffmpeg \
  fontconfig \
  libfreetype6 \
  libjpeg-turbo8 \
  libpng16-16
```

**`ffprobe`:** On Ubuntu and Debian it is installed **with** the **`ffmpeg`** package. A separate `ffprobe` apt package is often **not** available — after installing `ffmpeg`, confirm:

```bash
command -v ffprobe && ffprobe -version
```

**PHP extensions (match your PHP major, e.g. `php8.5-*` on Ubuntu)**

XML (required for typical Laravel / dependency XML usage on workers):

```bash
sudo apt install -y php8.5-xml
```

Use **`php{version}-xml`** for whatever version runs **`php artisan queue:work`** / Horizon (e.g. **`php8.3-xml`**, **`php8.4-xml`**).

**PHP raster backend (pick one; same version suffix as above)**

Preferred when the app uses Imagick for text overlays:

```bash
sudo apt install -y imagemagick php-imagick
```

Or GD + FreeType (ensure your `php-gd` build links FreeType — the `libfreetype6` package above covers the system library):

```bash
sudo apt install -y php-gd
```

Use **`php8.x-imagick`** / **`php8.x-gd`** if multiple PHP versions are installed so the extension loads for the CLI/FPM version that runs Horizon.

### ImageMagick PDF policy (required for PDF thumbnails)

Default ImageMagick packages on Debian/Ubuntu often ship a **policy** that **denies** PDF read/write (`/etc/ImageMagick-6/policy.xml` or ImageMagick-7 equivalent). For PDF thumbnails you must **allow PDF** operations for ImageMagick (and understand the security tradeoff — PDFs can embed complex content). After editing policy, no PHP restart is required for the next `convert`/`magick` invocation.

Verify with:

```bash
grep -i pdf /etc/ImageMagick-6/policy.xml || grep -i pdf /etc/ImageMagick-7/policy.xml
```

Use `php artisan pdf:verify` from the deployed app tree when the app is configured (see [Verification](#verification)).

<a id="office-worker-libreoffice"></a>

### Office documents (Word / Excel / PowerPoint previews)

Thumbnails and grid previews for **Office** uploads use **LibreOffice** in headless mode to convert the file to **PDF**, then the same **ImageMagick + Ghostscript + spatie/pdf-to-image** stack as native PDFs (page 1 only).

| Package | Purpose |
|---------|---------|
| **libreoffice-nogui** | Provides `soffice` for `--headless --convert-to pdf` without a desktop stack |

**Basic font pack (recommended):** Minimal worker/container images often ship without fonts referenced by Office and PDF pipelines. Install a small set of common families so substitutions and missing-glyph boxes are less likely:

```bash
sudo apt install -y fonts-dejavu fonts-liberation fonts-noto
```

**Security note:** Untrusted Office files can contain macros. Conversion runs on workers with the same trust model as PDF rasterization; keep workers isolated and sized appropriately.

**Verify after install:**

```bash
command -v soffice && soffice --version
```

If `soffice` is missing, the app **skips** Office thumbnails (placeholder UX) and records a **system incident** on the admin reliability dashboard so operators can install the package.

**Headless crashes (exit 134 / “Fatal exception: Signal 6”):** On minimal servers (no GPU, no X11), LibreOffice may abort while loading Impress/Draw backends. The app sets **`SAL_USE_VPLUGIN=svp`** (software VCL) and **`SAL_DISABLE_OPENCL=1`** for conversions by default (see `config/assets.php` → `assets.thumbnail.office.headless_extra_env`). Override with **`OFFICE_PREVIEW_SAL_USE_VPLUGIN`** / **`OFFICE_PREVIEW_SAL_DISABLE_OPENCL`** in `.env` if needed. If conversion still aborts, install **`xvfb`** and run conversions under **`xvfb-run -a`** (document in your worker image — not wired in PHP by default).

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
2. **Application JS** — From the **`jackpot/`** directory (same as `artisan`), run **`npm ci`** during image bake or deploy so `node_modules/playwright` matches the lockfile. The **`playwright`** package lives in **`dependencies`** (not `devDependencies`) so staging/production installs that use **`npm ci --omit=dev`** or **`NODE_ENV=production`** still ship the driver Horizon invokes.
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

**Docker / split DNS:** if **`APP_URL`** is only resolvable outside worker containers, set **`STUDIO_VIDEO_CANVAS_EXPORT_SIGNED_URL_ROOT`** (see `.env.example` and [CANVAS_RUNTIME_EXPORT.md](../studio/CANVAS_RUNTIME_EXPORT.md)) so the signed render URL host matches what Playwright can open (`net::ERR_CONNECTION_REFUSED` on **process exit code 3**).

When that root is set, the server rewrites **`APP_URL`** (and the opposite **http/https** variant, **`ASSET_URL`**, and **`STUDIO_VIDEO_CANVAS_EXPORT_PAYLOAD_EXTRA_ORIGINS`**) inside the export JSON so fonts and media do not still point at a browser-only host. If the console shows **`ERR_CONNECTION_REFUSED`** for assets while the main page loaded, add **`STUDIO_VIDEO_CANVAS_EXPORT_PAYLOAD_EXTRA_ORIGINS`** for any other absolute origins in persisted compositions (e.g. a dev Vite host), redeploy config, and restart Horizon.

**Exit code 1 vs 3 (canvas capture):** **3** = Chromium could not open the signed URL (network / DNS / wrong host). **1** = the Node process died **before** the script’s structured exits—usually **no `playwright` in `node_modules`** for that release, wrong Node binary, or an import-time crash; check `error_json.debug.stderr_tail` on the failed job row.

**Exit code 4 vs 5:** **4** = page loaded but the export bridge never reached **ready** (often asset or font load failures). **5** = the screenshot loop failed after readiness (missing scene root, disk, or total capture timeout).

### Zero-downtime / Forge releases (why `ERR_MODULE_NOT_FOUND` persists)

If deploy only runs **`composer install`** and **never `npm ci`**, each new release directory (e.g. `/var/www/jackpot/releases/…`) has **no `node_modules`**, so Node cannot resolve `playwright` — **no amount of PHP config fixes that**.

1. On the server, **`cd` to the active release** (same path as `php artisan` / `base_path()`).
2. Run **`npm ci --no-audit --no-fund`** (after `composer install` / symlink step).
3. Install browsers: **`PLAYWRIGHT_BROWSERS_PATH=0 npx playwright install chromium`** when your image sets that env, else **`npx playwright install --with-deps chromium`**.
4. Add those lines to your **Forge deploy script** (or CI) every release. Repo helper: **`bash scripts/forge-studio-npm-ci.sh`** from the app root.

**Worker mirror deploy:** `scripts/worker-mirror-deploy.sh` resolves the Laravel app as either `releases/<id>/jackpot` (monorepo) or `releases/<id>` (single-app), runs **`npm ci`** and Playwright there, and symlinks **`current` → that app directory** so Horizon’s `base_path()` matches `node_modules` and `scripts/studio-canvas-export.mjs`.

The app also **preflight-checks** for `node_modules/playwright/package.json` before spawning capture; if it is missing you get failure code **`canvas_runtime_playwright_module_missing`** with this explanation instead of a raw Node stack trace.

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

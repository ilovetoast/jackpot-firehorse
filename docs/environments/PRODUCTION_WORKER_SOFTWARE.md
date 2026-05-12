# Production worker software (single checklist)

Use this document when **provisioning or auditing staging/production machines** (or container images) that run **queue workers** for Jackpot: **raster thumbnails**, **PDF** thumbnails and text, **Office** document previews (LibreOffice → PDF), **SVG** rasterization, **video** previews and processing, **image OCR**, optional **Python/OpenCV** jobs, and **Studio** workloads: **FFmpeg-native composition export** (video + text rasterization) and/or **headless Chromium** on dedicated lanes where enabled.

**Web tier:** Often shares the same image as workers. If web does not run workers, it still needs any CLI tool invoked on the request path; matching the worker image avoids drift.

**Treat this file as the install contract** — see [Reproducible worker images](#reproducible-worker-images-aws-and-elsewhere) for how to mirror staging on AWS without drift.

**Deeper behavior** (pipeline stages, formats, retries) is not duplicated here — see [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md), [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md), and [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) for architecture only.

---

## Reproducible worker images (AWS and elsewhere)

Use **one** of these patterns so production matches staging and you can re-spin hosts next week without guesswork:

1. **Container image (ECS, EKS, Fargate, or EC2 + Docker)** — Add a `RUN apt-get install …` layer (or equivalent) that duplicates the [one-shot `apt` block](#packages) plus any optional rows you use (OpenCV, Studio Playwright, etc.). Tag images (`jackpot-worker:2026-05-12`) and deploy by tag, not `:latest` only.
2. **Golden AMI (EC2 Auto Scaling)** — Use [Packer](https://www.packer.io/) (or AWS Image Builder) with a shell provisioner that runs the same package list. Launch templates reference the AMI ID; bump the AMI when this document changes.
3. **User-data on first boot** — Acceptable for experiments; for production prefer baked images so boot is fast and failures are visible at **build** time, not when traffic hits.
4. **Configuration management** — Ansible / Salt / SSM documents can shell out to the same `apt-get install -y` list; keep the list in **one place** (ideally a small `scripts/worker-os-packages.sh` in your infra repo that mirrors this doc — optional follow-up).

**Tracking changes:** When you add a dependency in application code (new shell binary, new PHP extension), update **this markdown file in the same PR** and bump your image/AMI pipeline. Optionally record the **Git commit** of the doc version in your internal runbook (“worker image built from jackpot@`abc1234`”).

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
| **libheif1** | **HEIC/HEIF read** — ImageMagick must expose **HEIC** in `magick identify -list format` (the app checks `Imagick::queryFormats()`). Without libheif (or an IM build without the HEIF coder), PHP **imagick** loads but HEIC jobs fail or soft-skip |
| **ghostscript** | Delegate ImageMagick needs for **PDF** read/write |
| **poppler-utils** | PDF: `pdftotext`, `pdftoppm`, `pdfinfo` (text extraction and fallbacks) |
| **libreoffice-nogui** | **Office** → PDF for thumbnails/previews (`soffice`); see [Office documents](#office-worker-libreoffice) |
| **xvfb** | **Required on Linux workers that run Office previews** — provides **`xvfb-run`** so headless Impress/PPT conversion does not SIGABRT on hosts without a real display; the app uses it when **`OFFICE_PREVIEW_USE_XVFB`** is `auto` (default) or `true` |
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
  imagemagick libheif1 ghostscript poppler-utils \
  libreoffice-nogui xvfb \
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

**Spreadsheet vs presentation:** **Excel (`.xlsx` / Calc)** conversion often works on a bare headless worker because it uses the **Calc** engine. **PowerPoint (`.pptx` / Impress)** uses the **Impress** stack and is where **exit 134 / Signal 6** and **`SvpSalInstance` / `createViewController`** crashes most often appear without **`xvfb-run`**. When troubleshooting, do not assume “Office works” from a single **Excel** test — validate at least one **`.pptx`** (or the failing asset) after changing worker packages or **`OFFICE_PREVIEW_*`** env vars.

| Package | Purpose |
|---------|---------|
| **libreoffice-nogui** | Provides `soffice` for `--headless --convert-to pdf` without a desktop stack |
| **xvfb** | **Required with LibreOffice** on server images — supplies **`xvfb-run`** so the app can wrap **`soffice`** with a virtual X display (`OFFICE_PREVIEW_USE_XVFB`, default `auto`) |

**Basic font pack (recommended):** Minimal worker/container images often ship without fonts referenced by Office and PDF pipelines. Install a small set of common families so substitutions and missing-glyph boxes are less likely:

```bash
sudo apt install -y fonts-dejavu fonts-liberation fonts-noto
```

**Security note:** Untrusted Office files can contain macros. Conversion runs on workers with the same trust model as PDF rasterization; keep workers isolated and sized appropriately.

**Verify after install:**

```bash
command -v soffice && soffice --version
command -v xvfb-run && xvfb-run --help >/dev/null && echo "xvfb-run ok"
```

If `soffice` is missing, the app **skips** Office thumbnails (placeholder UX) and records a **system incident** on the admin reliability dashboard so operators can install the package.

**Headless crashes (exit 134 / “Fatal exception: Signal 6”):** On minimal servers (no GPU, no X11), LibreOffice may abort while loading Impress/Draw backends without a virtual display. Install **`xvfb`** alongside **`libreoffice-nogui`** (see [Packages](#packages)) so **`xvfb-run`** is on **`PATH`**. If **`php artisan assets:debug-office-preview`** shows **`xvfb-run: no`** but **`command -v xvfb-run`** works in an SSH shell, Horizon/php-fpm may be using a minimal **`PATH`** — set **`OFFICE_PREVIEW_XVFB_RUN_BINARY=/usr/bin/xvfb-run`** in `.env` and **`php artisan config:clear`**. The app sets **`SAL_USE_VPLUGIN=svp`**, **`SAL_DISABLE_OPENCL=1`**, and **`SAL_DISABLE_OPENGL=1`** by default (`config/assets.php` → `assets.thumbnail.office.headless_extra_env`); override with **`OFFICE_PREVIEW_SAL_*`** if needed. With **`OFFICE_PREVIEW_USE_XVFB=auto`** (default), the worker wraps **`soffice`** in **`xvfb-run -a`** when **`xvfb-run`** is found; use **`true`** to require it (fails fast if missing) or **`false`** to never wrap.

**Still failing on `.pptx` with xvfb (Signal 6, stack in `libsdlo` / `SvpSalInstance`):** The worker environment is then usually fine; the remaining problem is often **LibreOffice’s Impress build** (Ubuntu 22.04’s **7.3.x** is a frequent offender). Prefer a **newer supported LibreOffice** (distribution updates, **`jammy-backports`**, or [TDF](https://www.libreoffice.org/download/download/) packages on a test host first). The app defaults **`OFFICE_PREVIEW_SOFFICE_EXTRA_ARGS`** to **`--invisible --nolockcheck`** to reduce view-controller crashes; override in `.env` if you need to experiment or set to empty to disable.

<a id="upgrade-libreoffice-jammy-impress"></a>

#### Upgrading LibreOffice when `soffice --version` is still 7.3.x and PPTX aborts

If **`php artisan assets:debug-office-preview`** already shows **`xvfb-run: yes`**, **`soffice extra args`**, and the **Impress crash hint**, further PHP or env tuning will not fix a broken **Impress** binary. Move a **staging worker** to a **newer LibreOffice**, verify **`soffice --version`**, then bake the same steps into production images.

1. **Distro updates first** (may bump the jammy security pocket without adding PPAs):

   ```bash
   sudo apt-get update
   sudo apt-get full-upgrade
   soffice --version
   ```

2. **Newer release line** — use **one** path; test with the same failing **`.pptx`** after each:

   - **LibreOffice PPA (Fresh)** — widely used to get current stable builds on LTS; third-party, so validate on staging: [LibreOffice PPA on Launchpad](https://launchpad.net/~libreoffice/+archive/ubuntu/ppa). Typical flow:

     ```bash
     sudo add-apt-repository ppa:libreoffice/ppa
     sudo apt-get update
     sudo apt-get install -y libreoffice-nogui
     soffice --version
     ```

   - **Document Foundation `.deb` bundle** — download the current **Linux x86-64** `.tar.gz` from [LibreOffice download](https://www.libreoffice.org/download/download/), unpack, install **`DEBS/*.deb`** (and any dependencies the README lists). Prefer a **throwaway VM** first; mixing TDF debs with distro packages can require `apt -f install` to resolve conflicts.

3. **Restart queue workers** (Horizon / `supervisor`) so all processes pick up the new **`soffice`**, then rerun **`assets:debug-office-preview`**. Success means **`LibreOffice version`** is **clearly newer than 7.3.7** and **`PDF exists: yes`**. Optionally set **`OFFICE_LIBREOFFICE_BINARY=`** in `.env` if **`soffice`** is not under **`/usr/bin/soffice`**.

**Field confirmation (2026):** On Ubuntu 22.04 jammy, **stock `libreoffice-nogui` 7.3.x** plus **`xvfb`**, **`SAL_USE_VPLUGIN=svp`**, and **`--invisible --nolockcheck`** still aborted headless **`.pptx`** conversion (**exit 134 / Signal 6**, stack in **`libsdlo` / `SvpSalInstance`**). Upgrading the same host to **LibreOffice 26.x** (via the [LibreOffice PPA](https://launchpad.net/~libreoffice/+archive/ubuntu/ppa) in this case) fixed the identical file: **`soffice --version`** showed **26.2.x**, **`PDF exists: yes`**, and **`impress_pdf_Export`** in the conversion log.

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
which convert magick pdftotext rsvg-convert ffmpeg ffprobe tesseract soffice xvfb-run
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

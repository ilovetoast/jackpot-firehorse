# Studio font rendering (editor + FFmpeg-native export)

## Goals

- **Stable tokens** in saved compositions (`bundled:…`, `google:…`, `tenant:{assetId}`) instead of relying on CSS `font-family` strings for native export.
- **No remote font URLs** in FFmpeg, Imagick, or GD: every rasterizer receives an **absolute local `.ttf` / `.otf` path**.
- **Deterministic workers**: bundled fonts ship under `resources/fonts/`; Google fonts are downloaded once into `storage/app/{STUDIO_RENDERING_FONT_CACHE_DIR}/google/`; tenant fonts are staged under the same parent as today.

## Configuration

- `config/studio_rendering.php` → `fonts` (default key, bundled catalog, curated Google list, legacy family map).
- `config/studio_fonts_bundled.php` — repo paths for bundled families.
- `STUDIO_RENDERING_DEFAULT_FONT_PATH` — optional override; when empty/unreadable, boot + resolver fall back to `fonts.default_key` (bundled Inter) then DejaVu.

## PHP components

| Class | Role |
|--------|------|
| `StudioRenderingFontResolver` | Resolves `font_key`, tenant assets, legacy CSS, then default chain. |
| `StudioRenderingFontFileCache` | Copies tenant `Asset` bytes from storage to a local cache path. |
| `StudioGoogleFontFileCache` | Downloads allow-listed HTTPS font binaries into `…/google/`. |
| `StudioRenderingFontPaths` | Bundled path lookup + effective default path. |
| `StudioLegacyFontFamilyMapper` | Maps legacy first family token + weight to bundled slug. |
| `StudioEditorFontRegistryService` | Builds grouped JSON for `GET /app/api/editor/studio-fonts`. |

## Editor API

- `GET /app/api/editor/studio-fonts` — grouped fonts + `default_font_key` (same middleware stack as other editor APIs).

## Diagnostics

```bash
php artisan studio:fonts:check    # bundled files + default + cache writability
php artisan studio:fonts:warm     # pre-download curated Google fonts
php artisan studio:rendering:doctor # ffmpeg/ffprobe, PHP gd/imagick, font paths
```

## Server packages (typical Debian/Ubuntu)

- **ffmpeg**, **ffprobe** — video graph and probes.
- **php-gd** *or* **php-imagick** — text rasterization (code prefers Imagick when loaded).
- **freetype** — pulled in with GD/Imagick; FFmpeg bundles its own FreeType build.
- **fontconfig** — optional on workers; native export does **not** use `fc-match` for resolution (repo + cache paths only).

## Licenses (bundled binaries)

Bundled files under `resources/fonts/` are sourced from the **Inter** release (rsms/inter), **Roboto** (googlefonts/roboto unhinted zip), and **Google Fonts OFL** families on GitHub. Retain upstream `OFL.txt` / license files when updating font binaries.

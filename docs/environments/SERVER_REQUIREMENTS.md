# Server requirements (third-party binaries)

Processing (thumbnails, PDF text, SVG, video frames) depends on **CLI tools** installed on the machine or container that runs **queue workers**. The web tier may run minimal packages if all heavy work goes through workers—many deployments use the **same** image for `app` and `queue` for simplicity.

**Source of truth (development):** `docker/8.5/Dockerfile` — keep this file aligned with what you document below when dependencies change.

---

## Role matrix

| Role | Typical processes | Required packages (summary) |
|------|-------------------|------------------------------|
| **Worker** | `queue:work`, `ProcessAssetJob`, thumbnail generation | ImageMagick, Poppler (`pdftotext`, etc.), `librsvg`, FFmpeg — see [Packages](#packages) |
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
| **poppler-utils** | PDF: `pdftotext`, `pdftoppm`, `pdfinfo` |
| **librsvg2-bin** | SVG rasterization (`rsvg-convert`) |
| **librsvg2-dev** | Only if building extensions or compiling against librsvg on bare metal |
| **ffmpeg** | Video thumbnails and preview generation |

Example:

```bash
sudo apt-get update
sudo apt-get install -y imagemagick poppler-utils librsvg2-bin librsvg2-dev ffmpeg
```

### Web

If the web container does **not** run queue workers, it may omit heavy tools **only** if no request path invokes them. In practice, matching the worker image avoids drift.

---

## Verification

After deploy, confirm binaries are on `PATH` for the user that runs workers:

```bash
which convert magick pdftotext rsvg-convert ffmpeg
ffmpeg -version
```

---

## Related documentation

- [MEDIA_PIPELINE.md](../MEDIA_PIPELINE.md) — thumbnails, PDFs, formats
- [UPLOAD_AND_QUEUE.md](../UPLOAD_AND_QUEUE.md) — worker processes and pipeline
- [DEV_TOOLING.md](../DEV_TOOLING.md) — local development utilities

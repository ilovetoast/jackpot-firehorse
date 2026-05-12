# Studio fonts in Laravel Sail (Docker)

Native Studio composition export needs a **readable TTF/OTF on disk** inside the PHP container (`STUDIO_RENDERING_DEFAULT_FONT_PATH`).

## `docker/8.5/Dockerfile`

The Sail image installs **`fonts-dejavu`**, **`fonts-liberation`**, and **`fonts-noto`** (DejaVu Sans remains at `/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`), plus **`fontconfig`**, **FreeType**, and JPEG/PNG runtime libs used when rasterizing text to PNG. **`fc-cache -f`** refreshes the font cache after install.

Rebuild after Dockerfile changes:

```bash
docker compose build --no-cache
```

On **Ubuntu 24.04 (noble)** the PNG runtime package is **`libpng16-16t64`** (not `libpng16-16`).

## `compose.yaml`

`laravel.test`, `queue`, and `queue_video_heavy` set **`STUDIO_RENDERING_DEFAULT_FONT_PATH`** to **`/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf`** by default. Your project `.env` can override with the same variable; omit it to use the compose default.

## Local `.env` (optional)

If you want the path explicit in `.env` (e.g. for `php artisan` parity or documentation), add:

```dotenv
STUDIO_RENDERING_DEFAULT_FONT_PATH=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
```

That path is **inside the container**, not on your macOS/Windows host.

See also [FFMPEG_NATIVE_EXPORT.md](../studio/FFMPEG_NATIVE_EXPORT.md) and [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md).

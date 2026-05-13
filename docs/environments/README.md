# Environments

Documentation for **where** the app runs and **what** must be installed on hosts. The full doc index is [README.md](../README.md).

| Document | Purpose |
|----------|---------|
| [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md) | **Single checklist** — OS packages, ImageMagick PDF policy, PHP imagick, FFmpeg, OCR, optional OpenCV, Node + Playwright for Studio workers (staging/production) |
| [BLENDER_DAM_3D_INSTALL.md](BLENDER_DAM_3D_INSTALL.md) | **Blender 4.5.3 LTS** (official tarball → `/usr/local/bin/blender`, `DAM_3D_BLENDER_BINARY`) — **workers only**; local Sail/Linux; **not** `apt` Blender |
| [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) | Full target architecture: ECS on EC2, worker topology, Horizon mapping, video tiers, RPO/RTO, cost bands, implementation phases — sync with internal `.docx` |
| [SERVER_REQUIREMENTS.md](SERVER_REQUIREMENTS.md) | Pointer to [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md) (legacy filename / links) |
| [../DEV_TOOLING.md](../DEV_TOOLING.md) | Local-only artisan helpers and dev utilities |
| [SAIL_STUDIO_FONTS.md](SAIL_STUDIO_FONTS.md) | Sail/Docker: system font for native Studio text export (`STUDIO_RENDERING_DEFAULT_FONT_PATH`) |

Local development with Laravel Sail uses the application image in `docker/8.5/Dockerfile` (see [Laravel Sail](https://laravel.com/docs/sail) and repo `compose.yaml`). Studio FFmpeg-native text needs a TTF inside the image — see [SAIL_STUDIO_FONTS.md](SAIL_STUDIO_FONTS.md). **DAM 3D real Blender renders** (optional locally) use **Blender 4.5.3 LTS** at **`/usr/local/bin/blender`** — see [BLENDER_DAM_3D_INSTALL.md](BLENDER_DAM_3D_INSTALL.md) (Sail bake or bind-mount; **not** `apt` Blender). Production/staging worker install lists are **not** Sail-specific — use [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md).

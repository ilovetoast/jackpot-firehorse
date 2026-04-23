# Environments

Documentation for **where** the app runs and **what** must be installed on hosts. The full doc index is [README.md](../README.md).

| Document | Purpose |
|----------|---------|
| [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md) | **Single checklist** — OS packages, ImageMagick PDF policy, PHP imagick, FFmpeg, OCR, optional OpenCV, Node + Playwright for Studio workers (staging/production) |
| [PRODUCTION_ARCHITECTURE_AWS.md](PRODUCTION_ARCHITECTURE_AWS.md) | Full target architecture: ECS on EC2, worker topology, Horizon mapping, video tiers, RPO/RTO, cost bands, implementation phases — sync with internal `.docx` |
| [SERVER_REQUIREMENTS.md](SERVER_REQUIREMENTS.md) | Pointer to [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md) (legacy filename / links) |
| [../DEV_TOOLING.md](../DEV_TOOLING.md) | Local-only artisan helpers and dev utilities |

Local development with Laravel Sail uses the application image in `docker/8.5/Dockerfile` (see [Laravel Sail](https://laravel.com/docs/sail) and repo `compose.yaml`). Production/staging worker install lists are **not** Sail-specific — use [PRODUCTION_WORKER_SOFTWARE.md](PRODUCTION_WORKER_SOFTWARE.md).

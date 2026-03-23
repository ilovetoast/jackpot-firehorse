# Environments

Documentation for **where** the app runs and **what** must be installed on hosts. The full doc index is [README.md](../README.md).

| Document | Purpose |
|----------|---------|
| [SERVER_REQUIREMENTS.md](SERVER_REQUIREMENTS.md) | Third-party OS binaries (ImageMagick, FFmpeg, Poppler, etc.) by role |
| [../DEV_TOOLING.md](../DEV_TOOLING.md) | Local-only artisan helpers and dev utilities |

Local development with Laravel Sail uses the application image defined in `docker/8.5/Dockerfile`; requirements there should match production workers for thumbnail and PDF processing.

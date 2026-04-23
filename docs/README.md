# Documentation index

Use this page as the **table of contents** for engineering docs (e.g. admin “Documents” UI). Prefer these consolidated guides over older scattered filenames.

## Product & architecture

| Document | Contents |
|----------|----------|
| [FEATURES.md](FEATURES.md) | Value proposition, feature areas, product behavior (URLs, gates) |
| [TECHNICAL_OVERVIEW.md](TECHNICAL_OVERVIEW.md) | Stack, capabilities, principles |

## Operations (consolidated)

| Document | Contents |
|----------|----------|
| [MEDIA_PIPELINE.md](MEDIA_PIPELINE.md) | Thumbnails, previews, LQIP, PDF/TIFF/AVIF/WebP, timeouts, env, retry design |
| [UPLOAD_AND_QUEUE.md](UPLOAD_AND_QUEUE.md) | Queue workers, dispatch flow, upload diagnostics, pipeline sequencing, deploy behavior |
| [STORAGE.md](STORAGE.md) | S3 buckets, upload strategy, limits, storage call chain |
| [PERMISSIONS.md](PERMISSIONS.md) | Roles, company settings permissions, audit, tenant-level TODO |
| [TAGS.md](TAGS.md) | AI tag policy, normalization, metadata field, UX, UI consistency, quality metrics |
| [AUTOMATED_METADATA_AND_FILTERS.md](AUTOMATED_METADATA_AND_FILTERS.md) | Automated metadata, filters, tenant governance (merged Phase C + H) |
| [AI_USAGE_LIMITS_AND_SUGGESTIONS.md](AI_USAGE_LIMITS_AND_SUGGESTIONS.md) | AI usage caps, suggestions (merged Phase I + tenant AI design) |

## Environments & tooling

| Document | Contents |
|----------|----------|
| [environments/PRODUCTION_ARCHITECTURE_AWS.md](environments/PRODUCTION_ARCHITECTURE_AWS.md) | **Production AWS** — ECS on EC2, 2×AZ, RDS Multi-AZ, ElastiCache, CloudFront+S3, worker lanes, scaling, cost, CI/CD, phases |
| [environments/PRODUCTION_WORKER_SOFTWARE.md](environments/PRODUCTION_WORKER_SOFTWARE.md) | **Single checklist** — OS packages for production workers (thumbnails, PDF, SVG, video, OCR, Studio Playwright) |
| [environments/README.md](environments/README.md) | Environment doc index |
| [DEV_TOOLING.md](DEV_TOOLING.md) | Local-only dev commands |

## Phase & governance (reference)

| Document | Contents |
|----------|----------|
| [PHASE_INDEX.md](PHASE_INDEX.md) | Map of former “Phase X” docs → where that content lives now (consolidated guides) |

Phase-specific write-ups are **merged into** the operations guides above (see PHASE_INDEX). Prefer those files over hunting for old `PHASE_*.md` names.

## Runbooks & incidents

| Document | Contents |
|----------|----------|
| [operations/DATA_EXPLOSION_RUNBOOK.md](operations/DATA_EXPLOSION_RUNBOOK.md) | DB/API growth, alert caps, monitoring SQL |
| [RUNBOOK_ALERTS_AND_TICKETS.md](RUNBOOK_ALERTS_AND_TICKETS.md) | Alerts and tickets |
| [STAGING_MANIFEST_401.md](STAGING_MANIFEST_401.md) | Staging manifest 401 troubleshooting |
| [DELETION_ERROR_TRACKING.md](DELETION_ERROR_TRACKING.md) | Deletion error system |

## Billing, email & notifications

| Document | Contents |
|----------|----------|
| [billing-expiration.md](billing-expiration.md) | Billing expiration |
| [SUBSCRIPTION_UPGRADE_GUIDE.md](SUBSCRIPTION_UPGRADE_GUIDE.md) | Upgrades |
| [email-notifications.md](email-notifications.md) | Email (Mailables, `EmailGate`, `MAIL_AUTOMATIONS_ENABLED`) |
| [notification-orchestration.md](notification-orchestration.md) | In-app + push orchestration (`NotificationOrchestrator`, OneSignal, channels) |
| [admin-notification-routing.md](admin-notification-routing.md) | **Admin reference** — event/channel matrix and future routing UI |

## Testing

| Document | Contents |
|----------|----------|
| [TESTING.md](TESTING.md) | Manual / QA scenarios |
| [testing.md](testing.md) | PHPUnit: Unit vs Feature, Sail, Xdebug, `@group ffmpeg`, composer scripts |
| [TESTING_DATABASE.md](TESTING_DATABASE.md) | Test database setup |

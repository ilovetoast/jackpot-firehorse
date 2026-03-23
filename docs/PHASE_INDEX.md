# Phase index (canonical map)

**Status:** Reference only — detailed phase write-ups have been **merged into consolidated guides** (see table below). Use this file to understand **what used to be called “Phase X”** and where that material lives now.

**Start here for navigation:** [README.md](README.md)

---

## Where phase content lives now

| Former focus | Consolidated document | Notes |
|--------------|----------------------|--------|
| Upload system, observability, diagnostics, downloader, Phase D roadmap | [UPLOAD_AND_QUEUE.md](UPLOAD_AND_QUEUE.md) | Includes merged appendices from Phase 2, 2.5, 3.1 lock, Phase D |
| Metadata governance, filters (Phase C, H) | [AUTOMATED_METADATA_AND_FILTERS.md](AUTOMATED_METADATA_AND_FILTERS.md) | Phase C + H appended in full |
| Analytics, support tickets, admin observability (Phase 4, 5A, 5B) | [RUNBOOK_ALERTS_AND_TICKETS.md](RUNBOOK_ALERTS_AND_TICKETS.md) | Phase 4/5A/5B appended in full |
| AI usage limits + AI metadata design + tenant AI capabilities (Phase I, 7.5) | [AI_USAGE_LIMITS_AND_SUGGESTIONS.md](AI_USAGE_LIMITS_AND_SUGGESTIONS.md) | Phase I + `admin/ai` doc appended in full |
| Publication / archival (Phase L) | [ASSET_LIFECYCLE_CONTRACTS.md](ASSET_LIFECYCLE_CONTRACTS.md) | Phase L appended in full |
| Thumbnails, media pipeline | [MEDIA_PIPELINE.md](MEDIA_PIPELINE.md) | Operational pipeline (not a numbered phase file) |
| Storage, S3 | [STORAGE.md](STORAGE.md) | |
| Permissions | [PERMISSIONS.md](PERMISSIONS.md) | |
| Tags | [TAGS.md](TAGS.md) | |

---

## Locked behavior (still in force)

Merging docs does **not** relax engineering locks described inside the consolidated files. Treat sections labeled **LOCKED**, **do not modify**, or **immutable** as binding unless product explicitly approves a new phase.

---

## Maintenance

When adding new work, update the relevant **consolidated** doc and [README.md](README.md). Avoid reintroducing parallel `PHASE_*.md` files unless you need a time-bound design spike—then merge into the appropriate guide when the spike ends.

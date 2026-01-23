# Phase Index — Canonical Reference

**Status:** ✅ Active Reference Document  
**Last Updated:** January 2025

---

## Overview

This document is the **SINGLE SOURCE OF TRUTH** for understanding phase structure, status, and what is safe vs unsafe to modify in the Jackpot DAM codebase.

**All existing `PHASE_*.md` files remain unchanged and valid.** This index explains how they relate to each other and the current system state.

---

## How to Use This Document

1. **Before starting new work:** Check this index to understand what is locked
2. **When reviewing existing code:** Use this index to understand phase dependencies
3. **When documenting new phases:** Update this index, but do not modify existing phase docs

---

## Phase Status Legend

- **LOCKED** — Immutable. Do not refactor, rename, or modify without explicit instruction.
- **COMPLETE** — Finished and stable. May be informational or foundational.
- **IN PROGRESS** — Currently active development.
- **PAUSED** — Intentionally stopped. Work may resume later.
- **FUTURE** — Design specification only. Not yet implemented.

---

## Core System Areas

### 1. Upload & Processing Pipeline

**Status:** ✅ LOCKED

**Phase Documents:**
- `PHASE_2_UPLOAD_SYSTEM.md` — Production-ready upload system (LOCKED)
- `PHASE_2_5_OBSERVABILITY_LOCK.md` — Upload observability & diagnostics (LOCKED)
- `PHASE_3_UPLOADER_FIXES.md` — Bug fixes and refinements (COMPLETE)

**Key Components:**
- UploadSession model and lifecycle
- Multipart & direct upload support
- Resume & recovery logic
- Error normalization and diagnostics
- Frontend UploadManager

**What's Locked:**
- Upload error normalization logic
- Error shape contracts
- Upload state machine
- Upload session lifecycle
- Diagnostics panel behavior

**Current State:** Production-ready and stable.

---

### 2. Download & Delivery System

**Status:** ✅ LOCKED

**Phase Documents:**
- `PHASE_3_1_DOWNLOADER_FOUNDATIONS.md` — Initial downloader design (HISTORICAL)
- `PHASE_3_1_DOWNLOADER_LOCK.md` — Complete downloader system (LOCKED)

**Key Components:**
- Download model and lifecycle
- Snapshot vs living downloads
- ZIP file generation (BuildDownloadZipJob)
- S3-based delivery
- Expiration and cleanup

**What's Locked:**
- Download model structure
- ZIP generation logic
- Download status transitions
- Lifecycle management
- Database schema (downloads, download_asset tables)

**Current State:** Complete and production-ready.

---

### 3. Metadata Engine & Automation

**Status:** ✅ LOCKED (Backend) / ✅ COMPLETE (Automation Pipeline)

**Phase Documents:**
- `PHASE_C_METADATA_GOVERNANCE.md` — Metadata governance foundations (COMPLETE)

**Key Components:**
- MetadataSchemaResolver (describes available fields)
- Automated metadata population pipeline:
  - ComputedMetadataJob
  - PopulateAutomaticMetadataJob
  - ResolveMetadataCandidatesJob
- Metadata persistence (MetadataPersistenceService)
- Automated field resolution

**What's Locked:**
- MetadataSchemaResolver behavior
- Automated metadata job pipeline
- asset_metadata merge logic in AssetController
- Field resolution rules
- Metadata persistence flow

**Current State:** Backend foundations complete. Automation pipeline functional.

---

### 4. Metadata Governance (Admin)

**Status:** ✅ COMPLETE

**Phase Documents:**
- `PHASE_C_METADATA_GOVERNANCE.md` — Admin metadata governance (COMPLETE)

**Key Components:**
- System metadata field visibility configuration
- Category suppression rules
- Admin metadata registry UI
- Field definition inspection

**What's Locked:**
- System field immutability
- Category suppression logic
- Admin visibility enforcement

**Current State:** Complete. Admins can observe and configure system metadata.

---

### 5. Asset Grid Filter UX

**Status:** ✅ LOCKED

**Phase Documents:**
- *No dedicated phase doc (consolidated in this index)*

**Key Components:**
- FilterDescriptor contract (JS-only type definition)
- normalizeFilterConfig (canonical config normalization)
- filterTierResolver (primary / secondary / hidden classification)
- filterScopeRules (scope compatibility rules)
- filterQueryOwnership (URL query parameter ownership map)
- filterVisibilityRules (visibility semantics)
- AssetGridPrimaryFilters (primary filter bar UI)
- AssetGridSecondaryFilters (secondary filter UI with expansion)
- Category-switch filter cleanup (query pruning)

**What's Locked:**
- FilterDescriptor contract structure
- Filter tier classification logic
- Scope compatibility rules
- URL query ownership definitions
- Visibility semantics (hidden vs visible, no disabled state)
- Primary and secondary filter UI structure
- Category-switch cleanup behavior
- All filter helper functions and utilities

**Current State:** ✅ COMPLETE and verified. Filter architecture is production-ready.

**Lock Declaration:**
Phase H is **COMPLETE and LOCKED**. No further changes to filter behavior, persistence, or UX are allowed in this phase. Any future filter improvements must occur in a new phase.

**UI Configuration Ownership (Metadata Management):**
- **Filter visibility** and **primary vs secondary placement** are configured **only** in Metadata Management → By Category tab
- All Metadata and Custom Fields tabs are **overview-only** and must never reintroduce filter controls
- Filters tab is **read-only explainer** and must never gain interactive controls
- This is a UI consolidation clarification only; Phase H filter logic remains unchanged

**Saved Filters Status:**
- **PAUSED** — Saved Filters (Phase I/J) are intentionally paused until admin configuration UX is locked and stable
- No code scaffolding, no TODO lists beyond documentation
- Will consume resolved metadata from Phase H when resumed

---

### 6. Metadata UX (Tenant)

**Status:** ✅ COMPLETE + PAUSED (Phase G)

**Phase Documents:**
- *No dedicated phase doc (consolidated in this index)*

**Phase G Breakdown:**
- **G.1** — Tenant Metadata Registry (read-only overview) — ✅ COMPLETE
- **G.2** — Category-First Metadata Enablement UX — ✅ COMPLETE
- **G.3** — Tenant Filter Surface Control — ✅ COMPLETE
- **G.4** — Wiring fixes (automated metadata population, asset detail merge, category loading) — ✅ COMPLETE
- **G.5** — Final UX polish (enable/disable toggle, upload/edit/filter checkboxes, drag-and-drop ordering) — ✅ COMPLETE

**Key Components:**
- TenantMetadataVisibilityService
- TenantMetadataRegistryService
- MetadataFilterService
- Category-first metadata enablement UI
- Filter surface configuration UI
- Asset detail metadata display

**What's Locked:**
- Tenant Metadata Management UI structure
- Category-first view behavior
- Filter Surface tab behavior
- Automated field exclusion from tenant category UI
- Existing API wiring (enable/disable per category, upload/edit/filter visibility, filter surface toggles)
- Fields are global, categories only control visibility
- Automated fields are never manual
- No backend persistence for drag-and-drop order (session only by design)

**Current State:** ✅ COMPLETE and verified. UI functional, backend wiring correct.

**Pause Declaration:**
Phase G is **COMPLETE and PAUSED**. No further metadata UI improvements are expected in this phase. Future metadata UX work will resume under **Phase H** (Filter UX improvements).

**Intentionally Paused (Not Missing):**
- Persisted display ordering per category
- Advanced drawers refinements
- Better visual grouping of shared vs category-specific fields
- Bulk enablement UX

---

### 6. Analytics & Observability Foundations

**Status:** 🔨 IN PROGRESS

**Phase Documents:**
- `PHASE_4_ANALYTICS_FOUNDATIONS.md` — Analytics aggregation foundations (IN PROGRESS)

**Key Components:**
- Event aggregation tables (event_aggregates)
- Pattern detection foundations
- Alert candidate generation

**Current State:** Foundations in progress. Not yet complete.

**Dependencies:** Consumes events from locked upload and download phases.

---

### 8. Support & Ticketing System

**Status:** 🔨 IN PROGRESS

**Phase Documents:**
- `PHASE_5A_SUPPORT_TICKETS.md` — Support ticket integration (IN PROGRESS)
- `PHASE_5B_ADMIN_UI.md` — Admin observability UI (IN PROGRESS)

**Key Components:**
- SupportTicket model
- Alert candidate → ticket linking
- Admin alerts tab (read-only)
- Ticket lifecycle management

**Current State:** In active development.

**Dependencies:** Requires Phase 4 (Analytics & AI) to be complete.

---

### 9. AI Usage & Suggestions

**Status:** ✅ LOCKED

**Phase Documents:**
- `AI_USAGE_LIMITS_AND_SUGGESTIONS.md` — AI usage tracking and suggestion system (LOCKED)

**Key Components:**
- `AiUsageService` — Usage tracking with hard stop enforcement
- `AiMetadataSuggestionService` — Suggestion generation and management
- Monthly calendar-based caps (tagging and suggestions)
- Transaction-safe usage tracking
- Ephemeral suggestion storage

**What's Locked:**
- Usage tracking logic and enforcement
- Cap interpretation (0 = unlimited, -1 = disabled)
- Monthly reset behavior (calendar-based)
- Transaction safety mechanisms
- Suggestion generation rules
- Hard stop behavior

**Current State:** Complete and production-ready. Prevents runaway AI costs.

---

### 10. Future Design Specifications

**Status:** 📋 FUTURE (Design Only)

**Phase Documents:**
- `admin/ai/phase-7-5-tenant-ai-capabilities.md` — Tenant AI capabilities design (FUTURE)

**Key Principles (Design Only):**
- Domain model attachment
- Suggest before acting
- Human confirmation required
- No free-form chat interfaces

**Current State:** Design specification only. Not yet implemented.

---

## Phase File Inventory

### Active Phase Documents

| File | Status | Purpose |
|------|--------|---------|
| `PHASE_2_UPLOAD_SYSTEM.md` | ✅ LOCKED | Production upload system documentation |
| `PHASE_2_5_OBSERVABILITY_LOCK.md` | ✅ LOCKED | Upload observability & diagnostics lock declaration |
| `PHASE_3_UPLOADER_FIXES.md` | ✅ COMPLETE | Bug fixes for upload system (historical record) |
| `PHASE_3_1_DOWNLOADER_FOUNDATIONS.md` | 📚 HISTORICAL | Initial downloader design (superseded by lock doc) |
| `PHASE_3_1_DOWNLOADER_LOCK.md` | ✅ LOCKED | Complete downloader system lock declaration |
| `PHASE_4_ANALYTICS_FOUNDATIONS.md` | 🔨 IN PROGRESS | Analytics aggregation foundations |
| `PHASE_5A_SUPPORT_TICKETS.md` | 🔨 IN PROGRESS | Support ticket integration |
| `PHASE_5B_ADMIN_UI.md` | 🔨 IN PROGRESS | Admin observability UI |
| `PHASE_C_METADATA_GOVERNANCE.md` | ✅ COMPLETE | Metadata governance foundations (admin + tenant concepts) |

### Future Design Documents

| File | Status | Purpose |
|------|--------|---------|
| `admin/ai/phase-7-5-tenant-ai-capabilities.md` | 📋 FUTURE | Tenant AI capabilities design specification |

### Related Technical Documentation

| File | Status | Purpose |
|------|--------|---------|
| `THUMBNAIL_PIPELINE.md` | ✅ COMPLETE | Thumbnail generation system |
| `THUMBNAIL_RETRY_DESIGN.md` | ✅ COMPLETE | Thumbnail retry mechanism |
| `PDF_THUMBNAIL_IMPLEMENTATION.md` | ✅ COMPLETE | PDF thumbnail generation |
| `PDF_SETUP_QUICKSTART.md` | ✅ COMPLETE | PDF thumbnail setup guide |
| `QUEUE_WORKERS.md` | ✅ COMPLETE | Queue worker configuration |
| `RUNBOOK_ALERTS_AND_TICKETS.md` | ✅ COMPLETE | Operational runbook |
| `AI_USAGE_LIMITS_AND_SUGGESTIONS.md` | ✅ LOCKED | AI usage tracking and suggestion system |

---

## Current Position

### Active Development

- **Phase 4** — Analytics Aggregation Foundations (IN PROGRESS)
- **Phase 5A** — Support Ticket Integration (IN PROGRESS)
- **Phase 5B** — Admin Observability UI (IN PROGRESS)

### Next Phase (Not Started)

**Immediate Next Work:**
- Complete Phase 4, 5A, and 5B before starting new major work

**Phase H (Asset Grid Filter UX):**
- ✅ COMPLETE and LOCKED
- See Phase H section below for details

---

## ⚠️ Locked Areas — Do Not Modify Without Explicit Instruction

### Backend (LOCKED)

**Upload & Processing:**
- UploadSession model and lifecycle
- Upload error normalization
- Upload diagnostics panel
- Upload state machine

**Download & Delivery:**
- Download model and lifecycle
- ZIP generation logic
- Download status transitions

**Metadata Engine:**
- MetadataSchemaResolver
- MetadataVisibilityResolver
- TenantMetadataVisibilityService
- TenantMetadataRegistryService
- MetadataFilterService
- Category suppression logic
- Automated metadata jobs and resolution pipeline
- asset_metadata merge logic in AssetController

### Frontend (LOCKED)

**Upload UI:**
- UploadManager singleton behavior
- Upload state recovery
- Upload progress tracking

**Metadata UX:**
- Tenant Metadata Management UI structure
- Category-first view behavior
- Filter Surface tab behavior
- Automated field exclusion from tenant category UI
- Existing API wiring for enable/disable per category, upload/edit/filter visibility, filter surface toggles

**Asset Grid Filter UX (Phase H):**
- Primary and secondary filter UI structure
- Filter tier classification behavior
- Scope compatibility rules
- URL query ownership definitions
- Visibility semantics (hidden vs visible)
- Category-switch cleanup behavior

### Architecture (LOCKED)

- Fields are global, categories only control visibility
- Automated fields are never manual
- Automated fields may appear in filters, but not in tenant category configuration
- No schema ownership by categories
- No metadata versioning
- No backend persistence for drag-and-drop order (session only by design)

**AI Usage & Suggestions:**
- AI usage tracking with hard stop enforcement
- Monthly calendar-based reset (no explicit reset jobs)
- Transaction safety prevents race conditions
- Cap interpretation: 0 = unlimited, -1 = disabled, positive = cap
- Suggestions are ephemeral (never auto-applied)
- Separate tracking for tagging vs suggestions

---

## How to Start New Work Safely

### 1. Consult This Index First

Before modifying code related to:
- Upload/processing
- Download/delivery
- Metadata (any aspect)
- Filters or search

Check this index to see if the area is locked.

### 2. Understand Phase Dependencies

- Phase 4 requires events from Phase 2 and Phase 3.1
- Phase 5A requires Phase 4
- Phase 5B requires Phase 5A
- Phase G (Metadata UX) requires Phase C (Metadata Governance)

Do not modify locked dependencies.

### 3. Recognize Phase Naming Conventions

- **Numeric phases (2, 3, 4, 5A, 5B):** Generally chronological but may overlap
- **Letter phases (C, G):** Thematic groupings that may span multiple numeric phases
- **Lock documents (`*_LOCK.md`):** Explicit declarations that a phase is immutable
- **Foundation documents:** Initial designs that may be superseded by lock documents

### 4. Distinguish UI Polish from Architecture Changes

**Allowed:**
- UI copy improvements (no logic changes)
- Visual hierarchy adjustments
- Styling improvements

**Not Allowed Without Explicit Instruction:**
- Changing data flow
- Modifying resolver behavior
- Re-modeling metadata relationships
- "Simplifying" services
- Merging automated + manual concepts

### 5. When in Doubt, Ask

If a task touches:
- Metadata UI, filtering, or category visibility
- Upload or download systems
- Any area marked LOCKED

**STOP and ask for confirmation before proceeding.**

---

## Phase Evolution Notes

### Overlapping Phases

Some phases intentionally overlap:
- **Phase C** (Metadata Governance) provides backend foundations for **Phase G** (Metadata UX)
- **Phase 2.5** extends **Phase 2** with observability
- **Phase 3** fixes bugs in **Phase 2** without creating Phase 2.1

This is intentional and normal.

### Historical vs Current

- `PHASE_3_1_DOWNLOADER_FOUNDATIONS.md` documents the initial design
- `PHASE_3_1_DOWNLOADER_LOCK.md` documents the final locked state

Both are valid. The lock document is authoritative for what should not be changed.

### Missing Phase Numbers

Not every number has a phase document:
- Phase 1 — Not documented (assumed to be initial system setup)
- Phase 3 — Uploader fixes, not a full phase
- Phase 6, 7 — Referenced but not yet documented here

This is acceptable. This index reflects documented phases only.

---

## Maintenance Guidelines

### When to Update This Index

1. **New phase document created:** Add to inventory and appropriate section
2. **Phase status changes:** Update status (e.g., IN PROGRESS → LOCKED)
3. **New locked area identified:** Add to "Locked Areas" section
4. **Phase dependency discovered:** Update dependency notes

### When NOT to Update This Index

1. **Bug fixes in existing phases:** Document in phase doc, not here
2. **Code refactoring:** Only update if refactor affects phase boundaries
3. **UI polish:** Only update if polish changes phase status

### Preserving Existing Phase Docs

- **Do NOT** rename, edit, delete, or move existing `PHASE_*.md` files
- **Do NOT** update phase numbers in existing docs
- **Do NOT** refactor or reinterpret historical prompts
- This index is ADDITIVE ONLY

---

## Summary

**Current System State:**
- ✅ Upload & Processing: LOCKED and production-ready
- ✅ Download & Delivery: LOCKED and complete
- ✅ Metadata Engine: LOCKED (backend) and complete (automation)
- ✅ Metadata Governance: COMPLETE (admin)
- ✅ Metadata UX: COMPLETE + PAUSED (Phase G, tenant)
- ✅ AI Usage & Suggestions: LOCKED (usage tracking and suggestion system)
- 🔨 Analytics & Observability: IN PROGRESS
- 🔨 Support & Ticketing: IN PROGRESS
- 📋 Tenant AI: FUTURE (design only)
- 📋 Metadata UX Filter Improvements: FUTURE (Phase H)

**For Future Agents:**
- Always check this index before modifying locked areas
- Respect phase dependencies
- Ask for confirmation when touching locked code
- Understand that UI polish ≠ architecture changes

---

**End of Phase Index**

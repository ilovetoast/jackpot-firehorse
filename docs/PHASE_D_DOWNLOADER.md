# Phase D — Downloader Phased Plan

**Status:** 📋 PLANNING  
**Baseline:** Phase 3.1 Downloader (LOCKED) — see `PHASE_3_1_DOWNLOADER_FOUNDATIONS.md`, `PHASE_3_1_DOWNLOADER_LOCK.md`

---

## Overview

This document defines the phased roadmap for the Jackpot downloader: foundation first (D1), then management and access (D2), advanced output (D3), and dynamic / press-kit downloads (D4). Each phase builds on the locked Phase 3.1 downloader system without modifying its locked behavior.

---

## Phase D1 — Downloader Foundation

**Scope:** Everything above (i.e. the minimal, public-only downloader built on Phase 3.1).

**In scope for D1:**
- Public download links only (no access restrictions beyond public)
- No “forever” links (expiration enforced per plan)
- No renaming of downloads
- No password protection
- Core flow: create download → build ZIP → deliver via public link → expire and cleanup

**Out of scope for D1:**
- Extend expiration (paid)
- Restrict access (brand/company/user)
- Manager controls
- Regenerate / revoke
- Naming templates, folder structures, manifests, size variants
- Non-materialized / dynamic ZIPs

**Deliverables:**
- Public download creation and delivery working end-to-end
- Expiration and cleanup aligned with Phase 3.1
- No new access modes or management features beyond what Phase 3.1 allows

---

## Phase D2 — Download Management

**Scope:** Extend expiration, restrict access, manager controls, regenerate/revoke.

**In scope for D2:**
- **Extend expiration (paid)** — Allow paid plans (or specific entitlements) to extend download link expiration beyond default.
- **Restrict access** — Restrict who can access a download by brand, company, or user (e.g. team-only, invite-only).
- **Manager controls** — UI and API for managers to create, list, and manage downloads (view, extend, restrict, revoke).
- **Regenerate / revoke** — Regenerate ZIP (e.g. after asset set changes) and revoke access (invalidate link or mark as revoked).

**Out of scope for D2:**
- Naming templates, folder structures, manifests, size variants (D3)
- Non-materialized / dynamic ZIPs (D4)

**Dependencies:** D1 complete; Phase 3.1 downloader (LOCKED) unchanged.

---

## Phase D3 — Advanced Output

**Scope:** Naming, folder structure, manifests, size variants.

**In scope for D3:**
- **Naming templates** — Configurable naming for files inside the ZIP (e.g. by metadata, date, sequence).
- **Folder structures** — Configurable folder hierarchy inside the ZIP (e.g. by collection, category, date).
- **Manifests** — Optional manifest file (e.g. CSV/JSON) listing assets and metadata included in the ZIP.
- **Size variants** — Option to include specific size variants (e.g. thumbnails, previews, originals) per asset in the download.

**Out of scope for D3:**
- Non-materialized / always-up-to-date ZIPs (D4)

**Dependencies:** D1–D2 (as needed for management of advanced options); Phase 3.1 unchanged.

---

## Phase D4 — Dynamic / Press Kit Downloads

**Scope:** Non-materialized ZIPs, always up-to-date content, premium/enterprise.

**In scope for D4:**
- **Non-materialized ZIPs** — Download built on-demand (or periodically) instead of pre-built and stored; no long-lived ZIP artifact.
- **Always up-to-date** — ZIP contents reflect current asset set (e.g. “all assets in this collection” or “current press kit”) at request time.
- **Premium / enterprise** — Feature gated to premium or enterprise plans; possible SLA/performance considerations for on-demand build.

**Out of scope for D4:**
- Changes to Phase 3.1 locked behavior (additive only)

**Dependencies:** D1–D3 as needed; Phase 3.1 unchanged.

---

## Summary Table

| Phase | Name                    | Focus                                           |
|-------|-------------------------|-------------------------------------------------|
| **D1** | Downloader Foundation   | Public links, no forever links, no renaming, no password |
| **D2** | Download Management     | Extend expiration (paid), restrict access, manager controls, regenerate/revoke |
| **D3** | Advanced Output         | Naming templates, folder structures, manifests, size variants |
| **D4** | Dynamic / Press Kit    | Non-materialized ZIPs, always up-to-date, premium/enterprise |

---

## Implementation Order

1. **D1** — Next Cursor prompt / implementation slice: deliver foundation (public only, expiration, no forever links, no renaming, no password).
2. **D2** — After D1: add management (extend expiration, restrict access, manager controls, regenerate/revoke).
3. **D3** — After D2: add advanced output (naming, folders, manifests, size variants).
4. **D4** — After D3: add dynamic / press kit downloads (non-materialized, always up-to-date, premium/enterprise).

---

## Relation to Phase 3.1

- Phase 3.1 is **LOCKED**. No changes to existing download model structure, ZIP build flow, cleanup jobs, or controller contracts.
- Phase D adds **new** behavior (access modes, management, output options, dynamic builds) in new code paths or additive configuration only.
- Where D1–D4 need to “sit on top of” 3.1, use the existing Download model, jobs, and delivery endpoints as the baseline and extend via new options, policies, or separate code paths rather than modifying locked components.

---

*For current downloader implementation details, see `PHASE_3_1_DOWNLOADER_FOUNDATIONS.md` and `PHASE_3_1_DOWNLOADER_LOCK.md`.*

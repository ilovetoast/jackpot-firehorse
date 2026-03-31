# Agency incubation — product & engineering roadmap

This document captures **agreed rules** for incubated client companies (storage, timelines, enforcement, support) and a **phased implementation plan**. It complements [FEATURES.md](FEATURES.md) and existing Phase AG data (`AgencyTier`, `Tenant` incubation fields).

---

## Goals

1. **Per-incubated-company** storage and limits, driven by the **target (preemptive) plan** chosen for that client—not by the agency’s own subscription tier. An agency on a modest plan may still incubate a client intended for **Enterprise**-scale storage.
2. **Hard enforcement** of the incubation window: after expiry (with warning + optional short grace), the tenant is **locked down** until ownership is transferred or support extends the window.
3. **Gatekeeping**: agencies are approved and monitored; abuse of incubation is handled operationally (see [Abuse & gatekeeping](#abuse--gatekeeping)).
4. **Support tooling**: ability to grant **deadline extensions** in discrete actions, with **hard caps per action** derived from the **incubating agency’s tier** (Silver / Gold / Platinum).

---

## Concepts

| Term | Meaning |
|------|--------|
| **Incubated company** | A `tenants` row with `incubated_by_agency_id` set (created via agency flow or legacy). |
| **Preemptive / target plan** | The commercial plan we **expect** the client to adopt after transfer (e.g. Pro, Premium, Enterprise). Drives **effective limits** (storage, brands, users, etc.) during incubation so usage matches what they will pay for. **Chosen when incubation starts**; the agency may **change it later** from the agency dashboard (same client row). |
| **Incubation window** | Time allowed **before** a completed **ownership transfer**; after this, **hard lock** if still not transferred (unless extended). |
| **Agency tier** (Silver / Gold / Platinum) | Partner level for the **agency** tenant—defines **default** transfer window length, **max days per support extension grant**, and max concurrent incubations (where enforced). |

---

## 1. Storage (per incubated company)

- **Scope:** Storage grants apply to **each incubated client tenant** (`tenant_id`), not pooled across all clients for the agency.
- **Driver:** Limits come from the **preemptive target plan** selected for that incubation (e.g. “this client is being brought up as Enterprise”), not from the agency’s own DAM plan.
- **Implementation note:** Resolve effective limits via `PlanService` using **`incubation_target_plan_key`** while the tenant is incubated and **no ownership transfer has completed**, so storage/brand/user caps match the **target** plan’s `config/plans.php` limits. **Manual plan override** on the tenant still wins when set (platform admin).
- **Exception — Platinum / strategic:** Where product allows, **unlimited** (or very high) storage for the incubated tenant still tied to **abuse monitoring** and manual review—not a free-for-all for all agencies.

### Abuse & gatekeeping

- Agencies are **onboarded and approved** before full incubation privileges.
- **Monitor** usage patterns (storage growth, number of incubated tenants, churn, support tickets).
- Escalation path: downgrade privileges, require manual approval per new incubation, or revoke agency status—**policy owned by ops/sales**, engineering provides flags and admin tools.

---

## 2. Maximum timeline to transfer (ownership)

If the client **does not** complete **ownership transfer** within the window, the incubated company enters **[Locked incubation](#3-enforcement-hard)** (see below).

**Tiered windows (agreed defaults — enforced via `AgencyTier.incubation_window_days` and creation logic):**

| Agency tier (partner level) | Max incubation window (transfer deadline) |
|----------------------------|-------------------------------------------|
| Silver | **1 month** (30 days) |
| Gold | **2 months** (60 days) |
| Platinum | **6 months** (180 days) |

- **Per-incubated-tenant** `incubation_expires_at` is set from the agency tier at **creation time** (fallback by tier name if the row has nulls).
- **Platform admin / support** can **push** the deadline via admin API or company view (subject to [extension caps](#extension-caps-by-tier) below).

---

## 3. Enforcement (hard)

### Before expiry

- **Banner / warning** when approaching the end of the window (e.g. 7 days, 3 days, 1 day—exact thresholds TBD in UX).
- **Optional: one calendar day grace** after `incubation_expires_at` where behavior is unchanged or only soft-limited (product to confirm exact behavior). Document as **single** grace period, not recurring. *(Not implemented in v1 middleware — lock is strictly after `incubation_expires_at`.)*

### After deadline (hard lock)

- **No uploads** and **no downloads** for that tenant (enforced on upload pipeline, asset download routes, download bucket, and brand asset downloads).
- **Settings / admin surfaces** largely **locked** (future phase; see roadmap) except where required for **transfer** and **support contact**.
- **Allowed actions for users on that tenant:**
  1. **Complete ownership transfer** to the client (primary path back to normal operation).
  2. **Contact support** to request a **time extension**; the agency **submits a request** from the **agency dashboard** (per tenant / when past limit), or pursues **transfer**.

All other workflows should fail fast with a clear message pointing to transfer or support.

---

## Extension caps by tier

**Support / site admin** may extend `incubation_expires_at` by up to **N days per action**, where **N** is capped by the **incubating agency’s** tier (not the client’s plan):

| Agency tier | Max additional days **per extension grant** |
|-------------|-----------------------------------------------|
| Silver | **14** (~2 weeks) |
| Gold | **30** (~1 month) |
| Platinum | **180** (up to ~6 months) |

Stored as `AgencyTier.max_support_extension_days` (with name-based fallback in code). Admin endpoint: `POST /app/admin/api/companies/{tenant}/incubation/extend` with `extend_days` (validated) and optional `reason`.

**Agency** submits an **extension request** (timestamp + optional note) from the agency dashboard; operations uses admin tools to apply the actual extension within the cap.

---

## 4. Support tooling (engineering)

- Admin or support UI / API: extend deadline with `extend_days` ≤ tier max, reason + actor logged.
- Agency dashboard: show **transfer deadline**, **target plan**, **locked** state, **extension request** submission, and **change target plan**.

---

## 5. Relationship to transfer & billing

- On **successful ownership transfer**, normal **tenant billing** applies: Stripe subscription for the **client’s chosen plan** (should align with **preemptive plan** to avoid “Basic subscription, Pro library” surprises).
- **Preemptive plan** during incubation should match or deliberately preview the **post-transfer** plan so metering and expectations stay consistent.

---

## Resolved: agency tier vs overrides (item 3)

1. **Defaults:** When an agency creates a new incubated company, the **transfer deadline** comes from the agency’s current **`AgencyTier`** (e.g. Silver=30d, Gold=60d, Platinum=180d), with name-based fallback if DB fields are null.
2. **Overrides:** **Platform admin / support** can **push** the deadline per tenant via admin tools; each push is limited by **max_support_extension_days** for that incubation’s agency tier.
3. **Target plan:** The **target plan** (`incubation_target_plan_key`) is **required when starting incubation** and can be **changed later** by the agency from the agency dashboard (same client).

---

## 6. Implementation phases (engineering)

Phases are ordered by **dependency** and **risk**.

### Phase A — Schema & source of truth

- [x] Persist **`incubation_target_plan_key`** on incubated `tenants`.
- [x] Persist **`incubation_expires_at`** from enforced agency-tier window (+ admin overrides).
- [x] `incubation_extension_requested_at`, `incubation_extension_request_note` for agency requests.
- [x] `AgencyTier.max_support_extension_days` (with seed defaults).

### Phase B — Effective limits

- [x] **`PlanService`:** For incubated tenants without completed transfer, use **target plan** limits when `incubation_target_plan_key` is set (after manual override check).

### Phase C — Window enforcement

- [x] Middleware: **`incubation_expired` + not transferred** ⇒ block uploads / downloads on gated routes.
- [ ] Restrict **settings** routes when locked (allow list: transfer flow, read-only messaging, support link).

### Phase D — UX

- [ ] Warning banners (7d / 3d / 1d / last day) — partial via agency dashboard.
- [ ] **One-day grace** behavior (if product confirms).
- [ ] Locked state UI: only **Transfer** + **Contact support** paths.

### Phase E — Support & admin

- [x] Admin API: extend deadline within tier cap.
- [x] Agency dashboard: deadline, target plan, extension request, change plan.
- [ ] Optional: admin dashboard for incubation health and abuse flags.

### Phase F — Agency tier config

- [x] Seed **Silver=30d / Gold=60d / Platinum=180d** window and **14 / 30 / 180** max extension days.
- [ ] Enforce **max incubated companies** / brands from `AgencyTier` where product defines numbers.

### Phase G — Tests

- [x] Feature tests: creation with target plan, admin extend, tier cap rejection.
- [ ] Feature tests: lock middleware on upload/download when expired.

---

## 7. Related code (today)

- `App\Models\AgencyTier` — `max_incubated_companies`, `max_incubated_brands`, `incubation_window_days`, `max_support_extension_days`.
- `App\Models\Tenant` — `incubated_at`, `incubation_expires_at`, `incubated_by_agency_id`, `incubation_target_plan_key`, extension request fields.
- `AgencyDashboardController` — create incubation with target plan; update target plan; extension request.
- `Admin\AdminIncubationController::extendDeadline` — extend with tier cap.
- `App\Services\IncubationWorkspaceService` — `isWorkspaceLocked`.
- `EnsureIncubationWorkspaceNotLocked` middleware — upload/download routes.

This roadmap supersedes “advisory only” behavior for window and storage once implemented.

---

## 8. Document control

| Version | Date | Notes |
|--------|------|--------|
| 1.0 | 2026-03-30 | Initial plan from stakeholder answers + recommendations |
| 1.1 | 2026-03-30 | Item 3: tier-hard windows, extension caps, target plan at create + change, admin push, agency requests, implementation status |

---

## 9. Next steps

- [ ] Grace period and full **settings** lock allow list.
- [ ] In-app **banner** on client workspace for expiry / lock.
- [ ] Automated tests for **upload/download 403** when locked.

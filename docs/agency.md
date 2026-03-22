PART 1 — INTERNAL PHASE DOC

Agency Partner Program (Draft v1)

Core Principle

Agencies are not “users” of the DAM.
They are temporary owners and long-term stewards of client asset systems.

The system must reward transfer, not discourage it.

Phase A — Agency Identity & Gating
Agency Account (first-class)

An Agency is a tenant with elevated capabilities.

Ways to become an agency:

Apply via form (manual approval initially)

Admin-flagged internally

Future: auto-approval based on domain, size, referrals

Why approval matters:

Prevents abuse

Signals exclusivity

Enables partner economics

💡 This mirrors Shopify Partners: not everyone is one by default.

Phase B — Brand Incubation (Agency-Owned)
Key capability

Agencies can:

Create brands inside their own agency tenant

Fully configure them for daily use

Treat them like “real” brands

These brands are:

Fully functional

Unbilled (or lightly metered)

Clearly marked as Incubated / Client-Eligible

This directly supports your use case:

“I manage Brand Y daily for my client, but they don’t own it yet.”

Phase C — Brand Transfer (Critical Differentiator)
New concept: Brand-Level Transfer

Not just company transfer — brand transfer.

Flow:

Agency selects a brand they own

Clicks Transfer Brand

Options:

Transfer to new client company

Transfer to existing client company

Client activates plan

Ownership moves

Agency becomes retained partner on that brand/company

Nothing is re-created. Nothing is exported.

This is rare in DAMs and extremely agency-friendly.

Phase D — Incentives to Transfer (this solves your concern)
The problem you identified

Agencies could just:

Add a client user

Never transfer ownership

Avoid friction

You fix this by making transfer strictly better
Incentive levers (stackable)
1️⃣ Economic incentives (primary)

Agencies receive ongoing value only if the client owns the company:

Revenue credit (% of client subscription)

Partner tier progression

Agency plan discounts

Eligibility for premium features

No transfer → no rewards.

2️⃣ Capability incentives (very powerful)

Certain features are only available after transfer:

Examples:

Client billing & usage analytics

Long-term rights governance

Legal audit exports

Client-only approval overrides

Advanced public sharing

Agencies can set up the system, but the full value unlocks only when the client owns it.

This reframes transfer as a benefit, not a loss.

3️⃣ Risk incentives (subtle but effective)

Incubated brands have:

Time-boxed grace periods

Soft limits (assets, AI runs, downloads)

Clear messaging:

“This brand is prepared for transfer. Activate to unlock full access.”

No hard shutdowns — just pressure.

Phase E — Time-Based Pricing Philosophy

Your instinct here is excellent.

Suggested model

Incubation Window (example):

14 days fully free

No storage limits

No brand limits

After window:

Either:

Transfer brand

Or agency absorbs light cost

This:

Encourages clean handoffs

Avoids long-term freeloading

Feels fair

Phase F — Partner Tiers (Clean & Familiar)
Example tiers
Starter Partner

Unlimited incubated brands

10% credit on activated clients

Listed in partner directory (later)

Pro Partner

20% credit

Priority support

Co-branding options

Agency dashboards

Premier Partner

Custom revenue share

White-label options

Joint sales

Early feature access

Credits > cash (initially).

Phase G — Roles After Transfer
Retained role: Agency Partner

Configurable per client:

Upload assets

Approve assets / metadata

Manage brands

No billing control

No ownership control

Clients can downgrade or revoke — agency trust is earned.

Phase H — UI / UX implications (high level)
New surfaces needed

“Agency Dashboard”

“Incubated Brands” badge

“Ready for Transfer” state

“Transfer Brand” CTA

Partner earnings / credits view

Signup flow

Separate Agency Apply path

Normal signup unchanged

Agency flag unlocks features

No need for separate auth system.

---

## Implementation (current codebase)

- **Tenant fields:** `incubated_by_agency_id`, `incubated_at`, `incubation_expires_at` on `tenants` (see `Tenant` model). `incubation_expires_at` is **informational** in the agency dashboard; **no middleware or job revokes access when it passes** (see `AgencyPartnerProgramTest` “no logic enforces incubation expiration”).
- **Agency tiers:** `agency_tiers.incubation_window_days` (and max incubated company/brand caps) are **nullable** in `AgencyTierSeeder` — `null` means no advisory limit in the UI. Enforcement is not wired yet.
- **Default demo seed (`CompanyBrandSeeder`):** Velvet Hammer is the **primary agency** (company 1). The four client companies (St. Croix, Augusta, ACG, Victory) are **incubated** by that agency (`incubated_by_agency_id`), with `incubated_at` set and `incubation_expires_at` null (no countdown). There is **no separate end-client owner user** on those tenants: access is **tenant_agencies** + agency users on the client tenant; the agency primary user holds the tenant **`owner`** role on the client as **temporary steward** until an ownership transfer. Optional user `johndoe@example.com` is not attached to those companies.
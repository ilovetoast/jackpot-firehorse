# Security: downloads, public pages, and external (collection) users

This document summarizes how shareable download links and the public download experience interact with **external users** (collection-only access) and what to consider operationally.

For collection access flows, see also [COLLECTIONS_ACCESS.md](./COLLECTIONS_ACCESS.md). For the permission model, see [PERMISSIONS.md](./PERMISSIONS.md).

---

## External (collection) users

**Definition:** A user who has an accepted `collection_user` grant for at least one collection in a tenant **and** has **no** active `brand_user` membership on any brand in that tenant (`User::isExternalCollectionAccessOnlyForTenant`).

**Typical experience**

- Structural `tenant_user` membership may exist (e.g. viewer) so the tenant can resolve in session; this is **not** brand membership.
- Navigation and routes are constrained (e.g. collection-only allowlist); assets are scoped to granted collections.
- **Agency dashboard** lists these accounts under “External access” (collection-only), separate from agency staff.

**Downloads**

- They may create downloads from an allowed flow (e.g. collection bucket) when the product exposes that UI.
- They **cannot** set a download to **public** (unauthenticated) access. The UI hides that option and defaults to **Company members (sign-in required)**. The API rejects `access_mode=public` for the same users.
- **Brand members** access mode is hidden in the create/edit UI for collection guests, because they are not brand members and could not use a brand-scoped link themselves.

---

## Public download links (`access_mode = public`)

**Behavior:** Anyone with the URL can open the landing page and obtain the file (subject to password policy, expiration, and ZIP readiness).

**Who may create or switch a link to public**

- Controlled by the tenant/brand permission **`downloads.share_public_link`** (see `PermissionMap` and `PermissionSeeder`).
- **Never** allowed for external collection guests (hard rule in `User::mayCreatePublicDownloadLinkForTenant`).
- Until the `downloads.share_public_link` permission row exists in the database (before migrations/seeders on older environments), non-guest users are treated as **allowed** for backward compatibility.

**Operational risks**

- Links can be forwarded; treat them like unauthenticated capability URLs.
- Prefer **company**, **brand**, or **specific users** when recipients should be logged-in workspace members.
- Enterprise policies may require passwords or max expiration for public links (`EnterpriseDownloadPolicy`).

---

## Public download page (`/d/{download}` and related routes)

**Purpose:** Branded or minimal landing UI, optional password gate, then delivery of the ZIP or streamed archive.

**Access checks**

- **Public** mode: no login required; `validateAccess` allows the request.
- **Company / team:** User must be authenticated and bound to the **same tenant** as the download.
- **Brand:** User must be authenticated, same tenant, and assigned to the download’s **brand**.
- **Users / restricted:** User must be authenticated, same tenant, and listed on the download (or allowed when the allowlist is empty per product rules).

**Notes**

- Unauthenticated visitors hitting a non-public download should see an appropriate challenge or denial (login / not found), not the file.
- Signed URLs and CDN behavior are implementation details; the **access_mode** is the primary authorization contract.

---

## Related code (for engineers)

| Area | Location |
|------|-----------|
| Public link permission + guest rule | `User::mayCreatePublicDownloadLinkForTenant`, `User::isExternalCollectionAccessOnlyForTenant` |
| Enforce on create / settings change | `DownloadController::assertUserMayUsePublicDownloadAccess` |
| Frontend flags | `auth.downloads.can_share_public_link` in `HandleInertiaRequests` |
| Create / edit UI | `CreateDownloadPanel.jsx`, `EditDownloadSettingsModal.jsx` |
| Access resolution | `DownloadController::validateAccess` |

---

## Change log

- **D12:** Added `downloads.share_public_link`, blocked public links for collection guests, defaulted guests to sign-in–required company scope in UI, documented this file.

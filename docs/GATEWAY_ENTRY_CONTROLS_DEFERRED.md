# Gateway entry controls (deferred brand settings)

This document describes **system-enforced** gateway behavior in the Jackpot app, and how it relates to `brands.portal_settings.entry` keys that still persist in the database but are **hidden** from the Brand → **Public Gateway** UI by default.

## Source of truth in code

| Area | File(s) |
|------|---------|
| Gateway HTTP + resume cookie | [`app/Http/Controllers/BrandGatewayController.php`](../app/Http/Controllers/BrandGatewayController.php), [`app/Support/GatewayResumeCookie.php`](../app/Support/GatewayResumeCookie.php) |
| Context + resume merge | [`app/Services/BrandGateway/BrandContextResolver.php`](../app/Services/BrandGateway/BrandContextResolver.php) |
| Theme + tagline | [`app/Services/BrandGateway/BrandThemeBuilder.php`](../app/Services/BrandGateway/BrandThemeBuilder.php) |
| Public Gateway UI | [`resources/js/Components/portal/EntryExperience.jsx`](../resources/js/Components/portal/EntryExperience.jsx) |
| Config | [`config/gateway.php`](../config/gateway.php) |

## Current product behavior

1. **Entry style** — When the gateway shows the cinematic enter flow (`mode === enter`), the app forces **cinematic** (never instant) for that path. Values in `portal_settings.entry.style` are ignored for this path but remain in the DB.

2. **Default destination** — After gateway completion (and when there is no `intended_url` from [`EnsureGatewayEntry`](../app/Http/Middleware/EnsureGatewayEntry.php)), users land on **`/app/overview`**. `portal_settings.entry.default_destination` is ignored but preserved.

3. **Auto enter** — Cinematic auto-enter runs whenever `mode === enter` and the URL does not use `?switch=1` or `?mode=login|register`. `portal_settings.entry.auto_enter` is ignored but preserved.

4. **Last workspace resume** — Encrypted cookie `jp_gateway_resume` (TTL from `GATEWAY_RESUME_TTL_MINUTES`, default **240**). Lets users with **multiple brands** (or multi-company flows) skip the picker on plain `GET /gateway` when the cookie is valid. **`?switch=1`** clears the cookie and shows the picker. Logout queues cookie removal.

5. **All-workspaces brand picker** — On plain `GET /gateway` (no `?company`, `?tenant`, or `?brand`, and not on a company subdomain), the gateway lists **every brand** the user can open across **all** companies they belong to. Brands are sorted with the **current session company first** (recent workspace), then by company name. Use `?company={slug}` or a company subdomain to keep the list scoped to one workspace. `POST /gateway/select-brand` sets both `tenant_id` and `brand_id` from the chosen brand.

6. **Gateway tagline** — Controlled by `portal_settings.entry.tagline_source`: `brand` (Brand DNA tagline), `custom` (`tagline_override` text), or `hidden`. Legacy rows without `tagline_source` keep previous behavior (override text first, then DNA).

## Re-enabling legacy UI controls

Set in `.env`:

```env
GATEWAY_SHOW_LEGACY_ENTRY_CONTROLS=true
```

This exposes **Entry style**, **Auto enter**, and **Default destination** again on the Public Gateway tab (`EntryExperience.jsx`).

## Tenant vs brand gateway URLs

- **Brand-scoped login (themed):** `/gateway?mode=login&brand={brand_slug}` — built in Public Gateway and invite emails.
- **Company-scoped login:** `/gateway?mode=login&tenant={tenant_slug}` — e.g. [`TenantPortalController`](../app/Http/Controllers/TenantPortalController.php) `login()`.

Both resolve through the same [`BrandGatewayController`](../app/Http/Controllers/BrandGatewayController.php).

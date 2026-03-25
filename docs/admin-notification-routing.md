# Admin reference: notification routing

This page is a **static reference** for operators and engineers. It mirrors the current **code/config** source of truth for notification orchestration.

**Future:** A **product admin UI** (or super-admin) will likely expose this matrix as editable configuration, so users or tenants can choose which channels receive which events. Until that exists, routing is defined only in **`config/notifications.php`** and in dispatch code.

## How to read this page

| Column | Meaning |
|--------|---------|
| **Event key** | String passed to `NotificationOrchestrator::dispatch('event.key', …)`. |
| **Channels** | `in_app` → grouped feed (`NotificationGroupService`); `email` → orchestrated email (stub); `push` → OneSignal. |
| **Global gates** | `NOTIFICATIONS_ENABLED` must be true for any orchestrated delivery. Push also requires `PUSH_NOTIFICATIONS_ENABLED` and OneSignal keys. |

This is **not** the same as [email-notifications.md](email-notifications.md) (Mailables for direct mail). The orchestrator’s **`email`** channel is reserved for future wiring to Mailables per event.

## Event catalog (current)

| Event key | Channels (config) | Typical trigger | Notes |
|-----------|-------------------|-----------------|-------|
| `generative.published` | `in_app`, `push` | Editor “publish to library” completes upload finalize (`EditorAssetBridgeController`) | In-app respects tenant `notifications_enabled` feature gate. Push uses OneSignal `external_id` = user id string. |

Add new rows here whenever a new event is added to `config/notifications.php` and a dispatch site is implemented.

## Payload fields (shared convention)

These fields are what **callers** should pass when dispatching; not every channel uses every field.

| Field | Used by | Description |
|-------|---------|-------------|
| `user_ids` | `in_app`, `push` | Recipients (ids; push sends as OneSignal external ids). |
| `title` | `in_app`, `push` | Short headline. |
| `message` | `in_app`, `push` | Body text. |
| `tenant_id`, `tenant_name` | `in_app` (and future prefs) | Workspace context. |
| `brand_id`, `brand_name` | `in_app` | Brand context. |
| `asset_id` | `in_app`, `push` data | Optional asset reference. |
| `action_url` | `in_app`, `push` | Optional URL (e.g. asset view). |

## Environment flags (operations)

| Variable | Default | Effect |
|----------|---------|--------|
| `NOTIFICATIONS_ENABLED` | `false` | Disables all orchestrator dispatches. |
| `PUSH_NOTIFICATIONS_ENABLED` | `false` | Disables server push + client OneSignal init (`client_enabled` in Inertia). |
| `ONESIGNAL_APP_ID` | — | Required for push + web SDK when enabled. |
| `ONESIGNAL_REST_API_KEY` | — | Server-side OneSignal only. |

## Future admin UI (planned)

When building configuration UI, consider:

1. **Source of truth** — Start from the event catalog in `config/notifications.php`, then migrate to DB or tenant-scoped settings if needed.
2. **Overrides** — Per-tenant or per-user channel toggles (merge with `NotificationOrchestrator` before sending).
3. **Email channel** — Wire `EmailChannel` to specific Mailables per event, still respecting `EmailGate` / `MAIL_AUTOMATIONS_ENABLED` for system mail.
4. **Audit** — Log which events fired and which channels succeeded (especially push).

## Related

- [notification-orchestration.md](notification-orchestration.md) — Architecture and implementation details.
- [email-notifications.md](email-notifications.md) — Mailables and email safety gates.

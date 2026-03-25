# Notification orchestration (in-app, email, push)

## Purpose

A **small, config-driven layer** fans out product events to delivery channels without replacing existing systems:

| System | Role |
|--------|------|
| **`NotificationGroupService`** | In-app grouped feed (custom `App\Models\Notification` model — not Laravel’s notification channel). |
| **Mailables + `BaseMailable` / `EmailGate`** | Product email (see [email-notifications.md](email-notifications.md)). |
| **Orchestration** | Reads `config/notifications.php`, then calls channel classes for `in_app`, `email`, and `push`. |

Orchestration is **additive** — new events should go through `NotificationOrchestrator` when you want unified multi-channel behavior; existing direct Mailable calls stay as-is.

## Entry point

- **`App\Services\NotificationOrchestrator`** — `dispatch(string $event, array $payload): void`
  - No-op if `NOTIFICATIONS_ENABLED` is false.
  - Resolves `config('notifications.events.{event}')` and invokes each listed channel handler.

Channels implement **`App\Services\Notifications\Contracts\NotificationChannel`** (`send(string $event, array $payload): void`).

## Configuration

**File:** `config/notifications.php`

- **`enabled`** — `NOTIFICATIONS_ENABLED` (default `false`). Master switch for orchestration.
- **`events`** — Map of event keys to `{ channels: ['in_app', 'email', 'push'] }`.

Registered in **`AppServiceProvider`**: `NotificationOrchestrator` is bound with a channel map (`in_app` → `InAppChannel`, `email` → `EmailChannel`, `push` → `PushChannel`).

## Channels

| Channel | Class | Behavior |
|---------|--------|----------|
| `in_app` | `InAppChannel` | Calls `NotificationGroupService::upsert()` per user in `user_ids`. Respects `FeatureGate::notificationsEnabled($tenant)` when `tenant_id` is present. |
| `email` | `EmailChannel` | Stub — reserved for future mapping to Mailables per event. |
| `push` | `PushChannel` | OneSignal REST API only; **all** HTTP and keys live here. Gated by `NOTIFICATIONS_ENABLED`, `PUSH_NOTIFICATIONS_ENABLED`, and `config('services.onesignal.*')`. |

## Push (OneSignal)

- **Server:** `POST https://api.onesignal.com/notifications` with `Authorization: Key {ONESIGNAL_REST_API_KEY}`.
- **Config:** `config/services.php` → `onesignal.app_id`, `onesignal.rest_api_key`.
- **Targeting:** `include_aliases.external_id` must match **`OneSignal.login('user_{id}')`** on the client (see `onesignal:test-push` and `PushTestController`).
- **Client:** `resources/views/app.blade.php` loads the Web SDK v16 script when `PUSH_NOTIFICATIONS_ENABLED` is true; **`resources/js/services/pushService.js`** runs a single `OneSignal.init`, one-time permission after login (`users.push_prompted_at`), and `POST /app/api/user/push-status` for device opt-in/out. **`resources/js/Components/PushServiceInit.jsx`** mounts from `app.jsx`. Profile UI: **`NotificationPreferences`** master “Push notifications” toggle.

## Environment variables

| Variable | Default | Meaning |
|----------|---------|---------|
| `NOTIFICATIONS_ENABLED` | `false` | Master switch for `NotificationOrchestrator`. |
| `PUSH_NOTIFICATIONS_ENABLED` | `false` | Allows `PushChannel` to call OneSignal (client init also uses this for `client_enabled`). |
| `ONESIGNAL_APP_ID` | — | OneSignal app id. |
| `ONESIGNAL_REST_API_KEY` | — | REST API key (server only). |
| `VITE_ONESIGNAL_APP_ID` | — | Optional; overrides meta tag for the web SDK app id in `pushService.js`. |
| `PUSH_TEST_ROUTE_ENABLED` | `false` | When true, allows `GET /test-push` outside `local` (smoke test; keep off in production). |

See `.env.example` for commented entries.

## Payload conventions

Callers should pass a consistent shape so all channels can use it:

- **`user_ids`** — array of user ids (int or string; push uses string external ids).
- **`title`**, **`message`** — Display strings.
- **`tenant_id`**, **`tenant_name`**, **`brand_id`**, **`brand_name`** — Context for in-app and future prefs.
- **`asset_id`**, **`action_url`** — Optional deep links.

## Example: generative publish

`EditorAssetBridgeController` dispatches `generative.published` after a successful upload finalize. See [admin-notification-routing.md](admin-notification-routing.md) for the event catalog.

## Future work (not implemented)

- Email + push unified preference matrix; per-category quiet hours; multi-device subscription management in the UI.
- Tenant-level overrides (admin UI or plan gates).
- **Admin UI** — see [admin-notification-routing.md](admin-notification-routing.md) for the reference table that will drive configuration.

## Related

- [email-notifications.md](email-notifications.md) — Mailables, `EmailGate`, `MAIL_AUTOMATIONS_ENABLED`.
- [admin-notification-routing.md](admin-notification-routing.md) — Event/channel matrix for operators and future admin UI.

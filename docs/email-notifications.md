# Email notifications and automation system

## Related systems

- **Mailables (this document)** — Direct user and system email via `BaseMailable` and `EmailGate`.
- **Notification orchestration** — Separate layer for in-app + push (and a future orchestrated email channel). See [notification-orchestration.md](notification-orchestration.md) and the operator-facing event matrix [admin-notification-routing.md](admin-notification-routing.md).

## Overview

Outgoing mail is classified into two categories:

1. **User-initiated** — Triggered by an explicit user action in the product (invite teammate, share download link, password reset, etc.). These messages are **always allowed** so staging and QA can exercise real flows through Mailtrap without extra toggles.

2. **System / automated** — Triggered by queues, schedules, or background rules (billing reminders, future nudges, marketing, AI suggestions, etc.). These are **blocked by default** in non-production-like environments when `MAIL_AUTOMATIONS_ENABLED=false`, so automated mail cannot accidentally reach Mailtrap or real inboxes during staging.

The gate exists to:

- Keep **staging safe** when using a shared Mailtrap inbox or real SMTP credentials.
- Make **automation opt-in** via a single env flag until product and compliance are ready.
- Give a clear place to extend (per-tenant preferences, unsubscribe, audit logs).

## Email types

### User-initiated

Examples:

- Login / session-related (if added)
- Password reset
- Share / download link emails
- Team invites, collection invites
- Ownership transfer flow messages
- Admin-triggered account notices (explicit action)

Implement as `protected string $emailType = 'user';` (or rely on the default on `BaseMailable`).

### System / automated

Examples (current or planned):

- Billing / trial / comped expiry and warnings (scheduled commands)
- Plan change notifications driven by billing automation
- Asset pending approval notifications (queued listener)
- Future: inactivity reminders, digests, marketing, AI-driven suggestions

Implement as:

```php
protected string $emailType = 'system';
```

## Environment controls

| Variable | Default | Meaning |
|----------|---------|---------|
| `MAIL_AUTOMATIONS_ENABLED` | `false` | When `false`, mailables with `emailType === 'system'` are not sent (early exit + log line). When `true`, system emails are allowed. |

Configured in `config/mail.php` as `mail.automations_enabled` (boolean).

## Rules

1. **All application mailables MUST extend `App\Mail\BaseMailable`.** Do not extend `Illuminate\Mail\Mailable` directly.
2. **Every new system/automated email MUST set** `protected string $emailType = 'system';`.
3. **Do not change mail drivers** for gating — use `BaseMailable` + `EmailGate` only.

## Implementation

- `App\Services\EmailGate` — `canSend('user'|'system')`.
- `App\Mail\BaseMailable` — default `emailType = 'user'`; overrides `send()` to respect the gate and log blocked system mail.

## Safety design

- Prevents accidental bulk or scheduled mail in staging while preserving user-driven tests.
- Future automation must opt in via `MAIL_AUTOMATIONS_ENABLED=true` (or explicit env per environment).

## Testing notes

`Mail::fake()` records mailable instances **without** calling `Mailable::send()`, so the `BaseMailable` / `EmailGate` logic in `send()` does not run under the fake. To assert that a system email was blocked or delivered, use the **`array` mail driver** (see `tests/Feature/Mail/EmailGateTest.php`) or call `shouldSend()` / `EmailGate::canSend()` directly in unit tests.

## Future expansion

- Per-tenant email preferences and quiet hours
- Unsubscribe / category preferences
- Notification settings UI
- Email audit log (who/when/template id)
- Provider webhooks (bounces, complaints)

## Notification templates (database)

Transactional HTML for password reset, invites, billing, etc. lives in `notification_templates` and is seeded by `NotificationTemplateSeeder`. Layouts use:

- **System** — `App\Support\TransactionalEmailHtml::systemShell()` — dark cinematic header strip, `{{app_url}}/icons/jp-wordmark-email@2x.png` (also used by the `<x-email.header>` Blade component), Stripe-like card on `#f4f5f7`, footer outside the card.
- **Tenant invite** — `tenantShell()` — same aesthetic plus optional `{{tenant_logo_block}}` (divider + default brand logo via `Brand::logoUrlForGuest()`).

After updating the seeder, refresh existing environments:

```bash
php artisan email:templates:refresh
```

Equivalent:

```bash
php artisan db:seed --class=Database\\Seeders\\NotificationTemplateSeeder
```

## Examples

**User-initiated (share link):**

```php
class ShareDownloadMail extends BaseMailable
{
    protected string $emailType = 'user';

    // ...
}
```

**System (future reminder):**

```php
class ReminderEmail extends BaseMailable
{
    protected string $emailType = 'system';

    // ...
}
```

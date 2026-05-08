# Demo workspaces

Jackpot supports **disposable demo tenants** so prospects can upload, download, and explore a realistic workspace without touching production customer data.

## Templates vs instances

- **Demo template** — A tenant marked `is_demo_template`. Intended as the long-lived “golden” workspace used to produce demo content (and, in a future phase, as the source for cloning).
- **Demo instance** — A tenant marked `is_demo` (and usually `demo_template_id` pointing at the template). This is what a prospect uses. It should expire after a configured window (for example 7 or 14 days) and be **reset or deleted** by cleanup jobs later.

Demo instances are **not** production tenants. A prospect who later becomes a customer should receive a **new** normal tenant through standard signup, not by “promoting” the demo row.

## Storage and cleanup

Uploaded files for a demo instance stay under that tenant’s **normal storage prefix** (same as any tenant). When cleanup runs (future phase), deleting or resetting the demo tenant should remove tenant-scoped rows and rely on existing asset/storage deletion paths. **Phase 1 does not implement S3 cleanup or scheduled deletion.**

## Phase 2A — Template audit (no cloning)

Before implementing cloning, run a **read-only audit** on any tenant with `is_demo_template = true`:

- **Artisan:** `php artisan demo:audit-template {id_or_slug} [--json]`
- **Admin UI:** Demo workspaces → **Audit template** on a template row (counts, exclusions, warnings, storage pointers).

The audit summarizes clone-ready rows (company, brands, assets, metadata, collections, executions, DNA, etc.), explicitly **excludes** billing/subscriptions, AI usage logs, activity/audit events, notifications, invites, tickets, downloads/share links, and flags **S3/object storage paths** (copy phase is still future work). It does **not** modify data.

## Product rules (Phase 1)

- Demo and template tenants are identified in the database; model helpers and `DemoTenantService` / `DemoGuard` centralize behavior.
- Certain actions are blocked in demo/template workspaces with the message: “This action is disabled in demo workspaces.” (billing changes, Stripe checkout/portal, team/brand invites, ownership transfer, permanent company delete from settings, etc.)
- Uploads and downloads are **not** blocked in Phase 1.
- **Cloning** from template to instance is **not** implemented yet (`config/demo.php` → `cloning_enabled`).

## Future phases (roadmap)

| Phase | Scope |
|--------|--------|
| **Cloning** | Create demo instances from a template tenant (data copy with safeguards). |
| **Sales admin** | Self-service “create demo” UI for sales; tie into `demo_created_by_user_id`, labels, and plan keys. |
| **Cleanup** | Scheduled job to delete or reset expired demos when `cleanup_enabled` is true. |
| **Optional promotion / migration** | If ever required, explicit export + import into a new production tenant — not an in-place flip of `is_demo`. |

Configuration defaults live in `config/demo.php` (`default_expiration_days`, `allowed_expiration_days`, `default_plan_key`, feature flags).

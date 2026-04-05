# Collection access model

Collections support three **access modes** (stored on `collections.access_mode`):

1. **`all_brand`** — Any active member of the brand can view the collection. Legacy `visibility = brand` maps here.
2. **`role_limited`** — Brand members whose **brand role** is listed in `collections.allowed_brand_roles` can view. The creator always can. Users listed in `collection_members` (accepted or pending) are treated as explicit exceptions.
3. **`invite_only`** — Brand members can view if they are the creator, have an **accepted** `collection_members` row, **or** their brand role appears in `allowed_brand_roles` (optional allow-list without a separate invite).

The legacy `visibility` column is kept in sync on save for older code paths: `all_brand` → `brand`, `role_limited` → `restricted`, `invite_only` → `private`.

## External guests (C12)

When **`allows_external_guests`** is true, company admins (tenant owner/admin with company brand management) and **brand admin** or **brand_manager** on that brand may invite people **by email** who are not on the brand. Those users receive a **collection-only** workspace: they can open collections they were invited to, but not the full brand library, dashboard, or other assets.

- **Plan:** Sending new external invites requires **Premium** or **Enterprise** (`external_collection_guests_enabled` in `config/plans.php`). Downgrades do not remove existing grants; new invites are blocked.
- **API:** `POST /app/collections/{id}/access-invite` (email), list/revoke via existing access-invite routes.

## Internal teammates (C7)

`POST /app/collections/{id}/invite` with `user_id` adds a `collection_members` row for users who are already on the **tenant and brand**. The edit collection UI loads options from `GET /app/collections/{id}/internal-invite-data`. Removing a row uses `DELETE /app/collections/{id}/members/{member}`.

## UI signals

The collections sidebar shows a **user group** icon when `allows_external_guests` is enabled for that collection.

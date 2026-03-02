# "No brand access" Message — Source and Constraints

## Where it comes from

### 1. Backend: `HandleInertiaRequests.php`

**Line 427–428** — sets `auth.no_brand_access` in shared Inertia props:

```php
'no_brand_access' => $tenant && $user && ! (app()->bound('collection_only') && app('collection_only')) && (is_array($brands) && count($brands) === 0),
```

**Conditions for `no_brand_access = true`:**
- `$tenant` exists (user is in a company)
- `$user` exists (user is logged in)
- NOT in collection-only mode
- `$brands` is empty (array with 0 items)

### 2. Frontend: `AppNav.jsx`

**Line 129** — when to show the alert:

```js
const showNoBrandAccessAlert = Boolean(
  auth?.no_brand_access ?? 
  (auth?.activeCompany && !collectionOnly && (!auth?.brands || auth.brands.length === 0))
);
```

**Alert shows when either:**
- Backend sets `auth.no_brand_access === true`, OR
- Fallback: `auth.activeCompany` exists, not collection-only, and `auth.brands` is missing or empty

---

## Why `$brands` ends up empty

`$brands` is built in `HandleInertiaRequests` (lines 145–265).

### Path A: Exception in the try block

If any exception occurs (e.g. `PlanService`, `getPlanLimits`, `$tenant->brands()`, etc.):

- The catch block sets `$brands = []`
- `no_brand_access` becomes true
- Error is logged: `[HandleInertiaRequests] Failed to load brands for tenant`

### Path B: No accessible brands after filtering

Brands are filtered by user access (lines 182–204):

1. **Tenant owners/admins** — see all brands (no filtering)
2. **Regular members** — only brands where they have an active membership

**Active membership** (`User::activeBrandMembership`):

- `brand_user` row exists for that user + brand
- `removed_at IS NULL`
- Role is valid per `RoleRegistry::isValidBrandRole()`

If a regular member has no such rows, `$userBrandIds` is empty and `$accessibleBrands` is empty → `$brands` ends up empty.

---

## Summary of constraints

| Constraint | Effect |
|-----------|--------|
| Exception during brand loading | `$brands = []` → `no_brand_access` |
| Regular member with no `brand_user` rows | No brands pass filter → `no_brand_access` |
| Regular member with `removed_at` set on all brands | No active memberships → `no_brand_access` |
| Invalid brand role in `brand_user` | `activeBrandMembership` returns null → brand excluded |
| Collection-only mode | `no_brand_access` is never set (different flow) |

---

## Fixing "No brand access" for real users

1. **Check logs** — `storage/logs/laravel.log` for `[HandleInertiaRequests] Failed to load brands`
2. **Add tenant owners/admins to brands** — `sail artisan brand:fix-tenant-leadership-access --user-email=... --tenant-id=...`
3. **Diagnose a user** — `sail artisan brand:diagnose-user-access {email}`

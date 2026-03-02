# Eager Loading & Lazy Load Prevention Rules

Lazy loading is globally disabled in this application. All code must follow these rules to prevent `LazyLoadingViolationException` and N+1 query regressions.

## Core Rules

1. **Never access relations inside loops** unless they are eager loaded at query time.

2. **Prefer eager loading at query boundaries:**
   ```php
   // Good
   Model::query()->with(['relation1', 'relation2'])->get();

   // Avoid
   $collection->load(['relation1']);  // Post-resolution loading
   ```

3. **Methods that depend on relations** should either:
   - Use scalar attributes (e.g. `$model->tenant_id`) when possible, OR
   - Guard with `relationLoaded()` and throw if not:
   ```php
   if (! $model->relationLoaded('relation')) {
       throw new \LogicException(
           'MethodName requires model->relation to be eager loaded. Use Model::with(\'relation\')->get() at query time.'
       );
   }
   ```

4. **Add eager loading at query boundaries**, not inside business logic. When iterating a collection, all relations used in the loop body must be loaded before the loop.

5. **Never call `$collection->load()`** in production logic as a primary strategy. Use `->with()` at query time instead.

## Example: Brand Membership

`User::activeBrandMembership(Brand $brand)` uses `$brand->tenant_id` (scalar) to avoid needing the `tenant` relation. If it required `$brand->tenant`, callers would need to pass brands from:

```php
$tenant->brands()->with('tenant')->orderBy(...)->get();
```

## Query Count Guidelines

When iterating and calling relation-dependent methods:

- Use `DB::enableQueryLog()` in tests to assert total queries ≤ expected.
- One query for the main collection + one per eager-loaded relation batch is acceptable.
- N+1 = one query per iteration is not acceptable.

## See Also

- `HandleInertiaRequests`: brand loading for nav
- `User::activeBrandMembership()`: uses `tenant_id` only

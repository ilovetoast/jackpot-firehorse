# Asset Recovery — Quick Reference

## ZIP stuck with orange dot (processing forever)

```bash
# List stuck ZIPs
php artisan assets:fix-stuck-zip --dry-run

# Fix them (completes immediately, dispatches FinalizeAssetJob)
php artisan assets:fix-stuck-zip
```

**After running:** Queue workers must process the dispatched FinalizeAssetJob. If using Sail: `sail artisan queue:work` or your normal worker process.

---

## Asset disappeared from grid (null category_id)

```bash
# List affected assets (no changes)
php artisan assets:recover-category-id --dry-run

# Assign category to recover (use your primary category ID, e.g. Logos)
php artisan assets:recover-category-id --category=5
```

---

## After deploying fixes

1. **Restart queue workers** — New ProcessAssetJob code (ZIP short-circuit) loads at worker start
2. **Run `php artisan config:clear`** — If env vars changed

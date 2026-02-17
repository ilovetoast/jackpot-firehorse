# Staging: manifest.webmanifest 401 Fix

## Problem

The PWA manifest at `/manifest.webmanifest` returns **401 Unauthorized** on staging, causing console errors and PWA install failures.

## Causes

1. **HTTP Basic Auth** – Staging often uses Basic Auth to restrict access. The manifest fetch does not include credentials, so it gets 401.
2. **Route not deployed** – The Laravel route for manifest may not be in the deployed code.
3. **Route cache** – Cached routes may not include the manifest route.

## Fixes

### 1. Nginx: Serve manifest as static file (bypass auth)

Add this **before** your main `location /` block:

```nginx
location = /manifest.webmanifest {
    auth_basic off;
    add_header Content-Type application/manifest+json;
    try_files $uri =404;
}
```

This serves the file from `public/manifest.webmanifest` directly, without PHP or auth.

### 2. Laravel route (already in codebase)

`routes/web.php` defines a public route for `/manifest.webmanifest`. Ensure:

- Code is deployed
- Run `php artisan route:clear` and `php artisan optimize` after deploy

### 3. Verify deployment

```bash
# On staging server
ls -la /path/to/app/public/manifest.webmanifest   # File must exist
php artisan route:list | grep manifest            # Route should appear
```

## Testing

After applying the fix, open in an incognito window (no auth):

```
https://staging-jackpot.velvetysoft.com/manifest.webmanifest
```

Should return 200 with JSON content, not 401.

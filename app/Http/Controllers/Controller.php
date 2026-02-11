<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Redirect to the intended URL (e.g. after login), but never to an API path.
     * API routes return JSON; an Inertia full-page visit would get "plain JSON response" error.
     */
    protected function redirectToIntendedApp(string $default): RedirectResponse
    {
        $intended = session()->pull('url.intended', $default);
        $path = parse_url($intended, PHP_URL_PATH) ?? '';
        if ($path !== '' && str_starts_with($path, '/app/api')) {
            $intended = $default;
        }
        return redirect()->to($intended);
    }

    /**
     * STARRED CANONICAL (reading): Normalize any legacy/API value to boolean for "is starred?".
     * Write path: we always store strict boolean in assets.metadata.starred (see syncSortFieldToAsset).
     * Read path: when reading from DB/JSON we may still see true|'true'|1|'1' until backfilled.
     */
    protected function assetIsStarred(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}

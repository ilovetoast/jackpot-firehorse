<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Workspace switch from fetch/XHR (Accept: application/json) must not return HTTP redirects:
     * fetch follows 302 and loads the page once, then the client does window.location — double load.
     * Inertia requests send X-Inertia and still use redirect/back for SPA visits.
     */
    protected function shouldReturnJsonForWorkspaceSwitch(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    /**
     * Redirect to the intended URL (e.g. after login), but never to an API path.
     * API routes return JSON; an Inertia full-page visit would get "plain JSON response" error.
     */
    /**
     * Resolve post-login / switch target URL without issuing a redirect (same rules as {@see redirectToIntendedApp}).
     */
    protected function peekIntendedAppUrl(string $default): string
    {
        $intended = session()->pull('url.intended', $default);
        $path = parse_url($intended, PHP_URL_PATH) ?? '';
        if ($path !== '' && str_starts_with($path, '/app/api')) {
            return $default;
        }

        return $intended;
    }

    protected function redirectToIntendedApp(string $default): RedirectResponse
    {
        return redirect()->to($this->peekIntendedAppUrl($default));
    }

    /**
     * Post-login handoff for collection-only email invites ({@see CollectionAccessInviteController}).
     */
    protected static function isCollectionInviteAcceptUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return $path !== '' && str_starts_with($path, '/invite/collection/');
    }

    /**
     * STARRED CANONICAL (reading): Normalize any legacy/API value to boolean for "is starred?".
     * Write path: we always store strict boolean in assets.metadata.starred (see syncSortFieldToAsset).
     * Read path: when reading from DB/JSON we may still see true|'true'|1|'1' until backfilled.
     */
    protected function assetIsStarred(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['true', '1', 'yes'], true);
        }
        return false;
    }
}

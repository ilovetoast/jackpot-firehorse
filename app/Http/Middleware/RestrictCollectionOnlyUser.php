<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase C12.0: When user is in collection-only mode, allow only specific routes.
 * All other routes redirect to the collection landing.
 */
class RestrictCollectionOnlyUser
{
    /**
     * Routes that collection-only users may access.
     *
     * @var list<string>
     */
    protected array $allowedRouteNames = [
        'collection-invite.landing',
        'collection-invite.view',
        'collection-invite.switch',
        'assets.view',
        'assets.download',
        'downloads.index',
        'downloads.store',
        'downloads.download',
        'api.downloads.poll',
        'api.notifications.index',
        'api.notifications.read',
        'download-bucket.items',
        'download-bucket.add',
        'download-bucket.add_batch',
        'download-bucket.remove',
        'download-bucket.clear',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('collection_only') || ! app('collection_only')) {
            return $next($request);
        }

        $collection = app('collection');
        if (! $collection) {
            return $next($request);
        }

        if ($request->routeIs($this->allowedRouteNames)) {
            return $next($request);
        }

        return redirect()->route('collection-invite.landing', ['collection' => $collection->id]);
    }
}

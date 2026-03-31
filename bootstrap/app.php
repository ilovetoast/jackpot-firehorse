<?php

use App\Exceptions\AIProviderException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [
            'CloudFront-Policy',
            'CloudFront-Signature',
            'CloudFront-Key-Pair-Id',
        ]);

        $middleware->web(prepend: [
            \App\Http\Middleware\ResponseTimingMiddleware::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureCloudFrontSignedCookies::class,
            \App\Http\Middleware\EnsureGatewayEntry::class,
        ]);

        $middleware->alias([
            'log.cloudfront.403' => \App\Http\Middleware\LogCloudFront403::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'tenant.cdn.cookie' => \App\Http\Middleware\EnsureCloudFrontSignedCookies::class, // TenantCdnCookieMiddleware
            'subdomain' => \App\Http\Middleware\ResolveSubdomainTenant::class,
            'ensure.brand.assignment' => \App\Http\Middleware\EnsureBrandAssignment::class,
            'ensure.account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            'ensure.user.within.plan.limit' => \App\Http\Middleware\EnsureUserWithinPlanLimit::class,
            'restrict.collection.only' => \App\Http\Middleware\RestrictCollectionOnlyUser::class,
            'collect.asset_url_metrics' => \App\Http\Middleware\CollectAssetUrlMetrics::class,
            // Must be registered here (not only AppServiceProvider::alias): the router resolves
            // middleware strings via this map; route:cache may still reference this name.
            'incubation.not_locked' => \App\Http\Middleware\EnsureIncubationWorkspaceNotLocked::class,
        ]);

        $middleware->redirectUsersTo('/');

        // Exclude Stripe webhook from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        // /d/* 404 (download not found): use shared branding resolver so error pages never silently fall back to unbranded layouts.
        // Same resolver as active/expired/revoked/403—intentional design for consistent branding across all download states.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('d/*') || $request->expectsJson()) {
                return null;
            }
            $resolver = app(\App\Services\DownloadPublicPageBrandingResolver::class);
            $branding = $resolver->resolve(null, 'This link is invalid or has been removed.');

            return Inertia::render('Downloads/Public', array_merge([
                'state' => 'not_found',
                'message' => 'This link is invalid or has been removed.',
                'password_required' => false,
                'download_id' => null,
                'unlock_url' => '',
                'cdn_domain' => config('cloudfront.domain'),
            ], $branding))->toResponse($request)->setStatusCode(404);
        });

        // Inertia app: avoid full-page 403 — send users to the asset grid with a toast instead of "Access denied".
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }
            if ($request->expectsJson()) {
                return null;
            }
            if (! $request->is('app/*')) {
                return null;
            }
            if ($request->routeIs(['assets.index', 'assets.staged', 'assets.processing'])) {
                return null;
            }

            $msg = (string) $e->getMessage();
            if ($msg === '' || str_contains($msg, 'This action is unauthorized')) {
                $msg = 'You don\'t have permission to use that page.';
            }

            return redirect()->route('assets.index')->with('warning', $msg);
        });

        // AI provider errors: safe message for clients; exception message remains internal for Sentry/logs.
        $exceptions->render(function (AIProviderException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->header('X-Inertia')) {
                return null;
            }

            return new JsonResponse([
                'message' => $e->getPublicMessage(),
                'code' => $e->getStatusCode(),
            ], $e->getStatusCode());
        });

        // Inertia SPA: JSON error payload so the client can show a modal instead of a full-page HTML exception.
        $exceptions->render(function (\Throwable $e, Request $request) {
            return \App\Exceptions\Handler::renderJsonForInertia($e, $request);
        });
    })->create();

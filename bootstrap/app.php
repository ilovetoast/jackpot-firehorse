<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'subdomain' => \App\Http\Middleware\ResolveSubdomainTenant::class,
            'ensure.brand.assignment' => \App\Http\Middleware\EnsureBrandAssignment::class,
            'ensure.account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            'ensure.user.within.plan.limit' => \App\Http\Middleware\EnsureUserWithinPlanLimit::class,
            'restrict.collection.only' => \App\Http\Middleware\RestrictCollectionOnlyUser::class,
        ]);

        $middleware->redirectUsersTo('/');

        // Exclude Stripe webhook from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Graceful degradation: /d/* 404 (download not found) → HTML "Jackpot access denied" page, not JSON
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('d/*') || $request->expectsJson()) {
                return null;
            }
            $appName = config('app.name', 'Jackpot');
            return Inertia::render('Downloads/Public', [
                'state' => 'not_found',
                'message' => 'This link is invalid or has been removed.',
                'password_required' => false,
                'download_id' => null,
                'unlock_url' => '',
                'branding_options' => [
                    'headline' => $appName,
                    'subtext' => 'This link is invalid or has been removed.',
                ],
            ])->toResponse($request)->setStatusCode(404);
        });
    })->create();

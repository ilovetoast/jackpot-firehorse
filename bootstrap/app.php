<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        ]);

        $middleware->redirectUsersTo('/');

        // Exclude Stripe webhook from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/stripe',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

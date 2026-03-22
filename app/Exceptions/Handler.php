<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Exception rendering helpers. Primary registration lives in bootstrap/app.php.
 */
class Handler
{
    /**
     * Return JSON for Inertia visits so the SPA can show a global modal instead of a full HTML error page.
     * Validation, auth redirect, CSRF, and route-specific renders return null to preserve default behavior.
     */
    public static function renderJsonForInertia(Throwable $e, Request $request): ?Response
    {
        if (! $request->header('X-Inertia')) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return null;
        }

        if ($e instanceof AuthenticationException) {
            return null;
        }

        if ($e instanceof TokenMismatchException) {
            return null;
        }

        // Branded download 404 — handled by bootstrap callback.
        if ($e instanceof ModelNotFoundException && $request->is('d/*')) {
            return null;
        }

        // App 403 → redirect with flash (bootstrap callback).
        if ($e instanceof HttpExceptionInterface
            && $e->getStatusCode() === 403
            && $request->is('app/*')
            && ! $request->expectsJson()) {
            return null;
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        // Never expose container wiring (missing classes, bad bindings) in the Inertia SPA — not actionable for users.
        if ($e instanceof BindingResolutionException) {
            $message = 'Something went wrong.';
        } else {
            $message = config('app.debug')
                ? (string) ($e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong.')
                : 'Something went wrong.';
        }

        return new JsonResponse(['message' => $message, 'code' => $status], $status);
    }
}

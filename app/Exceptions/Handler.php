<?php

namespace App\Exceptions;

use App\Models\Asset;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

        // Missing or soft-deleted asset: never leak model/UUID details to the browser (even when APP_DEBUG is true).
        if ($e instanceof ModelNotFoundException && $request->is('app/*')) {
            $modelClass = $e->getModel();
            if ($modelClass === Asset::class) {
                return new JsonResponse([
                    'message' => self::friendlyAssetMissingMessage($request, $e),
                    'code' => 404,
                ], 404);
            }
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        $message = 'Something went wrong.';
        if ($e instanceof AIProviderException) {
            $message = $e->getPublicMessage();
            $status = $e->getStatusCode();
        } elseif ($e instanceof BindingResolutionException) {
            // Container wiring — never leak class names to the browser.
            $message = 'Something went wrong.';
        } elseif ($e instanceof InvalidArgumentException) {
            $raw = (string) $e->getMessage();
            // Laravel MailManager: undefined mailer name (misconfigured MAIL_MAILER).
            if (str_contains($raw, 'Mailer [') && str_contains($raw, 'is not defined')) {
                $message = 'Email could not be sent. Please try again in a few minutes.';
            } elseif (config('app.debug') && $raw !== '') {
                $message = $raw;
            }
        } elseif (config('app.debug')) {
            $message = (string) ($e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong.');
        }

        return new JsonResponse(['message' => $message, 'code' => $status], $status);
    }

    /**
     * User-facing copy when route model binding cannot resolve an asset (missing id or soft-deleted).
     */
    protected static function friendlyAssetMissingMessage(Request $request, ModelNotFoundException $e): string
    {
        $ids = $e->getIds();
        $assetId = $ids[0] ?? null;
        if ($assetId && app()->bound('tenant')) {
            $tenant = app('tenant');
            if (Asset::onlyTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('id', $assetId)
                ->exists()) {
                return 'This asset has been deleted.';
            }
        }

        return 'This asset could not be found or is no longer available.';
    }
}

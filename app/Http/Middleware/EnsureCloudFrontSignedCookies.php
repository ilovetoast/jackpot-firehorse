<?php

namespace App\Http\Middleware;

use App\Services\CloudFrontSignedCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCloudFrontSignedCookies
{
    public function __construct(
        protected CloudFrontSignedCookieService $cookieService
    ) {}

    /**
     * Handle an incoming request.
     *
     * If user is authenticated and CloudFront signing is enabled (non-local),
     * ensure signed cookies are set. Regenerate if missing or near expiry.
     */
    public function handle(Request $request, Closure $next): Response
    {
        \Log::info('[CDN_COOKIE] middleware running', [
            'user_id' => auth()->id(),
            'env' => app()->environment(),
        ]);

        $response = $next($request);

        // Local environment: skip signing per task requirements
        if (app()->environment('local')) {
            return $response;
        }

        // Only set cookies for authenticated users
        if (! $request->user()) {
            return $response;
        }

        // Skip if CloudFront not configured
        if (empty(config('cloudfront.domain')) || empty(config('cloudfront.key_pair_id'))) {
            return $response;
        }

        if ($this->shouldRegenerateCookies($request)) {
            $this->attachCookiesToResponse($response);
        }

        return $response;
    }

    /**
     * Determine if we need to regenerate signed cookies.
     */
    protected function shouldRegenerateCookies(Request $request): bool
    {
        $policy = $request->cookie('CloudFront-Policy');
        if (! $policy) {
            return true;
        }

        $threshold = config('cloudfront.refresh_threshold', 600);
        if ($threshold <= 0) {
            return false;
        }

        $expiresAt = $this->getPolicyExpiry($policy);
        if ($expiresAt === null) {
            return true;
        }

        return $expiresAt < (time() + $threshold);
    }

    /**
     * Extract expiry timestamp from CloudFront-Policy cookie (URL-safe base64 JSON).
     */
    protected function getPolicyExpiry(?string $policy): ?int
    {
        if (! $policy) {
            return null;
        }

        $decoded = str_replace(['-', '_', '~'], ['+', '=', '/'], $policy);
        $json = base64_decode($decoded, true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        $epoch = $data['Statement'][0]['Condition']['DateLessThan']['AWS:EpochTime'] ?? null;

        return is_numeric($epoch) ? (int) $epoch : null;
    }

    /**
     * Attach CloudFront signed cookies to the response.
     */
    protected function attachCookiesToResponse(Response $response): void
    {
        try {
            $cookies = $this->cookieService->generate();
        } catch (\Throwable $e) {
            report($e);
            return;
        }

        $expirySeconds = $this->cookieService->getExpirySeconds();
        $expires = time() + $expirySeconds;
        // For CloudFront, cookies must be set for the CDN domain so the browser sends them on CloudFront requests.
        // Default to CloudFront domain when cookie_domain is null.
        $domain = config('cloudfront.cookie_domain') ?? config('cloudfront.domain');
        $path = '/';

        foreach ($cookies as $name => $value) {
            $cookie = cookie(
                name: $name,
                value: $value,
                minutes: (int) ceil($expirySeconds / 60),
                path: $path,
                domain: $domain,
                secure: true,
                httpOnly: true,
                raw: false,
                sameSite: 'none'
            );
            $response->headers->setCookie($cookie);
        }
    }
}

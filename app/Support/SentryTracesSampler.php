<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Tracing\SamplingContext;

/**
 * Dynamic trace sampling for Sentry performance. Keeps uploads, assets, and processing
 * fully sampled while lowering volume elsewhere (see config/sentry.php).
 *
 * Registered from {@see \App\Providers\AppServiceProvider::register} so
 * {@see config} can be cached with {@code php artisan config:cache} (closures in config cannot).
 */
final class SentryTracesSampler
{
    public function __invoke(SamplingContext $context): float
    {
        $transaction = $context->getTransactionContext();
        $name = $transaction !== null ? strtolower($transaction->getName()) : '';

        // Drop trivial / ops-only HTTP and high-frequency polling (no performance value, high volume)
        if (str_contains($name, 'health')
            || str_contains($name, 'horizon')
            || str_contains($name, 'telescope')
            || str_contains($name, 'poll')
            || str_contains($name, 'heartbeat')) {
            return 0.0;
        }

        // Always sample critical product flows (uploads, DAM assets, pipeline jobs, queue workers)
        if (str_contains($name, 'upload')
            || str_contains($name, 'asset')
            || str_contains($name, 'process')
            || str_contains($name, 'queue')) {
            return 1.0;
        }

        // API traffic: moderate sampling (still useful, less than 100%)
        if (str_contains($name, 'api')) {
            return 0.2;
        }

        // Default: low sampling (~70–80% fewer traces vs a flat 1.0 rate)
        return 0.05;
    }
}

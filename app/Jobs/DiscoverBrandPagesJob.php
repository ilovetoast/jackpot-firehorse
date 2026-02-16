<?php

namespace App\Jobs;

use App\Models\BrandBootstrapRun;
use App\Services\BrandDNA\BrandBootstrapOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 2: Discover key pages from homepage navigation.
 */
class DiscoverBrandPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $runId;

    private const KEYWORDS = ['about', 'story', 'mission', 'brand', 'company', 'products', 'shop', 'collections'];

    private const MAX_PAGES = 3;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(BrandBootstrapOrchestrator $orchestrator): void
    {
        $run = BrandBootstrapRun::with('brand.tenant')->find($this->runId);
        if (! $run) {
            return;
        }

        if (! $this->validateTenant($run)) {
            return;
        }

        try {
            $run->setStage('discovering_pages', 25);
            $run->appendLog('Stage 2: discovering_pages');

            $homepage = $run->raw_payload['homepage'] ?? [];
            $navLinks = $homepage['navigation']['links'] ?? [];
            $baseUrl = $run->source_url;

            $discovered = $this->discoverPages($navLinks, $baseUrl);

            $raw = $run->raw_payload ?? [];
            $raw['discovered_pages'] = $discovered;
            $run->update(['raw_payload' => $raw]);

            $orchestrator->advanceToNextStage($run);
        } catch (\Throwable $e) {
            Log::warning('[DiscoverBrandPagesJob] Failed', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
            $orchestrator->handleFailure($run, $e->getMessage());
        }
    }

    protected function discoverPages(array $navLinks, string $baseUrl): array
    {
        $baseHost = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $seen = [];
        $discovered = [];

        foreach ($navLinks as $link) {
            $href = $link['href'] ?? '';
            $label = strtolower($link['label'] ?? '');
            $path = strtolower(parse_url($href, PHP_URL_PATH) ?? '');

            $combined = $label . ' ' . $path;
            $matches = false;
            foreach (self::KEYWORDS as $kw) {
                if (str_contains($combined, $kw)) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $baseUrl);
            if (! $resolved || $resolved === $baseUrl) {
                continue;
            }
            if (parse_url($resolved, PHP_URL_HOST) !== $baseHost) {
                continue;
            }
            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;
            $discovered[] = $resolved;
            if (count($discovered) >= self::MAX_PAGES) {
                break;
            }
        }

        return $discovered;
    }

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $path = $base['path'] ?? '/';
        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }
        $dir = rtrim(dirname($path), '/');
        if ($dir === '.' || $dir === '') {
            $dir = '';
        }
        $base = $scheme . '://' . $host . ($dir ?: '') . '/';

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    protected function validateTenant(BrandBootstrapRun $run): bool
    {
        $tenant = $run->brand?->tenant;
        if (! $tenant || $run->brand->tenant_id !== $tenant->id) {
            Log::warning('[DiscoverBrandPagesJob] Tenant mismatch or missing', ['run_id' => $this->runId]);

            return false;
        }

        return true;
    }
}

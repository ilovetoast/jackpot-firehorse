<?php

namespace App\Services\BrandDNA;

use App\Jobs\ProcessBrandBootstrapRunJob;
use App\Models\Brand;
use App\Models\BrandBootstrapRun;
use App\Models\User;

/**
 * Brand Bootstrap Service — foundation for URL-based Brand DNA extraction.
 */
class BrandBootstrapService
{
    /**
     * Create a bootstrap run and dispatch processing job.
     */
    public function createRun(Brand $brand, string $url, ?User $user = null): BrandBootstrapRun
    {
        $normalized = $this->normalizeUrl($url);

        $run = BrandBootstrapRun::create([
            'brand_id' => $brand->id,
            'status' => 'pending',
            'source_url' => $normalized,
            'created_by' => $user?->id,
        ]);

        ProcessBrandBootstrapRunJob::dispatch($run->id);

        return $run;
    }

    /**
     * Mark run as completed.
     */
    public function markCompleted(BrandBootstrapRun $run, array $aiPayload = []): void
    {
        $run->update([
            'status' => 'completed',
            'ai_output_payload' => $aiPayload,
        ]);
    }

    /**
     * Mark run as failed.
     */
    public function markFailed(BrandBootstrapRun $run, array $errorPayload = []): void
    {
        $run->update([
            'status' => 'failed',
            'raw_payload' => $errorPayload,
        ]);
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }
}

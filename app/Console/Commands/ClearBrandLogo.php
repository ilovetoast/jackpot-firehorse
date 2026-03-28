<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Clear a brand's logo when stuck (e.g. broken SVG before support was added).
 * Use when the UI Remove button doesn't persist the change.
 */
class ClearBrandLogo extends Command
{
    protected $signature = 'brands:clear-logo
                            {brand : Brand ID or slug}';

    protected $description = 'Clear a brand\'s logo (for fixing stuck broken images)';

    public function handle(): int
    {
        $identifier = $this->argument('brand');

        $brand = Brand::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $brand) {
            $this->error("Brand not found: {$identifier}");

            return self::FAILURE;
        }

        $brand->update([
            'logo_path' => null,
            'logo_id' => null,
        ]);

        $this->info("Cleared logo_path and logo_id for brand: {$brand->name} ({$brand->slug})");

        return self::SUCCESS;
    }
}

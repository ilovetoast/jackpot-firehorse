<?php

namespace App\Console\Commands;

use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Clear a brand's logo/icon when stuck (e.g. broken SVG before support was added).
 * Use when the UI Remove button doesn't persist the change.
 */
class ClearBrandLogo extends Command
{
    protected $signature = 'brands:clear-logo
                            {brand : Brand ID or slug}
                            {--icon : Clear icon instead of logo}
                            {--both : Clear both logo and icon}';

    protected $description = 'Clear a brand\'s logo or icon (for fixing stuck broken images)';

    public function handle(): int
    {
        $identifier = $this->argument('brand');
        $clearIcon = $this->option('icon');
        $clearBoth = $this->option('both');

        $brand = Brand::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (!$brand) {
            $this->error("Brand not found: {$identifier}");
            return self::FAILURE;
        }

        $updates = [];
        if ($clearBoth || !$clearIcon) {
            $updates['logo_path'] = null;
            $updates['logo_id'] = null;
        }
        if ($clearBoth || $clearIcon) {
            $updates['icon_path'] = null;
            $updates['icon_id'] = null;
        }

        if (empty($updates)) {
            $this->warn('Nothing to clear. Use --icon for icon, or --both for both.');
            return self::FAILURE;
        }

        $brand->update($updates);

        $this->info("Cleared " . implode(' and ', array_keys($updates)) . " for brand: {$brand->name} ({$brand->slug})");
        return self::SUCCESS;
    }
}

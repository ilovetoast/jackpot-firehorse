<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\BrandResearchSnapshot;
use App\Models\Tenant;
use App\Jobs\RunBrandIngestionJob;
use App\Jobs\RunBrandPdfVisionExtractionJob;
use App\Services\BrandDNA\BrandDnaDraftService;
use Illuminate\Console\Command;

/**
 * DEV-ONLY: Run the Brand DNA PDF extraction pipeline for a real brand.
 * Uses actual PDF from guidelines_pdf context. Tests page analysis persistence end-to-end.
 *
 * Usage:
 *   php artisan dev:run-brand-pdf-pipeline --tenant=1 --brand=versa-gripps
 *   php artisan dev:run-brand-pdf-pipeline --brand-id=5
 */
class DevRunBrandPdfPipelineCommand extends Command
{
    protected $signature = 'dev:run-brand-pdf-pipeline
                            {--tenant= : Tenant ID (e.g. 1 for company 1)}
                            {--brand= : Brand slug or name (e.g. versa-gripps)}
                            {--brand-id= : Brand ID (overrides tenant+brand)}
                            {--sync : Run jobs synchronously (no queue worker needed)}';

    protected $description = 'DEV-ONLY: Run PDF visual extraction pipeline for a brand (e.g. Versa Gripps)';

    public function handle(BrandDnaDraftService $draftService): int
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            $this->error('This command only runs in local or testing environments.');
            return Command::FAILURE;
        }

        $brand = $this->resolveBrand();
        if (! $brand) {
            return Command::FAILURE;
        }

        $this->info("Brand: {$brand->name} (ID: {$brand->id})");
        $draft = $draftService->getOrCreateDraftVersion($brand);
        $this->info("Draft: ID {$draft->id}");

        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        if (! $guidelinesPdfAsset) {
            $this->warn('No guidelines PDF attached. Attach a PDF in the Brand Guidelines Builder first.');
            return Command::FAILURE;
        }

        $this->info("PDF Asset: {$guidelinesPdfAsset->title} (ID: {$guidelinesPdfAsset->id})");

        $deleted = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->delete();
        $this->info("Deleted {$deleted} completed snapshot(s).");

        $useSync = $this->option('sync');
        if ($useSync) {
            config(['queue.default' => 'sync']);
        }

        $this->info('Dispatching RunBrandPdfVisionExtractionJob...');
        RunBrandPdfVisionExtractionJob::dispatch(
            (string) $guidelinesPdfAsset->id,
            $brand->id,
            $draft->id
        );

        if ($useSync) {
            $this->info('Jobs ran synchronously. Check logs and Research Summary in the UI.');
            return Command::SUCCESS;
        }

        $this->info('Jobs queued. Run the queue worker to process:');
        $this->line('  ./vendor/bin/sail artisan queue:work --stop-when-empty');
        $this->newLine();
        $this->info('Or run with --sync to execute in-process (slower, but no worker needed).');

        return Command::SUCCESS;
    }

    protected function resolveBrand(): ?Brand
    {
        if ($brandId = $this->option('brand-id')) {
            $brand = Brand::find($brandId);
            if (! $brand) {
                $this->error("Brand ID {$brandId} not found.");
                return null;
            }
            return $brand;
        }

        $tenantId = $this->option('tenant');
        $brandSlug = $this->option('brand');

        if (! $tenantId || ! $brandSlug) {
            $this->error('Provide --tenant=1 --brand=versa-gripps or --brand-id=X');
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant ID {$tenantId} not found.");
            return null;
        }

        $brand = Brand::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($brandSlug) {
                $q->where('slug', $brandSlug)
                    ->orWhere('name', 'like', '%' . $brandSlug . '%');
            })
            ->first();

        if (! $brand) {
            $this->error("Brand '{$brandSlug}' not found for tenant {$tenantId}.");
            return null;
        }

        return $brand;
    }
}

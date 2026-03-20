<?php

namespace App\Console\Commands;

use App\Jobs\BrandPipelineRunnerJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandPipelineRun;
use Illuminate\Console\Command;

class DevRerunBrandAnalysisCommand extends Command
{
    protected $signature = 'dev:rerun-brand-analysis
        {brand? : Brand ID or slug}
        {--sync : Run synchronously instead of dispatching to queue}
        {--from-raw : Re-process from stored raw_api_response_json (no new API call)}';

    protected $description = 'Re-run brand PDF analysis on an already-uploaded PDF (local dev only)';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('This command is only available in local/testing environments.');
            return 1;
        }

        $brand = $this->resolveBrand();
        if (! $brand) {
            return 1;
        }

        $draft = $brand->brandModel?->versions()
            ->where('status', 'draft')
            ->latest()
            ->first();

        if (! $draft) {
            $this->error("No draft version found for brand '{$brand->name}'.");
            return 1;
        }

        $pdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        if (! $pdfAsset) {
            $this->error("No guidelines PDF attached to draft #{$draft->id}.");
            return 1;
        }

        $this->info("Brand:  {$brand->name} (#{$brand->id})");
        $this->info("Draft:  #{$draft->id} (v{$draft->version_number})");
        $this->info("PDF:    {$pdfAsset->original_filename} (#{$pdfAsset->id})");

        if ($this->option('from-raw')) {
            return $this->reprocessFromRaw($brand, $draft, $pdfAsset);
        }

        return $this->dispatchNewRun($brand, $draft, $pdfAsset);
    }

    protected function resolveBrand(): ?Brand
    {
        $input = $this->argument('brand');

        if (! $input) {
            $brands = Brand::with('brandModel.versions')
                ->whereHas('brandModel.versions', fn ($q) => $q->where('status', 'draft'))
                ->get();

            if ($brands->isEmpty()) {
                $this->error('No brands with draft versions found.');
                return null;
            }

            $choices = $brands->map(fn ($b) => "{$b->id} — {$b->name}")->toArray();
            $choice = $this->choice('Select a brand:', $choices);
            $id = explode(' — ', $choice)[0];

            return Brand::find($id);
        }

        return Brand::where('id', $input)->orWhere('slug', $input)->first()
            ?? tap(null, fn () => $this->error("Brand '{$input}' not found."));
    }

    protected function dispatchNewRun(Brand $brand, BrandModelVersion $draft, Asset $pdfAsset): int
    {
        $run = BrandPipelineRun::create([
            'brand_id' => $brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $pdfAsset->id,
            'stage' => BrandPipelineRun::STAGE_INIT,
            'extraction_mode' => BrandPipelineRun::resolveExtractionMode($pdfAsset),
            'status' => BrandPipelineRun::STATUS_PENDING,
        ]);

        $this->info("Pipeline run #{$run->id} created.");

        if ($this->option('sync')) {
            $this->info('Running synchronously...');
            BrandPipelineRunnerJob::dispatchSync($run->id);

            $run->refresh();
            if ($run->status === BrandPipelineRun::STATUS_COMPLETED) {
                $this->info('Pipeline completed successfully.');
                $this->printResults($run);
            } else {
                $this->error("Pipeline finished with status: {$run->status}");
                if ($run->error_message) {
                    $this->error($run->error_message);
                }
            }
        } else {
            BrandPipelineRunnerJob::dispatch($run->id);
            $this->info('Job dispatched to queue. Check pipeline log for progress.');
        }

        return 0;
    }

    protected function reprocessFromRaw(Brand $brand, BrandModelVersion $draft, Asset $pdfAsset): int
    {
        $latestRun = BrandPipelineRun::where('brand_id', $brand->id)
            ->whereNotNull('raw_api_response_json')
            ->latest()
            ->first();

        if (! $latestRun || empty($latestRun->raw_api_response_json)) {
            $this->error('No previous run with raw_api_response_json found. Run without --from-raw first.');
            return 1;
        }

        $this->info('Re-processing from stored raw API response (no new API call)...');
        $this->info("Source run: #{$latestRun->id} from {$latestRun->created_at}");

        $extraction = $latestRun->merged_extraction_json;
        if (empty($extraction)) {
            $this->error('No merged_extraction_json in the source run.');
            return 1;
        }

        $snapshotService = app(\App\Services\BrandDNA\BrandSnapshotService::class);
        $snapshotService->createFromExtractions($brand, $draft, [$extraction], ['pdf']);

        $this->info('Snapshot re-created from stored extraction data.');
        $this->printResults($latestRun);

        return 0;
    }

    protected function printResults(BrandPipelineRun $run): void
    {
        $extraction = $run->merged_extraction_json ?? [];
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Mission', mb_substr($extraction['identity']['mission'] ?? '—', 0, 80)],
            ['Positioning', mb_substr($extraction['identity']['positioning'] ?? '—', 0, 80)],
            ['Tagline', $extraction['identity']['tagline'] ?? '—'],
            ['Industry', $extraction['identity']['industry'] ?? '—'],
            ['Target Audience', mb_substr($extraction['identity']['target_audience'] ?? '—', 0, 80)],
            ['Archetype', $extraction['personality']['primary_archetype'] ?? '—'],
            ['Traits', implode(', ', $extraction['personality']['traits'] ?? []) ?: '—'],
            ['Tone', implode(', ', $extraction['personality']['tone_keywords'] ?? []) ?: '—'],
            ['Voice', mb_substr($extraction['personality']['voice_description'] ?? '—', 0, 80)],
            ['Brand Look', mb_substr($extraction['personality']['brand_look'] ?? '—', 0, 80)],
            ['Colors', implode(', ', $extraction['visual']['primary_colors'] ?? []) ?: '—'],
            ['Fonts', implode(', ', $extraction['visual']['fonts'] ?? []) ?: '—'],
        ]);

        $raw = $run->raw_api_response_json ?? [];
        if (! empty($raw)) {
            $this->newLine();
            $this->info(sprintf(
                'API cost: $%.4f | Tokens: %d in / %d out | Model: %s',
                $raw['cost'] ?? 0,
                $raw['tokens_in'] ?? 0,
                $raw['tokens_out'] ?? 0,
                $raw['model'] ?? 'unknown'
            ));
        }
    }
}

<?php

namespace App\Console\Commands\Automation;

use App\Jobs\Automation\DetectErrorPatternsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to scan error patterns.
 */
class ScanErrorPatterns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:scan-error-patterns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan error logs for patterns and suggest internal tickets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning error logs for patterns...');

        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.error_pattern_detection.enabled', true)) {
            $this->warn('Error pattern detection is disabled.');
            return Command::SUCCESS;
        }

        if (config('automation.triggers.error_pattern_detection.async', true)) {
            DetectErrorPatternsJob::dispatch();
            $this->info('Dispatched error pattern detection job.');
        } else {
            // Process inline
            try {
                $service = app(\App\Services\Automation\ErrorPatternDetectionService::class);
                $suggestionsCreated = $service->scanErrorPatterns();
                $this->info("Created {$suggestionsCreated} ticket suggestions from error patterns.");
            } catch (\Exception $e) {
                Log::error('Failed to scan error patterns inline', [
                    'error' => $e->getMessage(),
                ]);
                $this->error('Failed to scan error patterns: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}

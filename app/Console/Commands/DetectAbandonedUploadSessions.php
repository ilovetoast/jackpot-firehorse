<?php

namespace App\Console\Commands;

use App\Services\AbandonedSessionService;
use Illuminate\Console\Command;

/**
 * Command to detect and mark abandoned upload sessions as failed.
 *
 * This command should be run periodically (e.g., every 15 minutes) via Laravel scheduler
 * to clean up upload sessions that have been abandoned by clients.
 *
 * Usage: php artisan uploads:detect-abandoned [--timeout=30]
 */
class DetectAbandonedUploadSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uploads:detect-abandoned 
                            {--timeout=30 : Timeout in minutes for detecting abandoned sessions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and mark abandoned upload sessions as failed';

    /**
     * Execute the console command.
     */
    public function handle(AbandonedSessionService $service): int
    {
        $timeout = (int) $this->option('timeout');

        $this->info("Detecting abandoned upload sessions (timeout: {$timeout} minutes)...");

        $markedCount = $service->detectAndMarkAbandoned($timeout);

        if ($markedCount > 0) {
            $this->info("Marked {$markedCount} abandoned upload session(s) as failed.");
            return Command::SUCCESS;
        } else {
            $this->info('No abandoned upload sessions found.');
            return Command::SUCCESS;
        }
    }
}

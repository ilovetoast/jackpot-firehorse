<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoWorkspaceCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoCleanupExpiredCommand extends Command
{
    protected $signature = 'demo:cleanup-expired
                            {--dry-run : Report eligible demos and storage counts without deleting}
                            {--force : Run without interactive confirmation (required for destructive runs in non-interactive contexts)}';

    protected $description = 'Clean up expired/archived disposable demo tenants and their tenants/{uuid}/ storage prefix';

    public function handle(DemoWorkspaceCleanupService $cleanupService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! (bool) config('demo.cleanup_enabled', false) && ! $force) {
            $this->error('Demo cleanup is disabled (demo.cleanup_enabled=false). Use --dry-run to preview, or pass --force for a one-off run.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $force) {
            if (! $this->confirm('This will permanently delete eligible disposable demo tenants and their storage prefix. Continue?')) {
                return self::FAILURE;
            }
        }

        $rows = $cleanupService->runScheduledPass($dryRun);

        if ($rows === []) {
            $this->info($dryRun ? 'Dry run: no disposable demos matched scheduled cleanup rules.' : 'No disposable demos matched scheduled cleanup rules.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Dry run', 'OK', 'Storage keys', 'Message'],
            array_map(fn (array $r) => [
                (string) ($r['tenant_id'] ?? ''),
                $r['slug'] ?? '',
                $r['dry_run'] ? 'yes' : 'no',
                $r['success'] ? 'yes' : 'no',
                (string) ($r['storage_keys_removed'] ?? '0'),
                Str::limit((string) ($r['message'] ?? ''), 120),
            ], $rows),
        );

        return self::SUCCESS;
    }
}

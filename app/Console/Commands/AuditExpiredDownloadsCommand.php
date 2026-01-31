<?php

namespace App\Console\Commands;

use App\Services\DownloadExpiredAuditService;
use Illuminate\Console\Command;

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 *
 * Run audit: verify expired downloads do NOT have ZIPs in storage. Flags anomalies.
 */
class AuditExpiredDownloadsCommand extends Command
{
    protected $signature = 'downloads:audit-expired';

    protected $description = 'Audit expired downloads: verify ZIPs are not left in storage (Phase D1)';

    public function handle(DownloadExpiredAuditService $audit): int
    {
        $this->info('Running expired downloads audit...');

        $result = $audit->run();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Checked', $result['checked']],
                ['OK (no ZIP or ZIP missing)', $result['ok']],
                ['Anomalies (ZIP still present)', count($result['anomalies'])],
            ]
        );

        if (! empty($result['anomalies'])) {
            $this->warn('Anomalies detected (expired download ZIPs still in storage):');
            foreach ($result['anomalies'] as $a) {
                $this->line("  - Download {$a['download_id']}: {$a['zip_path']}");
            }
            return self::FAILURE;
        }

        $this->info('Audit complete. No anomalies.');
        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\StudioLayerExtractionSession;
use App\Support\StudioLayerExtractionStoragePaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupStudioLayerExtractionSessionsCommand extends Command
{
    protected $signature = 'studio:cleanup-layer-extraction-sessions';

    protected $description = 'Remove expired Studio layer extraction sessions and staged mask files.';

    public function handle(): int
    {
        $disk = Storage::disk('studio_layer_extraction');
        $rows = StudioLayerExtractionSession::query()
            ->where('expires_at', '<', now())
            ->whereIn('status', [
                StudioLayerExtractionSession::STATUS_PENDING,
                StudioLayerExtractionSession::STATUS_READY,
                StudioLayerExtractionSession::STATUS_FAILED,
            ])
            ->get();

        $n = 0;
        foreach ($rows as $row) {
            try {
                $disk->deleteDirectory(StudioLayerExtractionStoragePaths::sessionDirectory($row->id));
            } catch (\Throwable) {
                // best-effort
            }
            $row->delete();
            $n++;
        }

        $this->info("Removed {$n} expired extraction session(s).");

        return self::SUCCESS;
    }
}

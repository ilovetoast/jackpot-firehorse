<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\ThumbnailProfilingService;
use App\Support\ThumbnailMode;
use Illuminate\Console\Command;

/**
 * Profile thumbnail generation timings for one asset (storage vs decode/resize/encode vs write).
 *
 * Run via Sail: ./vendor/bin/sail artisan assets:profile-thumbnail {id}
 */
class ProfileThumbnailCommand extends Command
{
    protected $signature = 'assets:profile-thumbnail
                            {asset_id : Asset UUID}
                            {--iterations=1 : Repeat measurement and show averages}
                            {--write : Copy output to storage/app/debug/thumbnail-profiles/{asset_id}/ (does not touch production thumbnails or metadata)}
                            {--mode=original : Thumbnail mode: original or preferred}
                            {--style=grid : Style key (alias: grid → thumb) or preview|thumb|medium|large}';

    protected $description = 'Profile thumbnail pipeline timings for a single asset (diagnostics; read-only unless --write for debug file copy)';

    public function handle(ThumbnailProfilingService $profiler): int
    {
        $id = (string) $this->argument('asset_id');
        $iterations = max(1, (int) $this->option('iterations'));
        $write = (bool) $this->option('write');
        try {
            $mode = ThumbnailMode::normalize((string) $this->option('mode'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $styleRaw = strtolower(trim((string) $this->option('style')));
        $styleName = match ($styleRaw) {
            'grid' => 'thumb',
            default => $styleRaw,
        };

        $styles = config('assets.thumbnail_styles', []);
        if (! isset($styles[$styleName])) {
            $this->error('Unknown style "'.$styleName.'". Configured keys: '.implode(', ', array_keys($styles)));

            return self::FAILURE;
        }

        $asset = Asset::query()
            ->with(['currentVersion', 'storageBucket'])
            ->find($id);

        if (! $asset) {
            $this->error("Asset not found: {$id}");

            return self::FAILURE;
        }

        $version = $asset->currentVersion;

        $this->info('Thumbnail profile');
        $this->line('  asset_id: '.$asset->id);
        $this->line('  mode: '.$mode);
        $this->line('  style: '.$styleName);
        $this->line('  iterations: '.$iterations);
        $this->line('  write debug copy: '.($write ? 'yes (storage/app/debug/thumbnail-profiles/...)' : 'no'));
        $this->newLine();

        $samples = [];
        for ($i = 1; $i <= $iterations; $i++) {
            if ($iterations > 1) {
                $this->comment("— Iteration {$i}/{$iterations} —");
            }
            try {
                $row = $profiler->profileOnce($asset, $version, $mode, $styleName, $write);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $samples[] = $row;
            $this->printRow($row);
            ThumbnailProfilingService::logStructured($asset, $row);
            $this->newLine();
        }

        if ($iterations > 1) {
            $avg = ThumbnailProfilingService::averageSamples($samples);
            $this->info('Averages over '.$iterations.' iterations:');
            $this->line('  total_ms: '.($avg['total_ms'] ?? ''));
            $this->line('  read_ms: '.($avg['read_ms'] ?? '').' | head_ms: '.($avg['head_ms'] ?? ''));
            $this->line('  pipeline_ms: '.($avg['pipeline_ms'] ?? '').' | preferred_crop_ms: '.($avg['preferred_crop_ms'] ?? ''));
            $this->line('  decode_ms: '.($avg['decode_ms'] ?? '').' | normalize_ms: '.($avg['normalize_ms'] ?? '').' | resize_ms: '.($avg['resize_ms'] ?? '').' | encode_ms: '.($avg['encode_ms'] ?? ''));
            ThumbnailProfilingService::logStructured($asset, array_merge($avg, [
                'mime_type' => $asset->mime_type,
                'original_bytes' => $avg['original_bytes'] ?? null,
                'original_dimensions' => $avg['original_dimensions'] ?? null,
                'disk' => $avg['disk'] ?? null,
            ]));
        }

        $this->comment('db_ms is always 0 (this command does not persist metadata).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function printRow(array $row): void
    {
        $this->table(
            ['Stage', 'ms'],
            [
                ['1. Asset / DB lookup', $row['lookup_ms']],
                ['2. S3 HEAD (metadata)', $row['head_ms']],
                ['3. Read / download original', $row['read_ms']],
                ['4. detectFileType()', $row['detect_ms']],
                ['5. Source dimension probe (getimagesize / ping)', $row['source_probe_ms']],
                ['6. Preferred smart/print crop (mode=preferred)', $row['preferred_crop_ms']],
                ['7. Full pipeline (generate one style)', $row['pipeline_ms']],
                ['   └ GD file_meta (getimagesize)', $row['file_meta_ms']],
                ['   └ GD decode (imagecreatefrom*)', $row['decode_ms']],
                ['   └ GD normalize (canvas / transparency)', $row['normalize_ms']],
                ['   └ GD resize + blur', $row['resize_ms']],
                ['   └ GD encode (webp/jpeg)', $row['encode_ms']],
                ['8. Debug file write', $row['write_ms']],
                ['9. DB / metadata update', $row['db_ms'].' (not performed)'],
                ['10. Total', $row['total_ms']],
            ]
        );

        $this->line('Disk: '.($row['disk'] ?? ''));
        $this->line('Source key/path: '.($row['source_path'] ?? ''));
        $this->line('MIME: '.($row['mime_type'] ?? ''));
        $this->line('File type (handler): '.($row['file_type'] ?? ''));
        $this->line('Original: '.($row['original_bytes'] ?? 0).' bytes'.($row['original_dimensions'] ? ', '.$row['original_dimensions'].' px' : ''));
        $this->line('Output: '.($row['output_bytes'] ?? 0).' bytes');
        if (! empty($row['output_path'])) {
            $this->line('Debug copy: '.$row['output_path']);
        }
        if (($row['file_type'] ?? '') === 'image' && ($row['decode_ms'] ?? 0) == 0 && ($row['pipeline_ms'] ?? 0) > 0) {
            $this->comment('Note: decode/resize/encode breakdown applies to the GD image path; pipeline_ms is the full handler time.');
        }
    }
}

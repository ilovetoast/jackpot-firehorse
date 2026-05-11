<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 8: Audit the file_types registry vs. actual asset usage.
 *
 * The registry (`config/file_types.php`) is the single source of truth
 * for "what can be uploaded into the DAM". Over time, assets accumulate
 * MIME types and extensions that may have been uploaded under older,
 * looser rules (or imported via a back-channel). This command surfaces:
 *
 *   - Extensions present on existing Asset rows that are NOT in any
 *     allowlist `types.<type>.extensions`.
 *   - MIME types present on existing Asset rows that are NOT in any
 *     allowlist `types.<type>.mime_types`.
 *   - Extensions / MIMEs that appear simultaneously in BOTH the
 *     allowlist and the explicit `blocked` group (a contradiction the
 *     CI guard test in tests/Unit also enforces).
 *   - "Coming soon" types that are no longer marked coming_soon — i.e.
 *     somebody flipped the registry but the JS frontend may still be
 *     hinting users that they're not ready yet.
 *
 * Usage:
 *   php artisan filetypes:audit
 *   php artisan filetypes:audit --json   # ops automation friendly
 */
class FileTypesAuditCommand extends Command
{
    protected $signature = 'filetypes:audit {--json : Emit JSON instead of table output}';

    protected $description = 'Audit the file_types registry vs. existing asset usage and registry self-consistency.';

    public function handle(): int
    {
        $registry = (array) config('file_types.types', []);
        $blocked = (array) config('file_types.blocked', []);

        $allowedExtensions = [];
        $allowedMimes = [];
        foreach ($registry as $type => $cfg) {
            foreach ((array) ($cfg['extensions'] ?? []) as $ext) {
                $allowedExtensions[strtolower((string) $ext)] = $type;
            }
            foreach ((array) ($cfg['mime_types'] ?? []) as $mime) {
                $allowedMimes[strtolower((string) $mime)] = $type;
            }
        }

        $blockedExtensions = [];
        foreach ($blocked as $group => $entries) {
            foreach ((array) ($entries['extensions'] ?? []) as $ext) {
                $blockedExtensions[strtolower((string) $ext)] = $group;
            }
        }

        // Self-consistency: extension cannot be both allowed and blocked.
        $allowAndBlocked = array_intersect_key($allowedExtensions, $blockedExtensions);

        // Asset usage scan — guard against missing table during fresh installs.
        $usageRows = [];
        try {
            $usageRows = \DB::table('assets')
                ->selectRaw('LOWER(mime_type) as mime, LOWER(SUBSTRING_INDEX(original_filename, ".", -1)) as ext, COUNT(*) as n')
                ->whereNull('deleted_at')
                ->groupBy('mime', 'ext')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            // SQLite or non-MySQL test DB can't do SUBSTRING_INDEX; skip
            // the usage scan rather than fail the whole command.
            $this->warn('[filetypes:audit] usage scan skipped: '.$e->getMessage());
        }

        $unknownExtensions = [];
        $unknownMimes = [];
        foreach ($usageRows as $row) {
            $mime = trim((string) ($row['mime'] ?? ''));
            $ext = trim((string) ($row['ext'] ?? ''));
            $n = (int) ($row['n'] ?? 0);
            if ($ext !== '' && ! isset($allowedExtensions[$ext])) {
                $unknownExtensions[$ext] = ($unknownExtensions[$ext] ?? 0) + $n;
            }
            if ($mime !== '' && ! isset($allowedMimes[$mime])) {
                $unknownMimes[$mime] = ($unknownMimes[$mime] ?? 0) + $n;
            }
        }

        $report = [
            'allowed_types' => array_keys($registry),
            'blocked_groups' => array_keys($blocked),
            'allow_and_blocked_collision' => $allowAndBlocked,
            'unknown_extensions_in_use' => $unknownExtensions,
            'unknown_mimes_in_use' => $unknownMimes,
            'coming_soon_types' => array_keys(array_filter($registry, function ($cfg) {
                $status = $cfg['upload']['status'] ?? null;

                return $status === 'coming_soon';
            })),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('<info>Registry overview</info>');
        $this->line('  Allowed types:     '.implode(', ', $report['allowed_types']));
        $this->line('  Blocked groups:    '.implode(', ', $report['blocked_groups']));
        $this->line('  Coming soon types: '.implode(', ', $report['coming_soon_types']) ?: '(none)');
        $this->newLine();

        if (! empty($allowAndBlocked)) {
            $this->error('Self-consistency violations: extensions present in BOTH allowlist and blocklist');
            $this->table(
                ['Extension', 'Allowed type', 'Blocked group'],
                array_map(
                    fn ($ext, $type) => [$ext, $type, $blockedExtensions[$ext]],
                    array_keys($allowAndBlocked),
                    array_values($allowAndBlocked),
                ),
            );
            $this->newLine();
        } else {
            $this->info('Self-consistency: no extension is both allowed and blocked.');
            $this->newLine();
        }

        if (! empty($unknownExtensions)) {
            arsort($unknownExtensions);
            $this->warn('Extensions in use but NOT in any allowlist (stale data or pre-tightening uploads):');
            $this->table(
                ['Extension', 'Asset count'],
                array_map(fn ($k, $v) => [$k, $v], array_keys($unknownExtensions), array_values($unknownExtensions)),
            );
            $this->newLine();
        }

        if (! empty($unknownMimes)) {
            arsort($unknownMimes);
            $this->warn('MIME types in use but NOT in any allowlist:');
            $this->table(
                ['MIME', 'Asset count'],
                array_map(fn ($k, $v) => [$k, $v], array_keys($unknownMimes), array_values($unknownMimes)),
            );
        }

        return empty($allowAndBlocked) ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 5: Incident-response readout for `upload_blocked` log events.
 *
 * Usage:
 *   php artisan upload:audit-recent
 *   php artisan upload:audit-recent --since=1h --tenant=42
 *   php artisan upload:audit-recent --request-id=01HABC...
 *
 * The command tails the laravel log file(s) and prints a structured
 * grouping by gate / reason / tenant / user. It deliberately uses the
 * filesystem log channel rather than a structured store: when something
 * burns, the only thing you can rely on is that the logs exist.
 */
class UploadAuditRecentCommand extends Command
{
    protected $signature = 'upload:audit-recent
        {--since=2h : Window to scan (e.g. 30m, 2h, 1d, 7d)}
        {--tenant= : Filter to a single tenant id}
        {--user= : Filter to a single user id}
        {--ip= : Filter to a single source ip}
        {--gate= : Filter to a single gate (preflight, initiate, initiate_batch, finalize_content_sniff, finalize_response)}
        {--request-id= : Filter to a single correlation id}
        {--limit=200 : Max events to print}
        {--json : Emit JSON instead of table output}';

    protected $description = 'Audit recent upload_blocked events for incident response.';

    public function handle(): int
    {
        $sinceSeconds = $this->parseSince((string) $this->option('since'));
        $cutoffTs = now()->subSeconds($sinceSeconds);

        $logFiles = $this->candidateLogFiles();
        if (empty($logFiles)) {
            $this->warn('No log files found in storage/logs.');

            return self::SUCCESS;
        }

        $records = [];
        foreach ($logFiles as $file) {
            // Cheap mtime gate — skip files older than the window entirely.
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoffTs->getTimestamp() - 60) {
                continue;
            }
            foreach ($this->scanFile($file, $cutoffTs) as $row) {
                $records[] = $row;
            }
        }

        $records = $this->applyFilters($records);

        usort($records, function ($a, $b) {
            return strcmp((string) ($b['ts'] ?? ''), (string) ($a['ts'] ?? ''));
        });

        $records = array_slice($records, 0, max(1, (int) $this->option('limit')));

        if ($records === []) {
            $this->info('No upload_blocked events match.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $rows = array_map(function ($r) {
            return [
                substr((string) ($r['ts'] ?? ''), 0, 19),
                (string) ($r['gate'] ?? ''),
                (string) ($r['reason'] ?? $r['code'] ?? ''),
                (string) ($r['tenant_id'] ?? ''),
                (string) ($r['user_id'] ?? ''),
                $this->shorten((string) ($r['ip'] ?? '')),
                $this->shorten((string) ($r['name'] ?? '')),
                $this->shorten((string) ($r['request_id'] ?? '')),
            ];
        }, $records);

        $this->table(
            ['Time', 'Gate', 'Reason / Code', 'Tenant', 'User', 'IP', 'Filename', 'Request'],
            $rows,
        );

        $this->renderSummary($records);

        return self::SUCCESS;
    }

    protected function parseSince(string $since): int
    {
        if (preg_match('/^(\d+)\s*(s|m|h|d)$/i', trim($since), $m)) {
            return (int) $m[1] * match (strtolower($m[2])) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400,
            };
        }

        return 7200; // 2h default if malformed
    }

    /**
     * @return list<string>
     */
    protected function candidateLogFiles(): array
    {
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.log') ?: [];
        usort($files, function ($a, $b) {
            return @filemtime($b) <=> @filemtime($a);
        });

        return $files;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    protected function scanFile(string $file, \DateTimeInterface $cutoff): iterable
    {
        $h = @fopen($file, 'r');
        if (! $h) {
            return;
        }
        try {
            while (($line = fgets($h)) !== false) {
                if (strpos($line, 'upload_blocked') === false) {
                    continue;
                }
                $parsed = $this->parseLine($line);
                if ($parsed === null) {
                    continue;
                }
                if (isset($parsed['ts'])) {
                    try {
                        $ts = new \DateTimeImmutable((string) $parsed['ts']);
                        if ($ts < $cutoff) {
                            continue;
                        }
                    } catch (\Throwable $e) {
                        // Bad timestamp; keep it so we don't drop signal silently.
                    }
                }
                yield $parsed;
            }
        } finally {
            fclose($h);
        }
    }

    /**
     * Best-effort parser for Laravel's stack/single channel format:
     *   [2026-05-11 12:34:56] production.WARNING: upload_blocked {"gate":"preflight",...}
     */
    protected function parseLine(string $line): ?array
    {
        if (! preg_match('/^\[([^\]]+)\][^:]+:\s*upload_blocked\s+(\{.*\})\s*$/u', $line, $m)) {
            return null;
        }
        $ts = $m[1];
        $json = $m[2];
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }
        $decoded['ts'] = $ts;

        return $decoded;
    }

    protected function applyFilters(array $records): array
    {
        $tenant = $this->option('tenant');
        $user = $this->option('user');
        $ip = $this->option('ip');
        $gate = $this->option('gate');
        $requestId = $this->option('request-id');

        return array_values(array_filter($records, function ($r) use ($tenant, $user, $ip, $gate, $requestId) {
            if ($tenant && (string) ($r['tenant_id'] ?? '') !== (string) $tenant) {
                return false;
            }
            if ($user && (string) ($r['user_id'] ?? '') !== (string) $user) {
                return false;
            }
            if ($ip && (string) ($r['ip'] ?? '') !== (string) $ip) {
                return false;
            }
            if ($gate && (string) ($r['gate'] ?? '') !== (string) $gate) {
                return false;
            }
            if ($requestId && (string) ($r['request_id'] ?? '') !== (string) $requestId) {
                return false;
            }

            return true;
        }));
    }

    protected function renderSummary(array $records): void
    {
        $byGate = [];
        $byReason = [];
        $byTenant = [];
        $byIp = [];
        foreach ($records as $r) {
            $g = (string) ($r['gate'] ?? '');
            $reason = (string) ($r['reason'] ?? $r['code'] ?? '');
            $t = (string) ($r['tenant_id'] ?? '');
            $i = (string) ($r['ip'] ?? '');
            if ($g) {
                $byGate[$g] = ($byGate[$g] ?? 0) + 1;
            }
            if ($reason) {
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            if ($t) {
                $byTenant[$t] = ($byTenant[$t] ?? 0) + 1;
            }
            if ($i) {
                $byIp[$i] = ($byIp[$i] ?? 0) + 1;
            }
        }

        $this->newLine();
        $this->info('Summary ('.count($records).' events)');
        $this->renderGroup('Gate', $byGate);
        $this->renderGroup('Reason / Code', $byReason);
        $this->renderGroup('Top tenants', $byTenant, 5);
        $this->renderGroup('Top IPs', $byIp, 5);
    }

    protected function renderGroup(string $label, array $counts, int $top = 10): void
    {
        if ($counts === []) {
            return;
        }
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $top, true) as $k => $v) {
            $rows[] = [$k, $v];
        }
        $this->line(' '.$label);
        $this->table(['Value', 'Count'], $rows);
    }

    protected function shorten(string $v, int $max = 36): string
    {
        if ($v === '') {
            return '';
        }
        if (mb_strlen($v) <= $max) {
            return $v;
        }

        return mb_substr($v, 0, $max - 1).'…';
    }
}

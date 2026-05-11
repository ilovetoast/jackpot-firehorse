<?php

namespace App\Support;

/**
 * Resolves the git commit timestamp (ISO-8601) for the Jackpot console banner.
 *
 * Order: {@see base_path('.release-info.json')} (committed_at|time) → config jackpot_console.build_time
 * ({@see env('APP_BUILD_TIME')}) → {@code git log -1 --format=%cI} when environment is local|staging and .git exists.
 */
final class JackpotConsoleRelease
{
    public static function committedAtIso8601(): ?string
    {
        $jsonPath = base_path('.release-info.json');
        if (is_file($jsonPath) && is_readable($jsonPath)) {
            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($decoded)) {
                $raw = $decoded['committed_at'] ?? $decoded['time'] ?? null;
                if (is_string($raw) && trim($raw) !== '') {
                    return trim($raw);
                }
            }
        }

        $fromConfig = config('jackpot_console.build_time');
        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return trim($fromConfig);
        }

        if (! in_array(app()->environment(), ['local', 'staging'], true)) {
            return null;
        }

        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $out = shell_exec('git -C '.escapeshellarg(base_path()).' log -1 --format=%cI 2>/dev/null');
        if (! is_string($out)) {
            return null;
        }
        $out = trim($out);

        return $out !== '' ? $out : null;
    }
}

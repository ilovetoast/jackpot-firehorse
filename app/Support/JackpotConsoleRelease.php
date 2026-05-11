<?php

namespace App\Support;

/**
 * Resolves git commit metadata (timestamp + short SHA) for the Jackpot
 * console banner.
 *
 * Resolution order — first non-empty wins:
 *   1. {@see base_path('.release-info.json')} (committed_at|time, commit|sha)
 *   2. config('jackpot_console.build_time') / env('APP_BUILD_COMMIT')
 *   3. {@see DeployedAtManifest} ({@see base_path('DEPLOYED_AT')}) — same
 *      source the Admin Command Center reads, so what shows in the console
 *      banner matches what the deploy script actually wrote.
 *   4. `git log -1 --format=%cI` / `git rev-parse --short=8 HEAD` when `.git`
 *      exists (dev fallback; production tarball deploys typically don't ship
 *      `.git`, so this only applies in local development).
 *
 * If nothing resolves, returns null and the banner falls back to the
 * client's local clock with a `local ·` badge — no console hint message
 * is printed in any case (operators were seeing the hint on staging because
 * the manifest fallback didn't exist; that's now fixed).
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

        // Same DEPLOYED_AT manifest the Command Center uses. The deploy
        // script (`scripts/web-mirror-deploy.sh`) writes `Deployed at: <UTC date>`
        // on every release; without this fallback the banner would say
        // `local ·` on staging even though the data exists in admin.
        $manifest = DeployedAtManifest::read();
        if (is_array($manifest)) {
            // Prefer an explicit commit time if a future deploy script
            // writes one; otherwise the deploy time is a fine proxy for
            // "when was this build cut" — the banner just wants a stamp.
            $candidates = [
                $manifest['Committed at'] ?? null,
                $manifest['Commit time'] ?? null,
                $manifest['Deployed at'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                $iso = self::normalizeToIso8601($candidate);
                if ($iso !== null) {
                    return $iso;
                }
            }
        }

        return self::fromGitLog();
    }

    /**
     * Short commit SHA for the badge suffix. Same resolution order as the
     * timestamp; returns null when nothing's available.
     */
    public static function commitShortSha(): ?string
    {
        $jsonPath = base_path('.release-info.json');
        if (is_file($jsonPath) && is_readable($jsonPath)) {
            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($decoded)) {
                $raw = $decoded['commit'] ?? $decoded['sha'] ?? null;
                $short = self::shortenSha($raw);
                if ($short !== null) {
                    return $short;
                }
            }
        }

        $fromConfig = env('APP_BUILD_COMMIT');
        $short = self::shortenSha($fromConfig);
        if ($short !== null) {
            return $short;
        }

        $manifest = DeployedAtManifest::read();
        if (is_array($manifest) && isset($manifest['Commit'])) {
            $short = self::shortenSha($manifest['Commit']);
            if ($short !== null) {
                return $short;
            }
        }

        return self::fromGitRevParse();
    }

    /**
     * @param  mixed  $value
     */
    protected static function normalizeToIso8601($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return gmdate('c', $ts);
    }

    /**
     * @param  mixed  $value
     */
    protected static function shortenSha($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || ! preg_match('/^[0-9a-f]{7,40}$/i', $value)) {
            return null;
        }

        return strtolower(substr($value, 0, 8));
    }

    protected static function fromGitLog(): ?string
    {
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

    protected static function fromGitRevParse(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $out = shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short=8 HEAD 2>/dev/null');
        if (! is_string($out)) {
            return null;
        }
        $out = trim($out);

        return $out !== '' ? strtolower($out) : null;
    }
}

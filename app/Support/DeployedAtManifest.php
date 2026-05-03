<?php

namespace App\Support;

/**
 * Reads {@see base_path('DEPLOYED_AT')} written by deploy scripts (e.g. web-mirror-deploy.sh).
 * Key/value lines: "Label: value" (first colon splits; value may contain colons).
 */
final class DeployedAtManifest
{
    /**
     * @return array<string, string>|null
     */
    public static function read(): ?array
    {
        $path = base_path('DEPLOYED_AT');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", trim($content));
        $info = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $info[$key] = $value;
            }
        }

        return $info === [] ? null : $info;
    }
}

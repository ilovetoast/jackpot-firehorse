<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shapes thumbnail engine errors for logs and DB metadata: strips native stack dumps
 * (LibreOffice often appends "Stack:" with libuno_sal / libc frames) and deduplicates
 * identical messages across preview + derivative styles.
 */
final class ThumbnailEngineDiagnostics
{
    public static function sanitizeMessage(string $message): string
    {
        $m = trim($message);
        if ($m === '') {
            return '';
        }

        foreach (["\nStack:", "\r\nStack:", "\nstack:", "\r\nstack:"] as $needle) {
            $p = stripos($m, $needle);
            if ($p !== false) {
                $m = substr($m, 0, $p);
                break;
            }
        }

        $m = trim($m);
        $max = 1200;
        if (mb_strlen($m) > $max) {
            return mb_substr($m, 0, $max).'…';
        }

        return $m;
    }

    /**
     * @param  list<mixed>  $rows
     * @return array{rows: list<array{context: string, message: string}>, summary: string}
     */
    public static function normalizeForMetadata(array $rows): array
    {
        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ctx = trim((string) ($row['context'] ?? ''));
            $msg = self::sanitizeMessage((string) ($row['message'] ?? ''));
            if ($msg === '' || $ctx === '') {
                continue;
            }
            $clean[] = ['context' => $ctx, 'message' => $msg];
        }

        if ($clean === []) {
            return ['rows' => [], 'summary' => ''];
        }

        /** @var array<string, list<string>> $contextsByMessage */
        $contextsByMessage = [];
        foreach ($clean as $row) {
            $contextsByMessage[$row['message']][] = $row['context'];
        }

        $summaryLines = [];
        foreach ($contextsByMessage as $msg => $contexts) {
            $summaryLines[] = count($contexts) > 1
                ? implode(', ', $contexts).': '.$msg
                : $contexts[0].': '.$msg;
        }

        return [
            'rows' => $clean,
            'summary' => implode("\n", $summaryLines),
        ];
    }
}

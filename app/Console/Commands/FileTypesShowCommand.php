<?php

namespace App\Console\Commands;

use App\Services\FileTypeService;
use Illuminate\Console\Command;

/**
 * Phase 8: One-extension readout for incident response and ops.
 *
 * Use cases:
 *   - "Why did this .heic upload fail?" -> filetypes:show heic shows the
 *     registry's exact decision for that extension (including coming-soon
 *     status and disabled message).
 *   - "Is .docx allowed today?"        -> filetypes:show docx
 *   - "Where does this MIME map?"      -> filetypes:show jpeg --mime
 *
 * The command is intentionally read-only and side-effect free. It calls
 * FileTypeService directly so the answer it gives matches what real
 * uploads would see.
 */
class FileTypesShowCommand extends Command
{
    protected $signature = 'filetypes:show
        {value : Extension (e.g. heic) or MIME (e.g. image/heic)}
        {--mime : Treat the value as a MIME type rather than an extension}
        {--json : Emit JSON instead of table output}';

    protected $description = 'Show the registry decision for a single extension or MIME type.';

    public function handle(FileTypeService $svc): int
    {
        $value = trim((string) $this->argument('value'));
        if ($value === '') {
            $this->error('You must pass an extension or MIME type.');

            return self::FAILURE;
        }

        $isMime = (bool) $this->option('mime') || str_contains($value, '/');
        $mime = $isMime ? strtolower($value) : null;
        $extension = $isMime ? null : strtolower(ltrim($value, '.'));

        $detected = $svc->detectFileType($mime, $extension);
        $decision = $svc->isUploadAllowed($mime, $extension);
        $isExplicitlyBlocked = $svc->isExplicitlyBlocked($mime, $extension);

        $cfg = $detected ? (array) config('file_types.types.'.$detected, []) : [];
        $upload = (array) ($cfg['upload'] ?? []);

        $payload = [
            'input' => [
                'extension' => $extension,
                'mime' => $mime,
            ],
            'detected_type' => $detected,
            'allowed' => $decision['allowed'] ?? false,
            'code' => $decision['code'] ?? null,
            'message' => $decision['message'] ?? null,
            'blocked_group' => $decision['blocked_group'] ?? null,
            'log_severity' => $decision['log_severity'] ?? null,
            'is_explicitly_blocked' => $isExplicitlyBlocked,
            'registry' => $detected ? [
                'name' => $cfg['name'] ?? null,
                'description' => $cfg['description'] ?? null,
                'upload_status' => $upload['status'] ?? null,
                'upload_enabled' => (bool) ($upload['enabled'] ?? false),
                'disabled_message' => $upload['disabled_message'] ?? null,
                'max_size_bytes' => $upload['max_size_bytes'] ?? null,
                'requires_sanitization' => (bool) ($upload['requires_sanitization'] ?? false),
                'capabilities' => $cfg['capabilities'] ?? null,
                'extensions' => $cfg['extensions'] ?? [],
                'mime_types' => $cfg['mime_types'] ?? [],
            ] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('<info>Input</info>');
        $this->line('  Extension : '.($extension ?? '(n/a)'));
        $this->line('  MIME      : '.($mime ?? '(n/a)'));
        $this->newLine();

        $this->line('<info>Decision</info>');
        $this->line('  Detected type      : '.($detected ?? '(none — not in any allowlist)'));
        $this->line('  Allowed for upload : '.(($payload['allowed'] ?? false) ? 'yes' : 'no'));
        if (($payload['allowed'] ?? false) === false) {
            $this->line('  Code               : '.($payload['code'] ?? ''));
            $this->line('  Blocked group      : '.($payload['blocked_group'] ?? '(n/a)'));
            $this->line('  Message            : '.($payload['message'] ?? ''));
            $this->line('  Log severity       : '.($payload['log_severity'] ?? ''));
        }
        $this->line('  Explicitly blocked : '.($payload['is_explicitly_blocked'] ? 'yes' : 'no'));
        $this->newLine();

        if ($detected) {
            $this->line('<info>Registry entry</info>');
            $reg = $payload['registry'];
            $this->line('  Name             : '.($reg['name'] ?? ''));
            $this->line('  Description      : '.($reg['description'] ?? ''));
            $this->line('  Upload status    : '.($reg['upload_status'] ?? ''));
            $this->line('  Upload enabled   : '.($reg['upload_enabled'] ? 'yes' : 'no'));
            if ($reg['disabled_message']) {
                $this->line('  Disabled message : '.$reg['disabled_message']);
            }
            $this->line('  Max size bytes   : '.($reg['max_size_bytes'] ?? '(no per-type cap)'));
            $this->line('  Sanitize on upload: '.($reg['requires_sanitization'] ? 'yes' : 'no'));
            $this->line('  Extensions       : '.implode(', ', $reg['extensions'] ?? []));
            $this->line('  MIME types       : '.implode(', ', $reg['mime_types'] ?? []));
        }

        return self::SUCCESS;
    }
}

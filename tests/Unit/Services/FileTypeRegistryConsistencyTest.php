<?php

namespace Tests\Unit\Services;

use App\Services\FileTypeService;
use Tests\TestCase;

/**
 * Phase 8: CI guard for the file_types registry.
 *
 * The registry is the single decision-maker for whether a file is
 * acceptable as a brand asset. Two failure modes can land in production
 * silently if not gated by tests:
 *
 *   1. An extension lands in BOTH the allowlist (`types.<type>.extensions`)
 *      and the blocklist (`blocked.<group>.extensions`). The "allowed"
 *      decision usually wins on a config merge, which means a security
 *      tightening (adding ".docm" to blocked.documents) would not
 *      actually take effect. The audit script runs nightly, but a
 *      compile-time test gives us the same protection on every CI run.
 *
 *   2. The blocked extensions list disagrees with the static
 *      FileTypeService::isExplicitlyBlocked() result. This catches a
 *      regression where someone removes a row but forgets to remove
 *      the redundant hard-coded list inside the service.
 */
class FileTypeRegistryConsistencyTest extends TestCase
{
    public function test_no_extension_is_both_allowed_and_blocked(): void
    {
        $registry = (array) config('file_types.types', []);
        $blocked = (array) config('file_types.blocked', []);

        $allowed = [];
        foreach ($registry as $type => $cfg) {
            foreach ((array) ($cfg['extensions'] ?? []) as $ext) {
                $allowed[strtolower((string) $ext)] = $type;
            }
        }

        $blockedExt = [];
        foreach ($blocked as $group => $entries) {
            foreach ((array) ($entries['extensions'] ?? []) as $ext) {
                $blockedExt[strtolower((string) $ext)] = $group;
            }
        }

        $collisions = array_intersect_key($allowed, $blockedExt);

        $this->assertSame(
            [],
            $collisions,
            'Extensions cannot be present in both an allowlist type and a blocked group: '.
                json_encode($collisions, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_every_blocked_extension_is_recognized_by_isExplicitlyBlocked(): void
    {
        $svc = app(FileTypeService::class);
        $blocked = (array) config('file_types.blocked', []);

        $missed = [];
        foreach ($blocked as $group => $entries) {
            foreach ((array) ($entries['extensions'] ?? []) as $ext) {
                $extLower = strtolower((string) $ext);
                if (! $svc->isExplicitlyBlocked(null, $extLower)) {
                    $missed[$extLower] = $group;
                }
            }
        }

        $this->assertSame(
            [],
            $missed,
            'These blocked extensions are not surfaced by isExplicitlyBlocked(): '.
                json_encode($missed, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_isUploadAllowed_rejects_every_blocked_extension(): void
    {
        $svc = app(FileTypeService::class);
        $blocked = (array) config('file_types.blocked', []);

        $leaked = [];
        foreach ($blocked as $group => $entries) {
            foreach ((array) ($entries['extensions'] ?? []) as $ext) {
                $extLower = strtolower((string) $ext);
                $decision = $svc->isUploadAllowed(null, $extLower);
                if ($decision['allowed'] ?? false) {
                    $leaked[$extLower] = $group;
                }
            }
        }

        $this->assertSame(
            [],
            $leaked,
            'These blocked extensions are still allowed by isUploadAllowed(): '.
                json_encode($leaked, JSON_UNESCAPED_SLASHES),
        );
    }
}

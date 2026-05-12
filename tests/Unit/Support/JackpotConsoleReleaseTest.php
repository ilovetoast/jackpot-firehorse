<?php

namespace Tests\Unit\Support;

use App\Support\JackpotConsoleRelease;
use Tests\TestCase;

/**
 * Pin the resolution order for the console banner stamp. The bug we hit
 * on staging: the banner read `local · …` even though the Admin
 * Command Center had `c77c0c9 / Deployed May 11 2026 5:14 PM` from the
 * DEPLOYED_AT manifest. The two surfaces must agree — both should resolve
 * via the same fallbacks.
 */
class JackpotConsoleReleaseTest extends TestCase
{
    protected ?string $releaseInfoPath = null;

    protected ?string $deployedAtPath = null;

    protected ?string $deployedAtBackup = null;

    protected ?string $releaseInfoBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->releaseInfoPath = base_path('.release-info.json');
        $this->deployedAtPath = base_path('DEPLOYED_AT');

        // Snapshot existing files so we never clobber a real deploy manifest
        // sitting in the repo (some local checkouts have one).
        if (is_file($this->releaseInfoPath)) {
            $this->releaseInfoBackup = (string) file_get_contents($this->releaseInfoPath);
            @unlink($this->releaseInfoPath);
        }
        if (is_file($this->deployedAtPath)) {
            $this->deployedAtBackup = (string) file_get_contents($this->deployedAtPath);
            @unlink($this->deployedAtPath);
        }

        config()->set('jackpot_console.build_time', null);
    }

    protected function tearDown(): void
    {
        @unlink($this->releaseInfoPath);
        @unlink($this->deployedAtPath);
        if ($this->releaseInfoBackup !== null) {
            file_put_contents($this->releaseInfoPath, $this->releaseInfoBackup);
        }
        if ($this->deployedAtBackup !== null) {
            file_put_contents($this->deployedAtPath, $this->deployedAtBackup);
        }
        parent::tearDown();
    }

    public function test_release_info_json_takes_precedence(): void
    {
        file_put_contents($this->releaseInfoPath, json_encode([
            'committed_at' => '2026-05-11T21:14:00+00:00',
            'commit' => 'abcdef1234567890',
        ]));
        // DEPLOYED_AT is also present but ignored because .release-info.json wins.
        file_put_contents($this->deployedAtPath, "Deployed at: Mon May 12 09:00:00 UTC 2026\nCommit: feedface\n");

        $this->assertSame('2026-05-11T21:14:00+00:00', JackpotConsoleRelease::committedAtIso8601());
        $this->assertSame('abcdef12', JackpotConsoleRelease::commitShortSha());
    }

    public function test_falls_back_to_deployed_at_manifest_for_staging(): void
    {
        // This is the exact scenario operators saw on staging — no
        // .release-info.json, no APP_BUILD_TIME, but DEPLOYED_AT exists
        // from web-mirror-deploy.sh.
        file_put_contents($this->deployedAtPath, implode("\n", [
            'Deployed at:  Mon May 11 21:14:00 UTC 2026',
            'Release:      20260511-211321-r592-manual',
            'Git ref:      main',
            'Commit:       c77c0c9abcdef0123456789',
            'Author:       deploy',
            'Message:      many updates',
        ]));

        $iso = JackpotConsoleRelease::committedAtIso8601();
        $this->assertNotNull($iso, 'Must resolve via DEPLOYED_AT manifest — staging should never fall through to local');
        $this->assertSame('2026-05-11T21:14:00+00:00', $iso);

        $this->assertSame('c77c0c9a', JackpotConsoleRelease::commitShortSha());
    }

    public function test_returns_null_when_no_metadata_anywhere(): void
    {
        // No files, no env config. In dev with .git checked in this MAY
        // resolve via git fallback — accept either null OR a valid ISO.
        $iso = JackpotConsoleRelease::committedAtIso8601();
        if ($iso !== null) {
            $this->assertNotFalse(strtotime($iso), 'Git fallback must return a parseable ISO timestamp');
        }
    }

    public function test_invalid_committed_at_string_does_not_crash(): void
    {
        file_put_contents($this->deployedAtPath, "Deployed at: not-a-real-date\nCommit: abcdef1\n");

        // Must not throw — should fall through to git or null.
        $iso = JackpotConsoleRelease::committedAtIso8601();
        $this->assertTrue($iso === null || strtotime($iso) !== false);
        $this->assertSame('abcdef1', JackpotConsoleRelease::commitShortSha());
    }

    public function test_short_sha_rejects_garbage_values(): void
    {
        // Manifests sometimes get hand-edited; never echo non-hex into the
        // banner. A subsequent .git fallback may legitimately resolve a
        // real SHA — but the garbage value itself must never appear.
        file_put_contents($this->deployedAtPath, implode("\n", [
            'Deployed at:  Mon May 11 21:14:00 UTC 2026',
            'Commit:       not-a-sha-just-text',
        ]));
        $sha = JackpotConsoleRelease::commitShortSha();
        if ($sha !== null) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7,8}$/', $sha,
                'Even when falling through to git fallback, the resulting SHA must be hex');
            $this->assertStringNotContainsString('not-a-sha', $sha,
                'Garbage manifest value must be rejected, never echoed back');
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_release_info_sha_alias_field_is_accepted(): void
    {
        file_put_contents($this->releaseInfoPath, json_encode([
            'time' => '2026-04-01T00:00:00Z',
            'sha' => 'DEADBEEFCAFE1234',
        ]));

        $this->assertSame('2026-04-01T00:00:00Z', JackpotConsoleRelease::committedAtIso8601());
        $this->assertSame('deadbeef', JackpotConsoleRelease::commitShortSha(),
            'Short SHA must be lowercased for stable comparison + a clean badge');
    }
}

<?php

namespace Tests\Unit;

use App\Studio\Animation\Analysis\StartFrameDriftAnalyzer;
use PHPUnit\Framework\TestCase;

final class StartFrameDriftAnalyzerTest extends TestCase
{
    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_match_when_server_and_client_bytes_identical(): void
    {
        $bin = (string) base64_decode(self::TINY_PNG, true);
        $h = hash('sha256', $bin);
        $a = new StartFrameDriftAnalyzer;
        $r = $a->analyze($bin, $bin, $h, $h);
        $this->assertSame('match', $r['frame_drift_status']);
        $this->assertSame(0.0, $r['frame_drift_score']);
    }

    public function test_mismatch_when_declared_server_hash_differs_from_client(): void
    {
        $bin = (string) base64_decode(self::TINY_PNG, true);
        $clientHash = hash('sha256', $bin);
        $a = new StartFrameDriftAnalyzer;
        $r = $a->analyze($bin, $bin, str_repeat('a', 64), $clientHash);
        $this->assertSame('mismatch', $r['frame_drift_status']);
        $this->assertContains('sha256_mismatch', $r['mismatch_reasons']);
    }

    public function test_unavailable_without_server_frame(): void
    {
        $bin = (string) base64_decode(self::TINY_PNG, true);
        $a = new StartFrameDriftAnalyzer;
        $r = $a->analyze(null, $bin, null, hash('sha256', $bin));
        $this->assertSame('unavailable', $r['frame_drift_status']);
    }
}

<?php

namespace App\Studio\Animation\Analysis;

/**
 * Compares server canonical PNG vs client snapshot for observability (not a hard gate).
 */
final class StartFrameDriftAnalyzer
{
    /**
     * @return array{
     *   frame_drift_status: string,
     *   frame_drift_score: float|null,
     *   drift_summary: string,
     *   mismatch_reasons: list<string>
     * }
     */
    public function analyze(
        ?string $serverPngBinary,
        ?string $clientPngBinary,
        ?string $serverSha256,
        string $clientSha256,
    ): array {
        if ($serverPngBinary === null || $serverPngBinary === '') {
            return [
                'frame_drift_status' => 'unavailable',
                'frame_drift_score' => null,
                'drift_summary' => 'Server canonical frame was not produced; drift vs client snapshot is not applicable.',
                'mismatch_reasons' => [],
            ];
        }

        if ($clientPngBinary === null || $clientPngBinary === '') {
            return [
                'frame_drift_status' => 'unavailable',
                'frame_drift_score' => null,
                'drift_summary' => 'Client snapshot bytes were not available for comparison.',
                'mismatch_reasons' => [],
            ];
        }

        $srvDims = @getimagesizefromstring($serverPngBinary);
        $cliDims = @getimagesizefromstring($clientPngBinary);
        $sw = isset($srvDims[0]) ? (int) $srvDims[0] : null;
        $sh = isset($srvDims[1]) ? (int) $srvDims[1] : null;
        $cw = isset($cliDims[0]) ? (int) $cliDims[0] : null;
        $ch = isset($cliDims[1]) ? (int) $cliDims[1] : null;

        $reasons = [];
        if ($sw !== null && $cw !== null && $sh !== null && $ch !== null) {
            if ($sw !== $cw || $sh !== $ch) {
                $reasons[] = "dimension_mismatch server={$sw}x{$sh} client={$cw}x{$ch}";
            }
        }

        $srvHash = $serverSha256 ?? hash('sha256', $serverPngBinary);
        if ($srvHash === $clientSha256) {
            return [
                'frame_drift_status' => 'match',
                'frame_drift_score' => 0.0,
                'drift_summary' => 'Server canonical frame matches client snapshot (SHA-256).',
                'mismatch_reasons' => $reasons,
            ];
        }

        $reasons[] = 'sha256_mismatch';

        $score = $this->approximatePixelDriftScore($serverPngBinary, $clientPngBinary, $sw, $sh, $cw, $ch);

        return [
            'frame_drift_status' => 'mismatch',
            'frame_drift_score' => $score,
            'drift_summary' => 'Server canonical frame differs from the client snapshot (expected when fonts/images differ from browser).',
            'mismatch_reasons' => $reasons,
        ];
    }

    private function approximatePixelDriftScore(
        string $serverPngBinary,
        string $clientPngBinary,
        ?int $sw,
        ?int $sh,
        ?int $cw,
        ?int $ch,
    ): ?float {
        if (! extension_loaded('imagick')) {
            return null;
        }
        if ($sw === null || $sh === null || $cw === null || $ch === null) {
            return null;
        }

        try {
            $a = new \Imagick;
            $a->readImageBlob($serverPngBinary);
            $b = new \Imagick;
            $b->readImageBlob($clientPngBinary);
            $tw = min(128, $sw, $cw);
            $th = min(128, $sh, $ch);
            if ($tw < 8 || $th < 8) {
                $a->clear();
                $b->clear();
                $a->destroy();
                $b->destroy();

                return null;
            }
            $a->resizeImage($tw, $th, \Imagick::FILTER_BOX, 1, true);
            $b->resizeImage($tw, $th, \Imagick::FILTER_BOX, 1, true);
            if (! method_exists($a, 'getImageDistortion')) {
                $a->clear();
                $b->clear();
                $a->destroy();
                $b->destroy();

                return null;
            }
            $metric = $a->getImageDistortion($b, \Imagick::METRIC_MEANABSOLUTEERROR);
            $a->clear();
            $b->clear();
            $a->destroy();
            $b->destroy();

            return is_finite($metric) ? (float) $metric : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

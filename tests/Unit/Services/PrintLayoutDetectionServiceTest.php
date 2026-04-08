<?php

namespace Tests\Unit\Services;

use App\Services\PrintLayoutDetectionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrintLayoutDetectionServiceTest extends TestCase
{
    private function makePrintRegistrationLikePng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pldet_').'.png';
        $w = 640;
        $h = 820;

        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);

        $t = 22;
        imagefilledrectangle($im, 0, 0, $w - 1, $t - 1, $black);
        imagefilledrectangle($im, 0, $h - $t, $w - 1, $h - 1, $black);
        imagefilledrectangle($im, 0, 0, $t - 1, $h - 1, $black);
        imagefilledrectangle($im, $w - $t, 0, $w - 1, $h - 1, $black);

        $gray = imagecolorallocate($im, 200, 200, 200);
        imagefilledrectangle($im, 160, 220, $w - 160, $h - 220, $gray);

        $stripeW = 46;
        $segH = (int) ($h * 0.11);
        $y = (int) ($h * 0.14);
        $cC = imagecolorallocate($im, 0, 255, 255);
        $cM = imagecolorallocate($im, 255, 0, 255);
        $cY = imagecolorallocate($im, 255, 255, 0);
        $cK = imagecolorallocate($im, 35, 35, 35);
        foreach ([$cC, $cM, $cY, $cK] as $col) {
            imagefilledrectangle($im, 2, $y, $stripeW, $y + $segH - 1, $col);
            $y += $segH + 5;
        }

        for ($i = 0; $i < 20; $i++) {
            imageline($im, 0, 3 + $i * 3, $w - 1, 3 + $i * 3, $black);
        }

        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function makeSmoothProductLikePng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pldet_norm_').'.png';
        $im = imagecreatetruecolor(640, 820);
        $fill = imagecolorallocate($im, 248, 247, 250);
        imagefill($im, 0, 0, $fill);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    #[Test]
    public function detects_print_registration_heuristics(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $path = $this->makePrintRegistrationLikePng();
        try {
            $svc = new PrintLayoutDetectionService;
            $r = $svc->detectPrintLayout($path);

            $this->assertTrue($r['is_print_layout'], 'Expected print layout: '.json_encode($r['signals']));
            $this->assertGreaterThanOrEqual(0.5, $r['confidence']);
            $this->assertTrue($r['signals']['edge_lines'] || $r['signals']['corner_marks']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function does_not_flag_smooth_product_like_image(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick extension required');
        }

        $path = $this->makeSmoothProductLikePng();
        try {
            $svc = new PrintLayoutDetectionService;
            $r = $svc->detectPrintLayout($path);

            $this->assertFalse($r['is_print_layout']);
            $this->assertLessThan(0.5, $r['confidence']);
        } finally {
            @unlink($path);
        }
    }
}

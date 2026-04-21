<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Free-plan tenants without an AI credit pack addon get a small Jackpot cherry mark on
 * editor-published and AI-generated raster outputs (server-side).
 *
 * Credit pack = {@see Tenant::$ai_credits_addon} &gt; 0 (monthly pool bump from billing).
 *
 * Output is always PNG so callers can keep `.png` paths and consistent mime when applicable.
 */
final class FreePlanImageWatermarkService
{
    public function __construct(
        protected PlanService $planService
    ) {}

    public function shouldWatermark(Tenant $tenant): bool
    {
        if ($this->planService->getCurrentPlan($tenant) !== 'free') {
            return false;
        }

        return (int) ($tenant->ai_credits_addon ?? 0) <= 0;
    }

    /**
     * @return string Same bytes when ineligible or on failure; otherwise PNG binary.
     */
    public function applyIfEligible(Tenant $tenant, string $imageBinary): string
    {
        if (! $this->shouldWatermark($tenant)) {
            return $imageBinary;
        }

        $wmPath = public_path('jp-parts/cherry-slot-watermark-raster.png');
        if (! is_file($wmPath) || ! is_readable($wmPath)) {
            Log::warning('[FreePlanImageWatermark] Raster watermark file missing', ['path' => $wmPath]);

            return $imageBinary;
        }

        if (! function_exists('imagecreatefromstring')) {
            return $imageBinary;
        }

        $base = @imagecreatefromstring($imageBinary);
        if ($base === false) {
            return $imageBinary;
        }

        $wm = null;
        $wmScaled = null;

        try {
            $wmBytes = @file_get_contents($wmPath);
            if ($wmBytes === false || $wmBytes === '') {
                return $imageBinary;
            }

            $wm = @imagecreatefromstring($wmBytes);
            if ($wm === false) {
                return $imageBinary;
            }

            $bw = imagesx($base);
            $bh = imagesy($base);
            $ww0 = imagesx($wm);
            $wh0 = imagesy($wm);
            if ($bw < 32 || $bh < 32 || $ww0 < 2 || $wh0 < 2) {
                return $imageBinary;
            }

            $targetW = (int) max(28, min(56, (int) round($bw * 0.07)));
            $targetH = (int) max(28, round($wh0 * ($targetW / $ww0)));

            $wmScaled = imagecreatetruecolor($targetW, $targetH);
            if ($wmScaled === false) {
                return $imageBinary;
            }
            imagealphablending($wmScaled, false);
            imagesavealpha($wmScaled, true);
            $transparent = imagecolorallocatealpha($wmScaled, 0, 0, 0, 127);
            imagefill($wmScaled, 0, 0, $transparent);
            imagealphablending($wmScaled, true);
            imagecopyresampled($wmScaled, $wm, 0, 0, 0, 0, $targetW, $targetH, $ww0, $wh0);

            $margin = (int) max(6, min(14, (int) round($bw * 0.012)));
            $dx = $bw - $targetW - $margin;
            $dy = $bh - $targetH - $margin;
            if ($dx < 0 || $dy < 0) {
                return $imageBinary;
            }

            if (! imageistruecolor($base)) {
                imagepalettetotruecolor($base);
            }
            imagealphablending($base, true);
            imagesavealpha($base, true);
            imagecopy($base, $wmScaled, $dx, $dy, 0, 0, $targetW, $targetH);

            imagealphablending($base, false);
            imagesavealpha($base, true);
            ob_start();
            imagepng($base, null, 6);
            $out = ob_get_clean();

            if (! is_string($out) || $out === '') {
                return $imageBinary;
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[FreePlanImageWatermark] Failed to apply watermark', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return $imageBinary;
        } finally {
            if ($wmScaled instanceof \GdImage) {
                imagedestroy($wmScaled);
            }
            if ($wm instanceof \GdImage) {
                imagedestroy($wm);
            }
            imagedestroy($base);
        }
    }
}

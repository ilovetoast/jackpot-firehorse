<?php

namespace App\Studio\Animation\Analysis;

final class CompositionAnimationPreflightAnalyzer
{
    private const SMALL_TEXT_PX = 18;

    private const LEGAL_NAME_HINTS = ['terms', 'legal', 'disclaimer', 'fine print', 'privacy', '©', 'copyright'];

    private const LOGO_NAME_HINTS = ['logo', 'wordmark', 'brand mark', 'lockup', 'badge', 'trademark', 'tm', '®'];

    /**
     * @param  array<string, mixed>|null  $documentJson
     * @return array{
     *   has_high_text_density: bool,
     *   has_logo_prominence: bool,
     *   has_small_text: bool,
     *   risk_level: string,
     *   warning_messages: list<string>,
     *   metrics: array<string, mixed>
     * }
     */
    public function analyze(?array $documentJson, int $canvasWidth, int $canvasHeight): array
    {
        if ($documentJson === null || $canvasWidth <= 0 || $canvasHeight <= 0) {
            return $this->emptyPayload('No composition document was provided for analysis.');
        }

        $layers = $documentJson['layers'] ?? null;
        if (! is_array($layers)) {
            return $this->emptyPayload('Composition has no layer list.');
        }

        $canvasArea = max(1, $canvasWidth * $canvasHeight);

        $visibleTextLayers = 0;
        $textAreaSum = 0.0;
        $smallTextAreaSum = 0.0;
        $longCopyChars = 0;
        $logoHits = 0;
        $legalHits = 0;

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['visible'] ?? true) !== true) {
                continue;
            }

            $type = (string) ($layer['type'] ?? '');
            $name = strtolower((string) ($layer['name'] ?? ''));

            if ($type === 'text') {
                $visibleTextLayers++;
                $tw = (float) ($layer['transform']['width'] ?? 0);
                $th = (float) ($layer['transform']['height'] ?? 0);
                $box = max(0.0, $tw * $th);
                $textAreaSum += $box;

                $fontSize = (float) ($layer['style']['fontSize'] ?? 0);
                if ($fontSize > 0 && $fontSize < self::SMALL_TEXT_PX) {
                    $smallTextAreaSum += $box;
                }

                $content = (string) ($layer['content'] ?? '');
                if (strlen($content) > 220) {
                    $longCopyChars += strlen($content);
                }
            }

            foreach (self::LOGO_NAME_HINTS as $hint) {
                if ($name !== '' && str_contains($name, $hint)) {
                    $logoHits++;

                    break;
                }
            }
            foreach (self::LEGAL_NAME_HINTS as $hint) {
                if ($name !== '' && str_contains($name, $hint)) {
                    $legalHits++;

                    break;
                }
            }
        }

        $textAreaRatio = $textAreaSum / $canvasArea;
        $smallTextRatio = $smallTextAreaSum / max(1.0, $textAreaSum > 0 ? $textAreaSum : $canvasArea);

        $hasHighTextDensity = $visibleTextLayers >= 4 || $textAreaRatio >= 0.22 || $longCopyChars >= 800;
        $hasSmallText = $smallTextAreaSum > 0 && ($smallTextRatio >= 0.25 || $textAreaRatio >= 0.08);
        $hasLogoProminence = $logoHits >= 1 && $textAreaRatio >= 0.06;
        $denseLegal = $legalHits >= 1 && $visibleTextLayers >= 2;

        $score = 0;
        if ($hasHighTextDensity) {
            $score += 2;
        }
        if ($hasSmallText) {
            $score += 2;
        }
        if ($hasLogoProminence) {
            $score += 1;
        }
        if ($denseLegal) {
            $score += 1;
        }

        $riskLevel = 'low';
        if ($score >= 4) {
            $riskLevel = 'high';
        } elseif ($score >= 2) {
            $riskLevel = 'medium';
        }

        $warnings = [];
        if ($hasHighTextDensity) {
            $warnings[] = 'High text density may cause warped typography in AI video.';
        }
        if ($hasSmallText) {
            $warnings[] = 'Small text regions are often unreadable after motion.';
        }
        if ($hasLogoProminence) {
            $warnings[] = 'Logo-heavy layouts may drift or smear brand marks.';
        }
        if ($denseLegal) {
            $warnings[] = 'Dense legal or informational overlays are hard for video models to preserve.';
        }

        return [
            'has_high_text_density' => $hasHighTextDensity,
            'has_logo_prominence' => $hasLogoProminence,
            'has_small_text' => $hasSmallText,
            'risk_level' => $riskLevel,
            'warning_messages' => $warnings,
            'metrics' => [
                'visible_text_layer_count' => $visibleTextLayers,
                'text_bounding_area_ratio' => round($textAreaRatio, 4),
                'small_text_area_ratio_of_text' => $textAreaSum > 0 ? round($smallTextRatio, 4) : 0.0,
                'long_copy_characters' => $longCopyChars,
                'logo_name_hits' => $logoHits,
                'legal_name_hits' => $legalHits,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $reason): array
    {
        return [
            'has_high_text_density' => false,
            'has_logo_prominence' => false,
            'has_small_text' => false,
            'risk_level' => 'low',
            'warning_messages' => [],
            'metrics' => [
                'note' => $reason,
            ],
        ];
    }
}

<?php

namespace App\Services\BrandDNA;

use App\Enums\AITaskType;
use App\Models\Tenant;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7: AI extraction of brand signals from normalized data.
 * Returns strict JSON: messaging_themes, tone_indicators, industry_guess, visual_style, color_profile, confidence_score.
 */
class BrandBootstrapSignalExtractionService
{
    public function __construct(
        private AIService $aiService
    ) {}

    /**
     * @return array{ai_signals: array, tokens_in: int, tokens_out: int, cost: float}
     */
    public function extract(array $normalized, string $sourceUrl, Tenant $tenant, int $runId): array
    {
        $domain = parse_url($sourceUrl, PHP_URL_HOST) ?? '';
        $context = [
            'domain' => $domain,
            'meta' => $normalized['meta'] ?? [],
            'headlines' => $normalized['headlines'] ?? [],
            'colors_detected' => $normalized['colors_detected'] ?? [],
            'navigation_labels' => array_column($normalized['navigation']['links'] ?? [], 'label'),
            'logo_candidates' => $normalized['branding']['logo_candidates'] ?? [],
        ];

        $schema = <<<'SCHEMA'
{
  "messaging_themes": [],
  "tone_indicators": [],
  "industry_guess": "",
  "visual_style": "",
  "color_profile": "",
  "confidence_score": 0
}
SCHEMA;

        $prompt = 'You are a brand strategist. Return ONLY valid JSON. No commentary. No markdown. Match the schema exactly.'
            . "\n\nScraped website data:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "\n\nRequired JSON schema (output exactly this structure with inferred values; confidence_score 0-100):\n" . $schema;

        $result = $this->aiService->executeAgent(
            'brand_bootstrap_signal_extraction',
            AITaskType::BRAND_BOOTSTRAP_SIGNAL_EXTRACTION,
            $prompt,
            [
                'tenant' => $tenant,
                'temperature' => 0.3,
                'max_tokens' => 1500,
                'brand_bootstrap_run_id' => $runId,
            ]
        );

        $text = trim($result['text'] ?? '');
        $json = $this->parseJson($text);

        return [
            'ai_signals' => $json,
            'tokens_in' => $result['tokens_in'] ?? 0,
            'tokens_out' => $result['tokens_out'] ?? 0,
            'cost' => $result['cost'] ?? 0.0,
        ];
    }

    protected function parseJson(string $text): array
    {
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[BrandBootstrapSignalExtractionService] Invalid JSON', [
                'preview' => substr($text, 0, 500),
                'error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('AI returned invalid JSON: ' . json_last_error_msg());
        }

        $default = [
            'messaging_themes' => [],
            'tone_indicators' => [],
            'industry_guess' => '',
            'visual_style' => '',
            'color_profile' => '',
            'confidence_score' => 0,
        ];

        return array_merge($default, $decoded);
    }
}

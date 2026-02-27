<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\PdfTextAiStructure;
use App\Models\PdfTextExtraction;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Structures extracted PDF text via AI (document_type, summary).
 *
 * - Only runs on completed extraction; uses getTextForAi() (truncated).
 * - Stores result in pdf_text_ai_structures; never corrupts asset or extraction metadata.
 * - Re-runnable when reprocess is true (otherwise skips if structure already exists).
 */
class PdfTextAiStructuringService
{
    public const MIN_CHARACTER_COUNT = 200;

    public function __construct(
        protected AIService $aiService
    ) {
    }

    /**
     * Run AI structuring for this extraction. Creates one PdfTextAiStructure record.
     *
     * @param bool $reprocess If false, skips when a structure already exists for this extraction.
     * @return PdfTextAiStructure
     * @throws RuntimeException When guardrails fail or AI/store fails.
     */
    public function run(PdfTextExtraction $extraction, bool $reprocess = false): PdfTextAiStructure
    {
        $this->guardrails($extraction, $reprocess);

        $extraction->loadMissing(['asset.tenant']);
        $asset = $extraction->asset;
        if (!$asset || !$asset->tenant) {
            throw new RuntimeException('Extraction must belong to an asset with a tenant.');
        }

        $textForAi = $extraction->getTextForAi();
        $prompt = $this->buildPrompt($textForAi);

        $options = [
            'tenant' => $asset->tenant,
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 2048,
            'temperature' => 0.2,
        ];

        $result = $this->aiService->executeAgent(
            'pdf_structure',
            AITaskType::PDF_DOCUMENT_STRUCTURE,
            $prompt,
            $options
        );

        $structured = $this->parseStructuredResponse($result['text'] ?? '');
        $confidence = $this->confidenceScore($structured);

        if ($reprocess) {
            PdfTextAiStructure::where('pdf_text_extraction_id', $extraction->id)->delete();
        }

        $summary = $structured['summary'] ?? null;
        if (is_array($summary)) {
            $summary = implode(' ', $summary);
        }

        $structure = PdfTextAiStructure::create([
            'asset_id' => $asset->id,
            'pdf_text_extraction_id' => $extraction->id,
            'ai_model' => $result['model'] ?? 'unknown',
            'structured_json' => $structured,
            'summary' => is_string($summary) ? $summary : null,
            'confidence_score' => $confidence,
            'status' => 'complete',
        ]);

        Log::info('[PdfTextAiStructuringService] Structure created', [
            'extraction_id' => $extraction->id,
            'asset_id' => $asset->id,
            'document_type' => $structured['document_type'] ?? null,
            'confidence' => $confidence,
        ]);

        return $structure;
    }

    /**
     * Guardrails before calling AI. Throws on failure.
     */
    protected function guardrails(PdfTextExtraction $extraction, bool $reprocess): void
    {
        if ($extraction->status !== PdfTextExtraction::STATUS_COMPLETE) {
            throw new RuntimeException('Extraction must be complete before AI structuring (status: ' . ($extraction->status ?? 'null') . ').');
        }

        $count = $extraction->character_count ?? 0;
        if ($count < self::MIN_CHARACTER_COUNT) {
            throw new RuntimeException('Extraction character count too low for AI structuring (min ' . self::MIN_CHARACTER_COUNT . ', got ' . $count . ').');
        }

        if ($count > PdfTextExtraction::AI_MAX_CHARS) {
            throw new RuntimeException('Extraction character count exceeds AI limit (' . PdfTextExtraction::AI_MAX_CHARS . ').');
        }

        if (!$reprocess && PdfTextAiStructure::where('pdf_text_extraction_id', $extraction->id)->exists()) {
            throw new RuntimeException('An AI structure already exists for this extraction. Use reprocess=true to replace.');
        }
    }

    protected function buildPrompt(string $textForAi): string
    {
        $preview = mb_substr($textForAi, 0, 8000);
        if (mb_strlen($textForAi) > 8000) {
            $preview .= "\n\n[... text truncated for analysis ...]";
        }

        return <<<PROMPT
Analyze the following text extracted from a PDF and respond with a single JSON object.

Required fields:
- document_type: one of "brand_guideline", "marketing_deck", "spec_sheet", or "other"
- summary: a short plain-language summary (string, 1-3 sentences)

Optional fields (if detectable):
- sections: array of section titles or headings
- has_color_palette: boolean
- has_typography_rules: boolean

Rules:
- Use "brand_guideline" only for brand guidelines, style guides, or brand books.
- Use "marketing_deck" for pitch decks, campaign one-pagers, or marketing collateral.
- Use "spec_sheet" for technical specs, product specs, or requirements.
- Use "other" for anything that does not clearly fit.

Text to analyze:

{$preview}

Respond with only valid JSON, no markdown or explanation.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseStructuredResponse(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['document_type' => 'other', 'summary' => ''];
        }
        // Strip optional markdown code fence
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```\s*$/s', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return ['document_type' => 'other', 'summary' => mb_substr($trimmed, 0, 500)];
        }
        return $decoded;
    }

    /**
     * Deterministic v1 confidence from document_type.
     */
    protected function confidenceScore(array $structured): float
    {
        return match ($structured['document_type'] ?? null) {
            'brand_guideline' => 0.9,
            'marketing_deck' => 0.7,
            'spec_sheet' => 0.75,
            default => 0.4,
        };
    }
}

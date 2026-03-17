<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandSnapshotSuggestionService;
use PHPUnit\Framework\TestCase;

/**
 * Brand Snapshot Suggestion Service — unit tests.
 */
class BrandSnapshotSuggestionServiceTest extends TestCase
{
    public function test_color_mismatch_generates_palette_suggestion(): void
    {
        $service = new BrandSnapshotSuggestionService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => ['#000000']],
        ];
        $snapshotRaw = [
            'primary_colors' => ['#FFFFFF'],
        ];
        $coherence = ['sections' => []];

        $result = $service->generate($draftPayload, $snapshotRaw, $coherence);

        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];
        $paletteSuggestion = null;
        foreach ($suggestions as $s) {
            if (($s['key'] ?? '') === 'SUG:standards.allowed_color_palette') {
                $paletteSuggestion = $s;
                break;
            }
        }
        $this->assertNotNull($paletteSuggestion, 'Suggestion with key SUG:standards.allowed_color_palette must exist');
        $this->assertSame('scoring_rules.allowed_color_palette', $paletteSuggestion['path']);
        $this->assertSame(['#FFFFFF'], $paletteSuggestion['value']);
        $this->assertGreaterThanOrEqual(0, $paletteSuggestion['confidence']);
        $this->assertLessThanOrEqual(1, $paletteSuggestion['confidence']);
    }

    public function test_font_mismatch_generates_typography_suggestion(): void
    {
        $service = new BrandSnapshotSuggestionService;
        $draftPayload = [
            'typography' => ['primary_font' => 'Arial'],
        ];
        $snapshotRaw = [
            'detected_fonts' => ['Roboto'],
        ];
        $coherence = ['sections' => []];

        $result = $service->generate($draftPayload, $snapshotRaw, $coherence);

        $this->assertArrayHasKey('suggestions', $result);
        $suggestions = $result['suggestions'];
        $fontSuggestion = null;
        foreach ($suggestions as $s) {
            if (($s['key'] ?? '') === 'SUG:standards.primary_font') {
                $fontSuggestion = $s;
                break;
            }
        }
        $this->assertNotNull($fontSuggestion, 'Suggestion with key SUG:standards.primary_font must exist');
        $this->assertSame('typography.primary_font', $fontSuggestion['path']);
        $this->assertSame('Roboto', $fontSuggestion['value']);
    }

    public function test_color_match_does_not_generate_palette_suggestion(): void
    {
        $service = new BrandSnapshotSuggestionService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => ['#000000']],
        ];
        $snapshotRaw = [
            'primary_colors' => ['#000000'],
        ];
        $coherence = ['sections' => []];

        $result = $service->generate($draftPayload, $snapshotRaw, $coherence);

        $paletteSuggestions = array_filter($result['suggestions'] ?? [], fn ($s) => ($s['key'] ?? '') === 'SUG:standards.allowed_color_palette');
        $this->assertEmpty($paletteSuggestions, 'No palette suggestion when colors match');
    }

    public function test_logo_description_generates_informational_suggestion(): void
    {
        $service = new BrandSnapshotSuggestionService;
        $draftPayload = [];
        $snapshotRaw = [
            'logo_description' => 'Wordmark using VG ligature, red on dark background, minimum clear space of 1x logo height',
        ];
        $coherence = ['sections' => []];

        $result = $service->generate($draftPayload, $snapshotRaw, $coherence);

        $logoSuggestions = array_filter($result['suggestions'] ?? [], fn ($s) => str_contains($s['key'] ?? '', 'logo'));
        $this->assertNotEmpty($logoSuggestions, 'Logo suggestion should be generated');
    }
}

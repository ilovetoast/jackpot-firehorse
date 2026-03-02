<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandResearchSnapshot;
use App\Services\BrandDNA\BrandAlignmentEngine;
use App\Services\BrandDNA\BrandArchetypeSuggestionService;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use App\Services\BrandDNA\BrandSnapshotSuggestionService;
use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Brand research job. Snapshot-centric flow:
 * Crawl → Structured Snapshot → Coherence → Suggestions → Persist Snapshot
 *
 * Draft is read-only; research never mutates draft fields.
 */
class RunBrandResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $brandId,
        public int $brandModelVersionId,
        public string $sourceUrl
    ) {}

    public function handle(
        BrandWebsiteCrawlerService $crawlerService,
        BrandCoherenceScoringService $coherenceService,
        BrandAlignmentEngine $alignmentEngine
    ): void {
        $brand = Brand::find($this->brandId);
        if (! $brand) {
            return;
        }

        $draft = BrandModelVersion::find($this->brandModelVersionId);
        if (! $draft || ($draft->brandModel?->brand_id ?? null) !== $brand->id) {
            return;
        }

        $snapshot = BrandResearchSnapshot::create([
            'brand_id' => $brand->id,
            'brand_model_version_id' => $draft->id,
            'source_url' => $this->sourceUrl,
            'status' => 'running',
        ]);

        // 1. Crawl
        $crawlData = $crawlerService->crawl($this->sourceUrl);

        // 2. Build structured snapshot
        $structuredSnapshot = $this->buildStructuredSnapshot($crawlData);

        // 3. Update snapshot record (status=running, snapshot=structuredSnapshot)
        $snapshot->update([
            'snapshot' => $structuredSnapshot,
        ]);

        // 4. Run coherence (draft read-only)
        $draftPayload = $draft->model_payload ?? [];
        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
        $baseSuggestions = $this->generateSuggestions($draftPayload);

        $coherence = $coherenceService->score(
            $draftPayload,
            $baseSuggestions,
            $structuredSnapshot,
            $brand,
            $brandMaterialCount
        );

        // 5. Generate suggestions (base, snapshot, archetype)
        $snapshotSuggestions = app(BrandSnapshotSuggestionService::class)
            ->generate($draftPayload, $structuredSnapshot, $coherence);

        $suggestions = array_merge(
            $baseSuggestions,
            $snapshotSuggestions['suggestions'] ?? []
        );

        $archetypeSuggestions = app(BrandArchetypeSuggestionService::class)
            ->generate($draftPayload);

        $suggestions = array_merge(
            $suggestions,
            $archetypeSuggestions['suggestions'] ?? []
        );

        // 6. Deduplicate by key
        $suggestions = $this->deduplicateSuggestionsByKey($suggestions);

        // 7. Run alignment (draft read-only)
        $alignment = $alignmentEngine->analyze($draftPayload);

        // 8. Final snapshot update: status=completed, coherence, suggestions, alignment
        $snapshot->update([
            'status' => 'completed',
            'coherence' => $coherence,
            'suggestions' => $suggestions,
            'alignment' => $alignment,
        ]);

        $draft->getOrCreateInsightState($snapshot->id);
    }

    protected function buildStructuredSnapshot(array $crawlData): array
    {
        return [
            'logo_url' => $crawlData['logo_url'] ?? null,
            'primary_colors' => $crawlData['primary_colors'] ?? [],
            'detected_fonts' => $crawlData['detected_fonts'] ?? [],
            'hero_headlines' => $crawlData['hero_headlines'] ?? [],
            'brand_bio' => $crawlData['brand_bio'] ?? null,
        ];
    }

    protected function generateSuggestions(array $draftPayload): array
    {
        $suggestions = [];

        // Recommended archetypes — derive from draft when no archetype selected.
        // Future: use snapshot.crawl (hero_headlines, brand_bio) for ML-based suggestions.
        $personality = $draftPayload['personality'] ?? [];
        $primary = $personality['primary_archetype'] ?? $personality['archetype'] ?? null;
        if (! $primary) {
            $identity = $draftPayload['identity'] ?? [];
            $industry = strtolower(trim((string) ($identity['industry'] ?? '')));
            $mission = strtolower(trim((string) ($identity['mission'] ?? '')));
            $text = $industry . ' ' . $mission;
            $suggestions['recommended_archetypes'] = $this->suggestArchetypesFromText($text);
        }

        return $suggestions;
    }

    /**
     * Deduplicate structured suggestions (those with 'key') by key. Later wins.
     * Preserves legacy items (e.g. recommended_archetypes) unchanged.
     * Ensures Apply/Dismiss persistence remains stable.
     */
    protected function deduplicateSuggestionsByKey(array $suggestions): array
    {
        $legacy = [];
        $structured = [];

        foreach ($suggestions as $k => $v) {
            if (is_array($v) && isset($v['key'])) {
                $structured[$v['key']] = $v;
            } else {
                $legacy[$k] = $v;
            }
        }

        return array_merge($legacy, array_values($structured));
    }

    /**
     * Simple keyword-based archetype suggestions. Replace with crawl/ML when available.
     */
    protected function suggestArchetypesFromText(string $text): array
    {
        $hints = [
            'Creator' => ['creative', 'design', 'art', 'innovation', 'build', 'make'],
            'Caregiver' => ['care', 'health', 'support', 'help', 'community', 'family'],
            'Ruler' => ['lead', 'premium', 'luxury', 'authority', 'control', 'enterprise'],
            'Sage' => ['learn', 'knowledge', 'education', 'insight', 'data', 'research'],
            'Hero' => ['challenge', 'performance', 'sport', 'strength', 'achieve'],
            'Explorer' => ['adventure', 'travel', 'discover', 'explore', 'freedom'],
            'Everyman' => ['everyday', 'simple', 'honest', 'real', 'authentic'],
        ];
        $scores = [];
        foreach ($hints as $archetype => $keywords) {
            $scores[$archetype] = 0;
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $scores[$archetype]++;
                }
            }
        }
        arsort($scores);
        $top = array_slice(array_keys(array_filter($scores, fn ($s) => $s > 0)), 0, 3);

        return $top ?: ['Creator', 'Everyman', 'Sage'];
    }
}

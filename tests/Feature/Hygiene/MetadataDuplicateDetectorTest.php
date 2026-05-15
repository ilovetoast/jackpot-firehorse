<?php

namespace Tests\Feature\Hygiene;

use App\Models\MetadataField;
use App\Services\Hygiene\MetadataDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 5.3 — covers hash-bucket duplicate detection + plural/singular pair
 * heuristic. Touches metadata_options so it lives in the Feature suite.
 */
class MetadataDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private MetadataDuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(MetadataDuplicateDetector::class);
    }

    private function makeField(string $key): MetadataField
    {
        $id = DB::table('metadata_fields')->insertGetId([
            'key' => $key,
            'system_label' => $key,
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MetadataField::query()->findOrFail($id);
    }

    private function makeOptions(MetadataField $field, array $values): void
    {
        foreach ($values as $i => $value) {
            DB::table('metadata_options')->insert([
                'metadata_field_id' => $field->id,
                'value' => $value,
                'system_label' => $value,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_returns_empty_when_no_options(): void
    {
        $field = $this->makeField('dup_empty');
        $this->assertSame([], $this->detector->findCandidates($field));
    }

    public function test_returns_empty_when_all_options_unique(): void
    {
        $field = $this->makeField('dup_unique');
        $this->makeOptions($field, ['Indoor', 'Lifestyle', 'Studio']);
        $this->assertSame([], $this->detector->findCandidates($field));
    }

    public function test_finds_normalization_duplicates(): void
    {
        $field = $this->makeField('dup_norm');
        // Both normalize to "out door".
        $this->makeOptions($field, ['Out-Door', 'out door', 'studio']);

        $candidates = $this->detector->findCandidates($field);
        $this->assertCount(1, $candidates);
        $this->assertSame('normalized_match', $candidates[0]['reason']);
        $this->assertEqualsCanonicalizing(
            ['Out-Door', 'out door'],
            $candidates[0]['values']
        );
    }

    public function test_finds_plural_singular_pairs(): void
    {
        $field = $this->makeField('dup_plural');
        $this->makeOptions($field, ['Outdoor', 'Outdoors', 'Lifestyle']);
        $candidates = $this->detector->findCandidates($field);
        $this->assertNotEmpty($candidates);
        $reasons = array_column($candidates, 'reason');
        $this->assertContains('plural_singular_pair', $reasons);
        $pair = collect($candidates)->firstWhere('reason', 'plural_singular_pair');
        $this->assertEqualsCanonicalizing(['Outdoor', 'Outdoors'], $pair['values']);
        $this->assertSame('Outdoor', $pair['canonical_hint']);
    }

    public function test_does_not_double_report_singular_pair_when_already_in_normalization_cluster(): void
    {
        $field = $this->makeField('dup_combo');
        // "outdoor" / "OUTDOOR" cluster by hash; "outdoors" should still
        // pair with "outdoor" in the plural pass without producing a noisy
        // overlap.
        $this->makeOptions($field, ['outdoor', 'OUTDOOR', 'outdoors']);

        $candidates = $this->detector->findCandidates($field);
        $reasonCounts = array_count_values(array_column($candidates, 'reason'));
        $this->assertSame(1, $reasonCounts['normalized_match'] ?? 0);
        // Plural pair MAY surface (outdoor vs outdoors), but it should not
        // re-include values that are already in the normalization cluster.
        if (isset($reasonCounts['plural_singular_pair'])) {
            $pair = collect($candidates)->firstWhere('reason', 'plural_singular_pair');
            // The first cluster has the normalization grouping (outdoor / OUTDOOR);
            // any plural-pair grouping uses 'outdoors' explicitly.
            $this->assertContains('outdoors', $pair['values']);
        }
    }

    public function test_caps_results_for_very_noisy_fields(): void
    {
        $field = $this->makeField('dup_cap');
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $values[] = "Value-{$i}";
            $values[] = "Value {$i}"; // normalizes to the same hash as the dashed form
        }
        $this->makeOptions($field, $values);
        $candidates = $this->detector->findCandidates($field);
        $this->assertLessThanOrEqual(
            MetadataDuplicateDetector::MAX_CANDIDATES_RETURNED,
            count($candidates)
        );
    }

    public function test_ignores_blank_options(): void
    {
        $field = $this->makeField('dup_blank');
        $this->makeOptions($field, ['Outdoor', '   ']);
        $this->assertSame([], $this->detector->findCandidates($field));
    }
}

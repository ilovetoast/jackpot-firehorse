<?php

namespace Tests\Unit\Services\Hygiene;

use App\Services\Hygiene\MetadataValueNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5.3 — pure-function tests for the value normalizer. No DB; runs in
 * the unit suite.
 */
class MetadataValueNormalizerTest extends TestCase
{
    private MetadataValueNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new MetadataValueNormalizer();
    }

    public function test_normalize_trims_and_lowercases(): void
    {
        $this->assertSame('outdoor', $this->normalizer->normalize('  Outdoor  '));
        $this->assertSame('outdoor', $this->normalizer->normalize('OUTDOOR'));
        $this->assertSame('outdoor', $this->normalizer->normalize('outdoor'));
    }

    public function test_normalize_collapses_separator_chars_to_single_space(): void
    {
        $this->assertSame('out door', $this->normalizer->normalize('out-door'));
        $this->assertSame('out door', $this->normalizer->normalize('out_door'));
        $this->assertSame('nature photo', $this->normalizer->normalize('Nature/Photo'));
        $this->assertSame('a b', $this->normalizer->normalize('a..b'));
        $this->assertSame('a b c', $this->normalizer->normalize('A.B.C'));
    }

    public function test_normalize_collapses_runs_of_whitespace(): void
    {
        $this->assertSame('out door', $this->normalizer->normalize('out   door'));
        $this->assertSame('out door', $this->normalizer->normalize("out\tdoor"));
        $this->assertSame('a b', $this->normalizer->normalize("\tA \t  B\t "));
    }

    public function test_normalize_treats_dashed_and_spaced_forms_as_equivalent(): void
    {
        $this->assertTrue($this->normalizer->equivalent('Out-Door', 'Out Door'));
        $this->assertTrue($this->normalizer->equivalent('outdoor', 'OUTDOOR'));
        $this->assertTrue($this->normalizer->equivalent(' nature photo ', 'Nature/Photo'));
    }

    public function test_normalize_does_NOT_singularize(): void
    {
        // Phase 5.3 ships conservative normalization. Plural/singular pairs
        // are surfaced by MetadataDuplicateDetector, not by the normalizer.
        $this->assertFalse($this->normalizer->equivalent('outdoor', 'outdoors'));
        $this->assertFalse($this->normalizer->equivalent('photo', 'photos'));
    }

    public function test_normalize_handles_non_string_inputs_safely(): void
    {
        $this->assertSame('', $this->normalizer->normalize(null));
        $this->assertSame('', $this->normalizer->normalize(false));
        $this->assertSame('true', $this->normalizer->normalize(true));
        $this->assertSame('123', $this->normalizer->normalize(123));
        $this->assertSame('', $this->normalizer->normalize(['anarray']));
        $this->assertSame('', $this->normalizer->normalize(new \stdClass()));
    }

    public function test_two_empty_values_are_NOT_equivalent_for_hygiene(): void
    {
        $this->assertFalse($this->normalizer->equivalent('', ''));
        $this->assertFalse($this->normalizer->equivalent(null, ''));
        $this->assertFalse($this->normalizer->equivalent('  ', null));
    }

    public function test_hash_is_deterministic_and_stable_across_inputs(): void
    {
        $a = $this->normalizer->hash('Outdoor');
        $b = $this->normalizer->hash('  outdoor');
        $c = $this->normalizer->hash('OUTDOOR');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertNotSame('', $a);
    }

    public function test_hash_changes_for_non_equivalent_values(): void
    {
        $this->assertNotSame(
            $this->normalizer->hash('outdoor'),
            $this->normalizer->hash('outdoors')
        );
        $this->assertNotSame(
            $this->normalizer->hash('outdoor'),
            $this->normalizer->hash('indoor')
        );
    }

    public function test_hash_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', $this->normalizer->hash(''));
        $this->assertSame('', $this->normalizer->hash('   '));
        $this->assertSame('', $this->normalizer->hash(null));
    }

    public function test_hash_returns_a_short_indexable_hex_string(): void
    {
        $hash = $this->normalizer->hash('Outdoor');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
    }
}

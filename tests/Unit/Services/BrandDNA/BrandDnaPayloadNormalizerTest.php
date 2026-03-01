<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandDnaPayloadNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Brand DNA Payload Normalizer — unit tests.
 */
class BrandDnaPayloadNormalizerTest extends TestCase
{
    public function test_normalizer_adds_defaults_for_new_keys_without_clobbering_existing(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'identity' => [
                'beliefs' => ['Quality first'],
                'values' => ['Integrity', 'Honesty'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        // Existing values preserved
        $this->assertSame(['Quality first'], $result['identity']['beliefs']);
        $this->assertSame(['Integrity', 'Honesty'], $result['identity']['values']);

        // New keys get defaults
        $this->assertArrayHasKey('personality', $result);
        $this->assertNull($result['personality']['primary_archetype']);
        $this->assertSame([], $result['personality']['candidate_archetypes']);
        $this->assertSame([], $result['personality']['rejected_archetypes']);

        $this->assertArrayHasKey('visual', $result);
        $this->assertNull($result['visual']['visual_density']);
        $this->assertSame([], $result['visual']['textures']);
    }

    public function test_normalizer_preserves_existing_nested_values(): void
    {
        $normalizer = new BrandDnaPayloadNormalizer;

        $payload = [
            'personality' => [
                'primary_archetype' => 'Creator',
                'candidate_archetypes' => ['Explorer', 'Sage'],
            ],
        ];

        $result = $normalizer->normalize($payload);

        $this->assertSame('Creator', $result['personality']['primary_archetype']);
        $this->assertSame(['Explorer', 'Sage'], $result['personality']['candidate_archetypes']);
        $this->assertSame([], $result['personality']['rejected_archetypes']);
    }
}

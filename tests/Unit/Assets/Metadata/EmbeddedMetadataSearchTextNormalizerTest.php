<?php

namespace Tests\Unit\Assets\Metadata;

use App\Assets\Metadata\EmbeddedMetadataSearchTextNormalizer;
use Tests\TestCase;

class EmbeddedMetadataSearchTextNormalizerTest extends TestCase
{
    public function test_lowercase_and_collapse_punctuation(): void
    {
        $n = new EmbeddedMetadataSearchTextNormalizer;

        $this->assertSame('24 70mm f 2 8', $n->normalize('24-70mm f/2.8'));
        $this->assertSame('canon eos r5', $n->normalize('Canon  EOS   R5'));
    }

    public function test_copyright_stripped_to_alphanumeric_tokens(): void
    {
        $n = new EmbeddedMetadataSearchTextNormalizer;

        $out = $n->normalize('© 2024 Acme Co.');
        $this->assertStringContainsString('2024', $out);
        $this->assertStringContainsString('acme', $out);
    }
}

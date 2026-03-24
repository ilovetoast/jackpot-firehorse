<?php

namespace Tests\Unit\Support;

use App\Support\GenerativeEditorModelNormalizer;
use PHPUnit\Framework\TestCase;

class GenerativeEditorModelNormalizerTest extends TestCase
{
    public function test_registry_key_gemini_1_5_maps_to_2_5(): void
    {
        $this->assertSame(
            'gemini-2.5-flash-image',
            GenerativeEditorModelNormalizer::normalizeRegistryKey('gemini-1.5-flash-image')
        );
    }

    public function test_registry_key_passthrough_for_current_keys(): void
    {
        $this->assertSame(
            'gemini-2.5-flash-image',
            GenerativeEditorModelNormalizer::normalizeRegistryKey('gemini-2.5-flash-image')
        );
    }

    public function test_api_model_id_gemini_1_5_maps_to_2_5(): void
    {
        $this->assertSame(
            'gemini-2.5-flash-image',
            GenerativeEditorModelNormalizer::normalizeApiModelId('gemini', 'gemini-1.5-flash-image')
        );
    }

    public function test_api_model_id_unrelated_unchanged(): void
    {
        $this->assertSame(
            'gemini-3-pro-image-preview',
            GenerativeEditorModelNormalizer::normalizeApiModelId('gemini', 'gemini-3-pro-image-preview')
        );
    }
}

<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\BrandDNA\PdfPageClassificationService;
use PHPUnit\Framework\TestCase;

class PdfPageClassificationServiceTest extends TestCase
{
    public function test_classification_result_has_required_shape(): void
    {
        $mock = \Mockery::mock(AIProviderInterface::class);
        $mock->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'page_type' => 'archetype',
                    'confidence' => 0.88,
                    'title' => 'Brand Archetype',
                    'signals_present' => ['archetype', 'tone_of_voice'],
                    'extraction_priority' => 'high',
                ]),
            ]);

        $service = new PdfPageClassificationService($mock);
        $tmp = tempnam(sys_get_temp_dir(), 'page');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $result = $service->classifyPage($tmp, 7);

        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('page_type', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('signals_present', $result);
        $this->assertArrayHasKey('extraction_priority', $result);
        $this->assertSame(7, $result['page']);
        $this->assertSame('archetype', $result['page_type']);
        $this->assertSame(0.88, $result['confidence']);
        $this->assertSame('archetype', $result['signals_present'][0] ?? null);
        $this->assertSame('high', $result['extraction_priority']);

        @unlink($tmp);
    }

    public function test_invalid_page_type_falls_back_to_unknown(): void
    {
        $mock = \Mockery::mock(AIProviderInterface::class);
        $mock->shouldReceive('analyzeImage')
            ->once()
            ->andReturn([
                'text' => json_encode([
                    'page_type' => 'invalid_type',
                    'confidence' => 0.5,
                    'title' => null,
                    'signals_present' => [],
                    'extraction_priority' => 'low',
                ]),
            ]);

        $service = new PdfPageClassificationService($mock);
        $tmp = tempnam(sys_get_temp_dir(), 'page');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $result = $service->classifyPage($tmp, 1);

        $this->assertSame('unknown', $result['page_type']);

        @unlink($tmp);
    }
}

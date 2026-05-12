<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Office\LibreOfficeDocumentPreviewService;
use App\Services\ThumbnailGenerationService;
use Mockery;
use Tests\TestCase;

final class ThumbnailGenerationServiceOfficeLibreOfficeMockTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_office_convert_once_invokes_libreoffice_only_once_on_failure(): void
    {
        config(['assets.thumbnail.office.convert_once' => true]);

        $this->app->forgetInstance(ThumbnailGenerationService::class);

        $mock = Mockery::mock(LibreOfficeDocumentPreviewService::class);
        $mock->shouldReceive('convertToPdf')
            ->once()
            ->andThrow(new \RuntimeException('LibreOffice failed to produce a PDF preview. Fatal exception: Signal 6'));
        $this->instance(LibreOfficeDocumentPreviewService::class, $mock);

        $pptx = tempnam(sys_get_temp_dir(), 'jp_office_fail_').'.pptx';
        $this->assertNotFalse(file_put_contents($pptx, 'not-a-real-deck'));

        $svc = app(ThumbnailGenerationService::class);
        $ref = new \ReflectionMethod(ThumbnailGenerationService::class, 'generateOfficeThumbnail');
        $ref->setAccessible(true);

        $style = [
            'width' => 320,
            'height' => 320,
            'quality' => 80,
            'fit' => 'contain',
            '_asset_id' => 'test-asset',
        ];

        $firstMessage = null;
        try {
            $ref->invoke($svc, $pptx, $style);
            $this->fail('Expected RuntimeException from first office style');
        } catch (\RuntimeException $first) {
            $firstMessage = $first->getMessage();
            $this->assertStringContainsString('LibreOffice failed', $firstMessage);
        }

        try {
            $ref->invoke($svc, $pptx, $style);
            $this->fail('Expected RuntimeException from second office style');
        } catch (\RuntimeException $second) {
            $this->assertSame($firstMessage, $second->getMessage());
        }

        @unlink($pptx);
    }
}

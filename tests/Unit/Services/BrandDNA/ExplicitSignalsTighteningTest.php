<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Services\BrandDNA\BrandSnapshotService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Explicit signal flags should not be set when no actual value exists.
 */
class ExplicitSignalsTighteningTest extends TestCase
{
    public function test_positioning_declared_false_when_no_positioning_value(): void
    {
        $extraction = [
            'identity' => ['mission' => null, 'positioning' => null],
            'explicit_signals' => [
                'archetype_declared' => false,
                'mission_declared' => false,
                'positioning_declared' => true,
            ],
        ];

        $service = app(BrandSnapshotService::class);
        $method = (new ReflectionClass($service))->getMethod('ensureExplicitSignalsFromExtraction');
        $method->setAccessible(true);
        $method->invokeArgs($service, [&$extraction]);

        $this->assertFalse($extraction['explicit_signals']['positioning_declared']);
    }

    public function test_mission_declared_false_when_no_mission_value(): void
    {
        $extraction = [
            'identity' => ['mission' => null, 'positioning' => 'Some positioning'],
            'explicit_signals' => [
                'mission_declared' => true,
                'positioning_declared' => true,
            ],
        ];

        $service = app(BrandSnapshotService::class);
        $method = (new ReflectionClass($service))->getMethod('ensureExplicitSignalsFromExtraction');
        $method->setAccessible(true);
        $method->invokeArgs($service, [&$extraction]);

        $this->assertFalse($extraction['explicit_signals']['mission_declared']);
        $this->assertTrue($extraction['explicit_signals']['positioning_declared']);
    }
}

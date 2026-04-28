<?php

namespace Tests\Unit\Studio\LayerExtraction;

use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use App\Studio\LayerExtraction\Sam\SamSegmentationResult;

/**
 * Test double: records inputs; returns configurable results (no real HTTP).
 */
final class RecordingFakeSamSegmentationClient implements SamSegmentationClientInterface
{
    /** @var list<array{pos: list<array{x: float, y: float}>, neg: list<array{x: float, y: float}>, options: array<string, mixed>}> */
    public array $pointCalls = [];

    /** @var list<array{box: array<string, int>, options: array<string, mixed>}> */
    public array $boxCalls = [];

    public ?SamSegmentationResult $auto = null;

    public ?SamSegmentationResult $byPoints = null;

    public ?SamSegmentationResult $byBox = null;

    public function isAvailable(): bool
    {
        return true;
    }

    public function autoSegment(string $imageBinary, array $options = []): SamSegmentationResult
    {
        return $this->auto ?? new SamSegmentationResult([], 'fake', 1, 'fake');
    }

    public function segmentWithPoints(
        string $imageBinary,
        array $positivePoints,
        array $negativePoints = [],
        array $options = []
    ): SamSegmentationResult {
        $this->pointCalls[] = [
            'pos' => $positivePoints,
            'neg' => $negativePoints,
            'options' => $options,
        ];

        return $this->byPoints ?? new SamSegmentationResult([], 'fake', 1, 'fake');
    }

    public function segmentWithBox(string $imageBinary, array $box, array $options = []): SamSegmentationResult
    {
        $this->boxCalls[] = [
            'box' => $box,
            'options' => $options,
        ];

        return $this->byBox ?? new SamSegmentationResult([], 'fake', 1, 'fake');
    }
}

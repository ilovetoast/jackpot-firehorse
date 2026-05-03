<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImageOrientationNormalizer;
use Tests\TestCase;

class ImageOrientationNormalizerTest extends TestCase
{
    public function test_orientation_read_returns_null_for_missing_file(): void
    {
        $this->assertNull(ImageOrientationNormalizer::readExifOrientationTag('/nonexistent/path/no-file.jpg'));
    }
}

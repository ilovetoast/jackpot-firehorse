<?php

namespace App\Services\BrandIntelligence\Dimensions;

use App\Models\Asset;
use App\Models\Brand;

interface DimensionEvaluatorInterface
{
    public function evaluate(Asset $asset, Brand $brand, EvaluationContext $context): DimensionResult;
}

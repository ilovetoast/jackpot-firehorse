<?php

namespace App\Studio\Animation\Contracts;

use App\Studio\Animation\Data\ProviderAnimationRequestData;
use App\Studio\Animation\Data\ProviderAnimationResultData;

interface AnimationProviderInterface
{
    public function submitImageToVideo(ProviderAnimationRequestData $request): ProviderAnimationResultData;

    public function poll(string $providerJobId): ProviderAnimationResultData;

    public function supports(string $capability): bool;
}

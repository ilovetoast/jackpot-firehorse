<?php

namespace App\Studio\Animation\Support;

use App\Models\StudioAnimationJob;

final class StudioAnimationFinalizeFingerprint
{
    public static function compute(StudioAnimationJob $job, string $remoteVideoUrl): string
    {
        $providerJobId = (string) ($job->provider_job_id ?? '');

        return hash('sha256', implode('|', [
            'v1',
            (string) $job->id,
            $providerJobId,
            $remoteVideoUrl,
        ]));
    }
}

<?php

namespace App\Studio\Animation\Support;

final class StudioAnimationFailureClassifier
{
    public static function codeForProcessorThrowable(\Throwable $e): string
    {
        if ($e instanceof StudioAnimationDriftBlockedException) {
            return 'drift_blocked';
        }
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'snapshot') || str_contains($msg, 'composition snapshot') || str_contains($msg, 'missing composition')) {
            return 'render_failed';
        }

        return 'render_failed';
    }

    public static function mapProviderSubmitErrorCode(?string $code): string
    {
        $c = (string) $code;
        if ($c === 'start_image_read_failed') {
            return 'render_failed';
        }

        return 'provider_submit_failed';
    }

    public static function mapProviderPollFailureCode(?string $ignoredProviderCode): string
    {
        return 'provider_failed';
    }
}

<?php

namespace App\Services;

use App\Enums\DerivativeProcessor;
use App\Enums\DerivativeType;
use App\Models\Asset;
use App\Models\AssetDerivativeFailure;
use App\Jobs\TriggerAssetDerivativeFailureAgentJob;
use Illuminate\Support\Facades\Log;

/**
 * Phase T-1: Records derivative generation failures for observability.
 *
 * OBSERVABILITY ONLY. Never modifies Asset.status or visibility.
 */
class AssetDerivativeFailureService
{
    public function recordFailure(
        Asset $asset,
        DerivativeType $derivativeType,
        DerivativeProcessor $processor,
        \Throwable $e,
        ?string $failureReason = null,
        ?string $codec = null,
        ?string $mime = null,
        ?array $extraMetadata = null
    ): ?AssetDerivativeFailure {
        try {
            $record = AssetDerivativeFailure::firstOrCreate(
                [
                    'asset_id' => $asset->id,
                    'derivative_type' => $derivativeType->value,
                ],
                [
                    'processor' => $processor->value,
                    'failure_reason' => $failureReason ?? $this->classifyFailureReason($e),
                    'failure_count' => 0,
                    'metadata' => [],
                ]
            );

            $errorCode = $this->classifyFailureReason($e);
            if ($errorCode === 'unknown') {
                $errorCode = class_basename($e);
            }
            $metadata = array_merge($record->metadata ?? [], [
                'exception_trace' => substr($e->getTraceAsString(), 0, 5000),
                'codec' => $codec,
                'mime' => $mime,
                'error_code' => $errorCode,
                'error_message' => $e->getMessage(),
                'retryable' => true,
            ], $extraMetadata ?? []);

            $record->increment('failure_count');
            $record->update([
                'failure_reason' => $failureReason ?? $this->classifyFailureReason($e),
                'last_failed_at' => now(),
                'metadata' => $metadata,
            ]);

            $record->refresh();

            if (AssetDerivativeFailure::shouldTriggerAgent($record)) {
                TriggerAssetDerivativeFailureAgentJob::dispatch($record->id);
            }

            return $record;
        } catch (\Throwable $logEx) {
            Log::error('[AssetDerivativeFailureService] Failed to record failure', [
                'asset_id' => $asset->id,
                'error' => $logEx->getMessage(),
            ]);

            return null;
        }
    }

    protected function classifyFailureReason(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($msg, 'memory') || str_contains($msg, 'oom')) {
            return 'oom';
        }
        if (str_contains($msg, 'codec') || str_contains($msg, 'corrupt')) {
            return 'codec_error';
        }
        if (str_contains($msg, 'permission') || str_contains($msg, 'access denied')) {
            return 'permission_error';
        }
        if (str_contains($msg, 's3') || str_contains($msg, 'getobject')) {
            return 'storage_error';
        }

        return 'unknown';
    }

    /**
     * Infer processor from exception trace/message (when not explicitly known).
     */
    public static function inferProcessorFromException(\Throwable $e): DerivativeProcessor
    {
        $trace = strtolower($e->getTraceAsString() . ' ' . $e->getMessage());
        if (str_contains($trace, 'ffmpeg') || str_contains($trace, 'video') && str_contains($trace, 'preview')) {
            return DerivativeProcessor::FFMPEG;
        }
        if (str_contains($trace, 'imagick') || str_contains($trace, 'imagemagick')) {
            return DerivativeProcessor::IMAGEMAGICK;
        }
        if (str_contains($trace, 'sharp')) {
            return DerivativeProcessor::SHARP;
        }
        if (str_contains($trace, 'gd') || str_contains($trace, 'imagecreate')) {
            return DerivativeProcessor::GD;
        }

        return DerivativeProcessor::UNKNOWN;
    }
}

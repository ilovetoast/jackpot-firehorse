<?php

namespace App\Support\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Pipeline Logger
 * 
 * Centralized logging for asset processing pipeline health monitoring.
 * 
 * Logs are gated behind PIPELINE_DEBUG environment variable to reduce noise
 * in production while maintaining observability during development/debugging.
 * 
 * Usage:
 *   PipelineLogger::info('message', ['context' => 'data']);
 *   PipelineLogger::debug('message', ['context' => 'data']);
 * 
 * Set PIPELINE_DEBUG=true in .env to enable pipeline health logs.
 */
class PipelineLogger
{
    /**
     * Check if pipeline debugging is enabled.
     * 
     * @return bool
     */
    protected static function isDebugEnabled(): bool
    {
        return config('app.pipeline_debug', false) || env('PIPELINE_DEBUG', false);
    }

    /**
     * Log pipeline health information (gated by PIPELINE_DEBUG).
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        if (!static::isDebugEnabled()) {
            return;
        }

        Log::info($message, $context);
    }

    /**
     * Log pipeline debug information (gated by PIPELINE_DEBUG).
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!static::isDebugEnabled()) {
            return;
        }

        Log::debug($message, $context);
    }

    /**
     * Log pipeline warnings (always logged, not gated).
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    /**
     * Log pipeline errors (always logged, not gated).
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }
}

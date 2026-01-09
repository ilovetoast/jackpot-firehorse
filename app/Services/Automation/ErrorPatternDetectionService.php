<?php

namespace App\Services\Automation;

use App\Enums\AITaskType;
use App\Enums\AutomationSuggestionType;
use App\Enums\LinkDesignation;
use App\Models\AIAgentRun;
use App\Models\AITicketSuggestion;
use App\Models\ErrorLog;
use App\Models\FrontendError;
use App\Models\JobFailure;
use App\Models\TicketLink;
use App\Services\AIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Error Pattern Detection Service
 *
 * Handles AI-powered error pattern detection.
 * Scheduled hourly scan of error logs, frontend errors, job failures.
 *
 * Outputs:
 * - Ticket creation suggestion (pre-filled with severity, environment, component)
 * - Links diagnostic data via TicketLink
 * - Never auto-creates tickets (admin confirmation required)
 */
class ErrorPatternDetectionService
{
    public function __construct(
        protected AIService $aiService
    ) {
    }

    /**
     * Scan error patterns and suggest internal tickets.
     *
     * @return int Number of suggestions created
     */
    public function scanErrorPatterns(): int
    {
        // Check if automation is enabled
        if (!config('automation.enabled', true) || !config('automation.triggers.error_pattern_detection.enabled', true)) {
            return 0;
        }

        $timeWindowMinutes = config('automation.triggers.error_pattern_detection.time_window_minutes', 60);
        $errorThreshold = config('automation.triggers.error_pattern_detection.error_threshold', 5);

        $windowStart = now()->subMinutes($timeWindowMinutes);
        $suggestionsCreated = 0;

        // Scan error logs by fingerprint (if we had error_fingerprint field)
        // For now, group by message hash or similar pattern
        $errorLogs = ErrorLog::where('created_at', '>=', $windowStart)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($error) {
                // Group by message pattern (simplified fingerprint)
                return md5($error->message);
            });

        foreach ($errorLogs as $fingerprint => $errors) {
            if ($errors->count() >= $errorThreshold) {
                try {
                    $this->createTicketSuggestion($errors, $fingerprint);
                    $suggestionsCreated++;
                } catch (\Exception $e) {
                    Log::error('Failed to create error pattern suggestion', [
                        'fingerprint' => $fingerprint,
                        'error_count' => $errors->count(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Scan frontend errors similarly
        $frontendErrors = FrontendError::where('created_at', '>=', $windowStart)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy(function ($error) {
                return md5($error->message . $error->error_type);
            });

        foreach ($frontendErrors as $fingerprint => $errors) {
            if ($errors->count() >= $errorThreshold) {
                try {
                    $this->createTicketSuggestion($errors, $fingerprint, 'frontend_error');
                    $suggestionsCreated++;
                } catch (\Exception $e) {
                    Log::error('Failed to create frontend error pattern suggestion', [
                        'fingerprint' => $fingerprint,
                        'error_count' => $errors->count(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $suggestionsCreated;
    }

    /**
     * Create a ticket suggestion from error pattern.
     *
     * @param \Illuminate\Database\Eloquent\Collection $errors
     * @param string $fingerprint
     * @param string $errorType
     * @return void
     */
    protected function createTicketSuggestion($errors, string $fingerprint, string $errorType = 'error_log'): void
    {
        // Build prompt from error data
        $prompt = $this->buildPrompt($errors, $fingerprint, $errorType);

        // Execute AI agent
        $response = $this->aiService->executeAgent(
            'error_pattern_analyzer',
            AITaskType::ERROR_PATTERN_ANALYSIS,
            $prompt,
            [
                'triggering_context' => 'system',
                'tenant_id' => $errors->first()->tenant_id ?? null,
            ]
        );

        // Parse AI response
        $suggestionData = $this->parseSuggestionResponse($response['text']);

        // Get agent run
        $agentRun = AIAgentRun::find($response['agent_run_id']);

        // Create suggestion (not linked to a specific ticket yet)
        $suggestion = AITicketSuggestion::create([
            'ticket_id' => null, // No ticket yet - admin must create it
            'suggestion_type' => AutomationSuggestionType::TICKET_CREATION,
            'suggested_value' => array_merge($suggestionData, [
                'error_fingerprint' => $fingerprint,
                'error_type' => $errorType,
                'error_count' => $errors->count(),
                'error_ids' => $errors->pluck('id')->toArray(),
            ]),
            'confidence_score' => $suggestionData['confidence'] ?? 0.7,
            'ai_agent_run_id' => $agentRun?->id,
            'metadata' => [
                'task_type' => AITaskType::ERROR_PATTERN_ANALYSIS,
                'cost' => $response['cost'],
                'tokens_in' => $response['tokens_in'],
                'tokens_out' => $response['tokens_out'],
                'error_sample' => $errors->first()->toArray(),
                'error_ids' => $errors->pluck('id')->toArray(),
                'error_type' => $errorType,
            ],
        ]);
    }

    /**
     * Build prompt for error pattern analysis.
     *
     * @param \Illuminate\Database\Eloquent\Collection $errors
     * @param string $fingerprint
     * @param string $errorType
     * @return string
     */
    protected function buildPrompt($errors, string $fingerprint, string $errorType): string
    {
        $sampleError = $errors->first();
        $errorCount = $errors->count();
        $firstOccurrence = $errors->last()->created_at;
        $lastOccurrence = $errors->first()->created_at;

        $errorDetails = '';
        if ($errorType === 'error_log' && $sampleError instanceof ErrorLog) {
            $errorDetails = sprintf(
                "Level: %s\nMessage: %s\nFile: %s:%d\nTrace: %s",
                $sampleError->level,
                $sampleError->message,
                $sampleError->file ?? 'N/A',
                $sampleError->line ?? 0,
                substr($sampleError->trace ?? '', 0, 500)
            );
        } elseif ($errorType === 'frontend_error' && $sampleError instanceof FrontendError) {
            $errorDetails = sprintf(
                "Type: %s\nMessage: %s\nURL: %s\nStack Trace: %s",
                $sampleError->error_type,
                $sampleError->message,
                $sampleError->url ?? 'N/A',
                substr($sampleError->stack_trace ?? '', 0, 500)
            );
        }

        $availableSeverities = implode(', ', \App\Enums\TicketSeverity::values());
        $availableComponents = implode(', ', \App\Enums\TicketComponent::values());
        $availableEnvironments = implode(', ', \App\Enums\TicketEnvironment::values());

        return <<<PROMPT
Analyze this error pattern and suggest an internal engineering ticket.

Error Fingerprint: {$fingerprint}
Error Count: {$errorCount}
First Occurrence: {$firstOccurrence->toIso8601String()}
Last Occurrence: {$lastOccurrence->toIso8601String()}

Error Details:
{$errorDetails}

Available Severities: {$availableSeverities}
Available Components: {$availableComponents}
Available Environments: {$availableEnvironments}

Please provide a JSON response with the following structure:
{
    "subject": "brief subject line for the ticket",
    "description": "detailed description of the error pattern",
    "severity": "one of the available severities",
    "component": "one of the available components",
    "environment": "one of the available environments (likely production)",
    "confidence": 0.0 to 1.0,
    "reasoning": "brief explanation"
}
PROMPT;
    }

    /**
     * Parse AI response for ticket suggestion data.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseSuggestionResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $jsonMatch = [];
        if (preg_match('/\{[^}]+\}/s', $responseText, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        // Fallback: try to parse the entire response as JSON
        $data = json_decode($responseText, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        Log::warning('Failed to parse error pattern suggestion response as JSON', [
            'response' => $responseText,
        ]);

        return [
            'subject' => 'Error Pattern Detected',
            'description' => $responseText,
            'severity' => 'P2',
            'component' => 'api',
            'environment' => 'production',
            'confidence' => 0.5,
        ];
    }
}

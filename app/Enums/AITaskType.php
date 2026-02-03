<?php

namespace App\Enums;

/**
 * AI Task Type Registry
 *
 * Centralized registry for all AI task types.
 * Defines what agents are doing (e.g., summarizing tickets, generating reports).
 *
 * Task types are used for:
 * - Routing agent runs to appropriate handlers
 * - Reporting and cost analysis
 * - Filtering agent run history
 * - Defining agent capabilities
 *
 * Naming convention: Use snake_case descriptive names
 * Examples: support_ticket_summary, audit_report_generation
 */
class AITaskType
{
    // Support ticket tasks
    public const SUPPORT_TICKET_SUMMARY = 'support_ticket_summary';
    public const TICKET_CLASSIFICATION = 'ticket_classification';
    public const SLA_RISK_DETECTION = 'sla_risk_detection';
    public const ERROR_PATTERN_ANALYSIS = 'error_pattern_analysis';
    public const DUPLICATE_TICKET_DETECTION = 'duplicate_ticket_detection';
    
    // Audit and reporting tasks
    public const AUDIT_REPORT_GENERATION = 'audit_report_generation';
    public const PERFORMANCE_ANALYSIS = 'performance_analysis';
    public const SYSTEM_RELIABILITY_ANALYSIS = 'system_reliability_analysis';
    
    // Asset management tasks
    public const ASSET_TAG_SUGGESTION = 'asset_tag_suggestion';
    public const ASSET_METADATA_GENERATION = 'asset_metadata_generation';
    public const APPROVAL_FEEDBACK_SUMMARY = 'approval_feedback_summary'; // Phase AF-6
    
    // Alert and monitoring tasks
    public const ALERT_SUMMARY = 'alert_summary';

    // Download ZIP failure analysis (timeout/escalation)
    public const DOWNLOAD_ZIP_FAILURE_ANALYSIS = 'download_zip_failure_analysis';

    // Phase U-1: Upload failure analysis
    public const UPLOAD_FAILURE_ANALYSIS = 'upload_failure_analysis';

    // Phase T-1: Asset derivative failure analysis
    public const ASSET_DERIVATIVE_FAILURE_ANALYSIS = 'asset_derivative_failure_analysis';
    
    /**
     * Get all task types as an array.
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::SUPPORT_TICKET_SUMMARY,
            self::TICKET_CLASSIFICATION,
            self::SLA_RISK_DETECTION,
            self::ERROR_PATTERN_ANALYSIS,
            self::DUPLICATE_TICKET_DETECTION,
            self::AUDIT_REPORT_GENERATION,
            self::PERFORMANCE_ANALYSIS,
            self::SYSTEM_RELIABILITY_ANALYSIS,
            self::ASSET_TAG_SUGGESTION,
            self::ASSET_METADATA_GENERATION,
            self::APPROVAL_FEEDBACK_SUMMARY,
            self::ALERT_SUMMARY,
            self::DOWNLOAD_ZIP_FAILURE_ANALYSIS,
            self::UPLOAD_FAILURE_ANALYSIS,
            self::ASSET_DERIVATIVE_FAILURE_ANALYSIS,
        ];
    }
    
    /**
     * Validate if a task type is valid.
     *
     * @param string $taskType
     * @return bool
     */
    public static function isValid(string $taskType): bool
    {
        return in_array($taskType, self::all(), true);
    }
    
    /**
     * Get task types by category/domain.
     *
     * @param string $category (e.g., 'support', 'audit', 'asset')
     * @return array<string>
     */
    public static function byCategory(string $category): array
    {
        $mapping = [
            'support' => [
                self::SUPPORT_TICKET_SUMMARY,
                self::TICKET_CLASSIFICATION,
                self::SLA_RISK_DETECTION,
                self::DUPLICATE_TICKET_DETECTION,
            ],
            'engineering' => [self::ERROR_PATTERN_ANALYSIS],
            'audit' => [self::AUDIT_REPORT_GENERATION, self::PERFORMANCE_ANALYSIS, self::SYSTEM_RELIABILITY_ANALYSIS],
            'asset' => [self::ASSET_TAG_SUGGESTION, self::ASSET_METADATA_GENERATION, self::APPROVAL_FEEDBACK_SUMMARY],
        ];
        
        return $mapping[$category] ?? [];
    }
}

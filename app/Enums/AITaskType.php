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

    /** Vision: normalized focal point (0–1) for photography smart crops — gpt-4o-mini */
    public const PHOTOGRAPHY_FOCAL_POINT = 'photography_focal_point';

    /** Async video frame + optional transcript + vision insights (tags, summary, structured hints) */
    public const VIDEO_INSIGHTS = 'video_insights';

    public const APPROVAL_FEEDBACK_SUMMARY = 'approval_feedback_summary'; // Phase AF-6

    /** Template-based or future-AI enhanced thumbnail previews */
    public const THUMBNAIL_ENHANCEMENT = 'thumbnail_enhancement';

    /** AI image-edit presentation still from preferred/original thumbnail */
    public const THUMBNAIL_PRESENTATION_PREVIEW = 'thumbnail_presentation_preview';

    // Alert and monitoring tasks
    public const ALERT_SUMMARY = 'alert_summary';

    // Download ZIP failure analysis (timeout/escalation)
    public const DOWNLOAD_ZIP_FAILURE_ANALYSIS = 'download_zip_failure_analysis';

    // Phase U-1: Upload failure analysis
    public const UPLOAD_FAILURE_ANALYSIS = 'upload_failure_analysis';

    // Phase T-1: Asset derivative failure analysis
    public const ASSET_DERIVATIVE_FAILURE_ANALYSIS = 'asset_derivative_failure_analysis';

    // Phase 6: Brand Bootstrap AI inference
    public const BRAND_BOOTSTRAP_INFERENCE = 'brand_bootstrap_inference';

    // Phase 7: Brand Bootstrap signal extraction
    public const BRAND_BOOTSTRAP_SIGNAL_EXTRACTION = 'brand_bootstrap_signal_extraction';

    // Sentry AI: error analysis (summary, root cause, fix suggestion)
    public const SENTRY_ERROR_ANALYSIS = 'sentry_error_analysis';

    // PDF text: structure extracted text (document_type, summary) for guidelines/specs
    public const PDF_DOCUMENT_STRUCTURE = 'pdf_document_structure';

    // Brand PDF extraction: single-pass Claude analysis of brand guidelines PDF
    public const BRAND_PDF_EXTRACTION = 'brand_pdf_extraction';

    // Brand insights: LLM-generated actionable insights from analytics metrics
    public const BRAND_INSIGHTS = 'brand_insights';

    /** Asset editor — inline marketing copy assist (text layers) */
    public const EDITOR_COPY_ASSIST = 'editor_copy_assist';

    /** Asset editor — generative image layers */
    public const EDITOR_GENERATIVE_IMAGE = 'editor_generative_image';

    /** Asset editor — AI edit of an existing image layer (counts toward same plan quota as generative) */
    public const EDITOR_EDIT_IMAGE = 'editor_edit_image';

    /** Asset editor — AI-generated layout recommendation (template + layer structure from user prompt) */
    public const EDITOR_LAYOUT_GENERATION = 'editor_layout_generation';

    /** Studio Creator — animate full composition snapshot via external video model (e.g. Kling i2v) */
    public const STUDIO_COMPOSITION_ANIMATION = 'studio_composition_animation';

    /** Studio — Fal/SAM (or other remote) layer mask extraction; billed on success, tenant-scoped credits */
    public const STUDIO_LAYER_EXTRACTION = 'studio_layer_extraction';

    /** Studio — background inpaint (e.g. Clipdrop) after cutout; billed on success */
    public const STUDIO_LAYER_BACKGROUND_FILL = 'studio_layer_background_fill';

    /** In-app help: grounded answers from retrieved help_actions only (gpt-4o-mini) */
    public const IN_APP_HELP_ACTION_ANSWER = 'in_app_help_action_answer';

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
            self::PHOTOGRAPHY_FOCAL_POINT,
            self::VIDEO_INSIGHTS,
            self::APPROVAL_FEEDBACK_SUMMARY,
            self::ALERT_SUMMARY,
            self::DOWNLOAD_ZIP_FAILURE_ANALYSIS,
            self::UPLOAD_FAILURE_ANALYSIS,
            self::ASSET_DERIVATIVE_FAILURE_ANALYSIS,
            self::BRAND_BOOTSTRAP_INFERENCE,
            self::BRAND_BOOTSTRAP_SIGNAL_EXTRACTION,
            self::SENTRY_ERROR_ANALYSIS,
            self::PDF_DOCUMENT_STRUCTURE,
            self::BRAND_PDF_EXTRACTION,
            self::BRAND_INSIGHTS,
            self::EDITOR_COPY_ASSIST,
            self::EDITOR_GENERATIVE_IMAGE,
            self::EDITOR_EDIT_IMAGE,
            self::EDITOR_LAYOUT_GENERATION,
            self::STUDIO_COMPOSITION_ANIMATION,
            self::STUDIO_LAYER_EXTRACTION,
            self::STUDIO_LAYER_BACKGROUND_FILL,
            self::THUMBNAIL_ENHANCEMENT,
            self::THUMBNAIL_PRESENTATION_PREVIEW,
            self::IN_APP_HELP_ACTION_ANSWER,
        ];
    }

    /**
     * Validate if a task type is valid.
     */
    public static function isValid(string $taskType): bool
    {
        return in_array($taskType, self::all(), true);
    }

    /**
     * Get task types by category/domain.
     *
     * @param  string  $category  (e.g., 'support', 'audit', 'asset')
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
            'asset' => [self::ASSET_TAG_SUGGESTION, self::ASSET_METADATA_GENERATION, self::PHOTOGRAPHY_FOCAL_POINT, self::VIDEO_INSIGHTS, self::APPROVAL_FEEDBACK_SUMMARY, self::THUMBNAIL_ENHANCEMENT, self::THUMBNAIL_PRESENTATION_PREVIEW, self::STUDIO_COMPOSITION_ANIMATION],
        ];

        return $mapping[$category] ?? [];
    }
}

<?php

namespace App\Enums;

/**
 * Event Type Registry
 * 
 * Centralized registry for all activity event types.
 * Enforces consistent naming and provides documentation.
 * 
 * Naming convention: {domain}.{action} or {domain}.{category}.{action}
 * 
 * Examples:
 * - tenant.created
 * - user.invited
 * - asset.uploaded
 * - asset.version_added
 * - asset.previewed
 * - asset.download.created
 * - asset.download.completed
 * - asset.shared.link_created
 * - asset.shared.link_accessed
 */
class EventType
{
    // Tenant events
    public const TENANT_CREATED = 'tenant.created';
    public const TENANT_UPDATED = 'tenant.updated';
    public const TENANT_DELETED = 'tenant.deleted';
    
    // Tenant ownership transfer events
    public const TENANT_OWNER_TRANSFER_INITIATED = 'tenant.owner_transfer.initiated';
    public const TENANT_OWNER_TRANSFER_CONFIRMED = 'tenant.owner_transfer.confirmed';
    public const TENANT_OWNER_TRANSFER_ACCEPTED = 'tenant.owner_transfer.accepted';
    public const TENANT_OWNER_TRANSFER_COMPLETED = 'tenant.owner_transfer.completed';
    public const TENANT_OWNER_TRANSFER_CANCELLED = 'tenant.owner_transfer.cancelled';

    // User events
    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';
    public const USER_DELETED = 'user.deleted';
    public const USER_INVITED = 'user.invited';
    public const USER_ACTIVATED = 'user.activated';
    public const USER_DEACTIVATED = 'user.deactivated';
    public const USER_ADDED_TO_COMPANY = 'user.added_to_company';
    public const USER_REMOVED_FROM_COMPANY = 'user.removed_from_company';
    public const USER_ROLE_UPDATED = 'user.role_updated';
    public const USER_SITE_ROLE_ASSIGNED = 'user.site_role_assigned';
    public const USER_ADDED_TO_BRAND = 'user.added_to_brand';
    public const USER_REMOVED_FROM_BRAND = 'user.removed_from_brand';

    // Brand events
    public const BRAND_CREATED = 'brand.created';
    public const BRAND_UPDATED = 'brand.updated';
    public const BRAND_DELETED = 'brand.deleted';

    // Asset events
    public const ASSET_UPLOADED = 'asset.uploaded';
    public const ASSET_UPDATED = 'asset.updated';
    public const ASSET_DELETED = 'asset.deleted';
    public const ASSET_RESTORED = 'asset.restored';
    public const ASSET_VERSION_ADDED = 'asset.version_added';
    public const ASSET_PREVIEWED = 'asset.previewed';
    public const ASSET_METADATA_UPDATED = 'asset.metadata_updated';
    public const ASSET_METADATA_POPULATED = 'asset.metadata.populated';
    public const ASSET_COLOR_ANALYSIS_COMPLETED = 'asset.color_analysis.completed';
    
    // System Metadata events (ComputedMetadataJob + PopulateAutomaticMetadataJob)
    // System metadata = orientation, color_space, resolution_class (automatically computed)
    public const ASSET_SYSTEM_METADATA_GENERATED = 'asset.system_metadata.generated';
    public const ASSET_SYSTEM_METADATA_REGENERATED = 'asset.system_metadata.regenerated';
    
    // AI Metadata Generation events (AiMetadataGenerationJob - metadata_generator agent)
    // AI metadata = candidates for ai_eligible fields like Photo Type (vision-based field inference)
    public const ASSET_AI_METADATA_GENERATED = 'asset.ai_metadata.generated';
    public const ASSET_AI_METADATA_REGENERATED = 'asset.ai_metadata.regenerated';
    public const ASSET_AI_METADATA_FAILED = 'asset.ai_metadata.failed';
    
    // AI Suggestions events (AiMetadataSuggestionJob - creates user-facing suggestions from candidates)
    public const ASSET_AI_SUGGESTIONS_GENERATED = 'asset.ai_suggestions.generated';
    public const ASSET_AI_SUGGESTIONS_FAILED = 'asset.ai_suggestions.failed';
    public const ASSET_AI_SUGGESTION_ACCEPTED = 'asset.ai_suggestion.accepted';
    public const ASSET_AI_SUGGESTION_DISMISSED = 'asset.ai_suggestion.dismissed';
    
    // AI Tagging events (AITaggingJob - general/freeform tags, not yet fully implemented)
    public const ASSET_AI_TAGGING_COMPLETED = 'asset.ai_tagging.completed';
    public const ASSET_AI_TAGGING_REGENERATED = 'asset.ai_tagging.regenerated';
    
    // AI Tag Auto-Apply events (Phase J.2.2)
    public const ASSET_AI_TAGS_AUTO_APPLIED = 'asset.ai_tags.auto_applied';
    public const ASSET_AI_TAG_AUTO_APPLY_FAILED = 'asset.ai_tag_auto_apply.failed';
    
    // Legacy: System tagging (kept for backward compatibility)
    public const ASSET_SYSTEM_TAGGING_COMPLETED = 'asset.system_tagging.completed';
    
    /**
     * Asset lifecycle events (processing pipeline)
     * 
     * Event/State Contract:
     * - Asset.status = VISIBILITY ONLY (VISIBLE/HIDDEN/FAILED), not processing state
     * - Processing state is DERIVED from thumbnail_status, metadata.pipeline_completed_at, and metadata flags
     * - ASSET_UPLOAD_FINALIZED is the canonical event that triggers processing pipeline
     * - asset.created does NOT exist - upload completion uses ASSET_UPLOAD_FINALIZED instead
     * - Processing pipeline: AssetUploaded event → ProcessAssetOnUpload listener → ProcessAssetJob
     */
    public const ASSET_UPLOAD_FINALIZED = 'asset.upload.finalized';
    public const ASSET_THUMBNAIL_STARTED = 'asset.thumbnail.started';
    public const ASSET_THUMBNAIL_COMPLETED = 'asset.thumbnail.completed';
    public const ASSET_THUMBNAIL_FAILED = 'asset.thumbnail.failed';
    public const ASSET_THUMBNAIL_SKIPPED = 'asset.thumbnail.skipped';
    public const ASSET_THUMBNAIL_RETRY_REQUESTED = 'asset.thumbnail.retry_requested';
    public const ASSET_PROMOTED = 'asset.promoted';
    public const ASSET_READY = 'asset.ready';

    // Asset download events (explicit logging required)
    public const ASSET_DOWNLOAD_CREATED = 'asset.download.created';
    public const ASSET_DOWNLOAD_COMPLETED = 'asset.download.completed';
    public const ASSET_DOWNLOAD_FAILED = 'asset.download.failed';

    // Asset metric events
    public const ASSET_METRIC_RECORDED = 'asset.metric.recorded';

    // Asset sharing events
    public const ASSET_SHARED_LINK_CREATED = 'asset.shared.link_created';
    public const ASSET_SHARED_LINK_ACCESSED = 'asset.shared.link_accessed';
    public const ASSET_SHARED_LINK_REVOKED = 'asset.shared.link_revoked';

    // Category events
    public const CATEGORY_CREATED = 'category.created';
    public const CATEGORY_UPDATED = 'category.updated';
    public const CATEGORY_DELETED = 'category.deleted';
    public const CATEGORY_SYSTEM_UPGRADED = 'category.system_upgraded';
    public const CATEGORY_ACCESS_UPDATED = 'category.access_updated';

    // System events
    public const SYSTEM_ERROR = 'system.error';
    public const SYSTEM_WARNING = 'system.warning';
    public const SYSTEM_INFO = 'system.info';

    // Zip/Export events
    public const ZIP_GENERATED = 'zip.generated';
    public const ZIP_DOWNLOADED = 'zip.downloaded';

    // Download Group events (Phase 3.1 Step 5)
    public const DOWNLOAD_GROUP_CREATED = 'download_group.created';
    public const DOWNLOAD_GROUP_READY = 'download_group.ready';
    public const DOWNLOAD_GROUP_INVALIDATED = 'download_group.invalidated';
    public const DOWNLOAD_ZIP_REQUESTED = 'download.zip.requested';
    public const DOWNLOAD_ZIP_COMPLETED = 'download.zip.completed';
    public const DOWNLOAD_ZIP_FAILED = 'download.zip.failed';
    public const DOWNLOAD_GROUP_FAILED = 'download_group.failed';

    // Subscription/Billing events
    public const SUBSCRIPTION_CREATED = 'subscription.created';
    public const SUBSCRIPTION_UPDATED = 'subscription.updated';
    public const SUBSCRIPTION_CANCELED = 'subscription.canceled';
    public const PLAN_UPDATED = 'plan.updated';
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_FAILED = 'invoice.failed';

    // Ticket events
    public const TICKET_CREATED = 'ticket.created';
    public const TICKET_UPDATED = 'ticket.updated';
    public const TICKET_DELETED = 'ticket.deleted';
    public const TICKET_MESSAGE_CREATED = 'ticket.message.created';
    public const TICKET_ATTACHMENT_CREATED = 'ticket.attachment.created';
    public const TICKET_ASSIGNED = 'ticket.assigned';
    public const TICKET_STATUS_CHANGED = 'ticket.status_changed';
    public const TICKET_CONVERTED = 'ticket.converted';
    public const TICKET_INTERNAL_NOTE_ADDED = 'ticket.internal_note_added';
    public const TICKET_LINKED = 'ticket.linked';
    public const TICKET_SUGGESTION_CREATED = 'ticket.suggestion.created';
    public const TICKET_SUGGESTION_UPDATED = 'ticket.suggestion.updated';

    // AI Agent events
    public const AI_AGENT_RUN_STARTED = 'ai.agent_run.started';
    public const AI_AGENT_RUN_COMPLETED = 'ai.agent_run.completed';
    public const AI_AGENT_RUN_FAILED = 'ai.agent_run.failed';

    // AI Configuration Override events
    public const AI_MODEL_OVERRIDE_CREATED = 'ai.model_override.created';
    public const AI_MODEL_OVERRIDE_UPDATED = 'ai.model_override.updated';
    public const AI_AGENT_OVERRIDE_CREATED = 'ai.agent_override.created';
    public const AI_AGENT_OVERRIDE_UPDATED = 'ai.agent_override.updated';
    public const AI_AUTOMATION_OVERRIDE_CREATED = 'ai.automation_override.created';
    public const AI_AUTOMATION_OVERRIDE_UPDATED = 'ai.automation_override.updated';

    // AI Budget events
    public const AI_BUDGET_OVERRIDE_CREATED = 'ai.budget_override.created';
    public const AI_BUDGET_OVERRIDE_UPDATED = 'ai.budget_override.updated';
    public const AI_BUDGET_WARNING_TRIGGERED = 'ai.budget.warning_triggered';
    public const AI_BUDGET_EXCEEDED = 'ai.budget.exceeded';
    public const AI_BUDGET_BLOCKED = 'ai.budget.blocked';

    // AI System Insight events
    public const AI_SYSTEM_INSIGHT = 'ai.system_insight';

    /**
     * Get all event types as an array.
     * 
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            self::TENANT_CREATED,
            self::TENANT_UPDATED,
            self::TENANT_DELETED,
            self::TENANT_OWNER_TRANSFER_INITIATED,
            self::TENANT_OWNER_TRANSFER_CONFIRMED,
            self::TENANT_OWNER_TRANSFER_ACCEPTED,
            self::TENANT_OWNER_TRANSFER_COMPLETED,
            self::TENANT_OWNER_TRANSFER_CANCELLED,
            self::USER_CREATED,
            self::USER_UPDATED,
            self::USER_DELETED,
            self::USER_INVITED,
            self::USER_ACTIVATED,
            self::USER_DEACTIVATED,
            self::USER_ADDED_TO_COMPANY,
            self::USER_REMOVED_FROM_COMPANY,
            self::USER_ROLE_UPDATED,
            self::USER_SITE_ROLE_ASSIGNED,
            self::USER_ADDED_TO_BRAND,
            self::USER_REMOVED_FROM_BRAND,
            self::BRAND_CREATED,
            self::BRAND_UPDATED,
            self::BRAND_DELETED,
            self::ASSET_UPLOADED,
            self::ASSET_UPDATED,
            self::ASSET_DELETED,
            self::ASSET_RESTORED,
            self::ASSET_VERSION_ADDED,
            self::ASSET_PREVIEWED,
            self::ASSET_METADATA_UPDATED,
            self::ASSET_METADATA_POPULATED,
            self::ASSET_COLOR_ANALYSIS_COMPLETED,
            self::ASSET_SYSTEM_METADATA_GENERATED,
            self::ASSET_SYSTEM_METADATA_REGENERATED,
            self::ASSET_AI_METADATA_GENERATED,
            self::ASSET_AI_METADATA_REGENERATED,
            self::ASSET_AI_METADATA_FAILED,
            self::ASSET_AI_SUGGESTIONS_GENERATED,
            self::ASSET_AI_SUGGESTIONS_FAILED,
            self::ASSET_AI_SUGGESTION_ACCEPTED,
            self::ASSET_AI_SUGGESTION_DISMISSED,
            self::ASSET_AI_TAGGING_COMPLETED,
            self::ASSET_AI_TAGGING_REGENERATED,
            self::ASSET_SYSTEM_TAGGING_COMPLETED, // Legacy
            self::ASSET_UPLOAD_FINALIZED,
            self::ASSET_THUMBNAIL_STARTED,
            self::ASSET_THUMBNAIL_COMPLETED,
            self::ASSET_THUMBNAIL_FAILED,
            self::ASSET_THUMBNAIL_SKIPPED,
            self::ASSET_THUMBNAIL_RETRY_REQUESTED,
            self::ASSET_PROMOTED,
            self::ASSET_READY,
            self::ASSET_DOWNLOAD_CREATED,
            self::ASSET_DOWNLOAD_COMPLETED,
            self::ASSET_DOWNLOAD_FAILED,
            self::ASSET_SHARED_LINK_CREATED,
            self::ASSET_SHARED_LINK_ACCESSED,
            self::ASSET_SHARED_LINK_REVOKED,
            self::CATEGORY_CREATED,
            self::CATEGORY_UPDATED,
            self::CATEGORY_DELETED,
            self::CATEGORY_SYSTEM_UPGRADED,
            self::CATEGORY_ACCESS_UPDATED,
            self::SYSTEM_ERROR,
            self::SYSTEM_WARNING,
            self::SYSTEM_INFO,
            self::ZIP_GENERATED,
            self::ZIP_DOWNLOADED,
            self::DOWNLOAD_GROUP_CREATED,
            self::DOWNLOAD_GROUP_READY,
            self::DOWNLOAD_GROUP_INVALIDATED,
            self::DOWNLOAD_ZIP_REQUESTED,
            self::DOWNLOAD_ZIP_COMPLETED,
            self::DOWNLOAD_ZIP_FAILED,
            self::DOWNLOAD_GROUP_FAILED,
            self::SUBSCRIPTION_CREATED,
            self::SUBSCRIPTION_UPDATED,
            self::SUBSCRIPTION_CANCELED,
            self::PLAN_UPDATED,
            self::INVOICE_PAID,
            self::INVOICE_FAILED,
            self::TICKET_CREATED,
            self::TICKET_UPDATED,
            self::TICKET_DELETED,
            self::TICKET_MESSAGE_CREATED,
            self::TICKET_ATTACHMENT_CREATED,
            self::TICKET_ASSIGNED,
            self::TICKET_STATUS_CHANGED,
            self::TICKET_CONVERTED,
            self::TICKET_INTERNAL_NOTE_ADDED,
            self::TICKET_LINKED,
            self::TICKET_SUGGESTION_CREATED,
            self::TICKET_SUGGESTION_UPDATED,
            self::AI_AGENT_RUN_STARTED,
            self::AI_AGENT_RUN_COMPLETED,
            self::AI_AGENT_RUN_FAILED,
            self::AI_MODEL_OVERRIDE_CREATED,
            self::AI_MODEL_OVERRIDE_UPDATED,
            self::AI_AGENT_OVERRIDE_CREATED,
            self::AI_AGENT_OVERRIDE_UPDATED,
            self::AI_AUTOMATION_OVERRIDE_CREATED,
            self::AI_AUTOMATION_OVERRIDE_UPDATED,
            self::AI_BUDGET_OVERRIDE_CREATED,
            self::AI_BUDGET_OVERRIDE_UPDATED,
            self::AI_BUDGET_WARNING_TRIGGERED,
            self::AI_BUDGET_EXCEEDED,
            self::AI_BUDGET_BLOCKED,
            self::AI_SYSTEM_INSIGHT,
        ];
    }

    /**
     * Validate if an event type is valid.
     * 
     * @param string $eventType
     * @return bool
     */
    public static function isValid(string $eventType): bool
    {
        return in_array($eventType, self::all(), true);
    }

    /**
     * Get event types by domain prefix.
     * 
     * @param string $domain (e.g., 'asset', 'user', 'tenant')
     * @return array<string>
     */
    public static function byDomain(string $domain): array
    {
        return array_filter(self::all(), function ($eventType) use ($domain) {
            return str_starts_with($eventType, $domain . '.');
        });
    }
}

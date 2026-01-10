import { UploadContext } from './UploadContext';
import { UploadItem } from './UploadItem';
import { MetadataField } from './MetadataField';

/**
 * Phase 3 Upload Manager State
 * 
 * Complete state model for the upload manager.
 * This is the single source of truth for all upload-related state.
 * 
 * @interface UploadManagerState
 */
export interface UploadManagerState {
    /**
     * Canonical upload context (company, brand, category)
     * Immutable once set (category can change until finalization)
     */
    context: UploadContext;

    /**
     * Array of upload items (files) in this batch
     * Order matters - reflects user's file selection order
     */
    items: UploadItem[];

    /**
     * Global metadata draft
     * Applies to all files by default
     * Keys match MetadataField.key values
     * Values depend on field type (string, number, boolean, array, etc.)
     */
    globalMetadataDraft: Record<string, any>;

    /**
     * Available metadata fields for the current category
     * Derived from category configuration
     * Updated when category changes
     * Empty array if no category selected
     */
    availableMetadataFields: MetadataField[];

    /**
     * Warnings and notifications for the user
     * 
     * Examples:
     * - Category change invalidated some metadata fields
     * - Some files have metadata overrides that will be preserved
     * - Required fields are missing
     * 
     * Warnings are informational and don't block finalization
     * (unless they indicate required fields are missing)
     */
    warnings: UploadWarning[];
}

/**
 * Warning/notification message
 * 
 * @interface UploadWarning
 */
export interface UploadWarning {
    /**
     * Warning type for programmatic handling
     */
    type: 'category_change' | 'metadata_invalidation' | 'missing_required_field' | 'filename_conflict' | 'other';

    /**
     * Human-readable warning message
     */
    message: string;

    /**
     * Optional severity level
     * - 'info': Informational (blue)
     * - 'warning': Warning (yellow)
     * - 'error': Error (red, but may not block finalization)
     */
    severity?: 'info' | 'warning' | 'error';

    /**
     * Optional field keys affected (for metadata-related warnings)
     */
    affectedFields?: string[];

    /**
     * Optional file IDs affected (for per-file warnings)
     */
    affectedFileIds?: string[];
}

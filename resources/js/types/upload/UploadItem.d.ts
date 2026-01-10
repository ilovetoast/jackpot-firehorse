/**
 * Phase 3 Upload Item Model
 * 
 * Represents a single file in an upload batch.
 * Each file has its own upload session, progress, and optional metadata overrides.
 * 
 * @interface UploadItem
 */
export interface UploadItem {
    /**
     * Client-side unique identifier (UUID v4)
     * Used for frontend state management and UI rendering
     * Persists across page refreshes
     */
    clientId: string;

    /**
     * Native File object from the browser
     * Used for actual upload operations
     * Note: Not serializable - must be reattached after page refresh
     */
    file: File;

    /**
     * Backend upload session ID
     * Provided by Phase 2 backend after upload initiation
     * Used for resume, completion, and status queries
     * Null until upload is initiated
     */
    uploadSessionId: string | null;

    /**
     * Original filename as provided by the user
     * Immutable - reflects the actual file name
     */
    originalFilename: string;

    /**
     * Asset title (user-facing, no extension)
     * Editable field that represents the human-readable asset name
     * Used to derive resolvedFilename: `${slugifiedTitle}.${extension}`
     * Defaults to originalFilename without extension
     */
    title: string;

    /**
     * Resolved filename (derived from title + extension)
     * Automatically computed from title: `${slugifiedTitle}.${extension}`
     * Used as the final asset filename sent to backend
     * Extension is derived from originalFilename
     */
    resolvedFilename: string;

    /**
     * Current upload status
     * 
     * - 'queued': Added to batch but not yet initiated
     * - 'uploading': Upload in progress (initiating or actively uploading)
     * - 'complete': Upload finished successfully
     * - 'failed': Upload failed (can be retried)
     * 
     * Note: 'paused' and 'cancelled' states are handled by Phase 2 UploadManager
     * and are not part of this model's status enum.
     */
    uploadStatus: 'queued' | 'uploading' | 'complete' | 'failed';

    /**
     * Upload progress percentage (0-100)
     * Updated by Phase 2 UploadManager during upload
     */
    progress: number;

    /**
     * Structured error information (if uploadStatus === 'failed')
     * Null if no error
     * 
     * Contains user-friendly error message and optional raw error details
     */
    error: UploadError | null;

    /**
     * Per-file metadata overrides
     * Keys match MetadataField.key values
     * Only fields that differ from global metadata are stored here
     * Empty object if no overrides
     */
    metadataDraft: Record<string, any>;

    /**
     * Tracks which metadata fields have been explicitly overridden for this file
     * 
     * If boolean: true means at least one field is overridden
     * If object: maps field keys to boolean (true = overridden)
     * 
     * Used to prevent global metadata changes from overwriting user edits
     */
    isMetadataOverridden: boolean | Record<string, boolean>;
}

/**
 * Structured error information for upload failures
 * 
 * @interface UploadError
 */
export interface UploadError {
    /**
     * User-friendly error message
     * Displayed in UI without technical details
     */
    message: string;

    /**
     * Error type classification (for error handling logic)
     * - 'network': Network/connection issues
     * - 'auth': Authentication/permission issues
     * - 'expired': Presigned URL expired
     * - 'server': Server-side error
     * - 'validation': Validation error
     * - 'unknown': Unclassified error
     */
    type: 'network' | 'auth' | 'expired' | 'server' | 'validation' | 'unknown';

    /**
     * HTTP status code (if applicable)
     */
    httpStatus?: number;

    /**
     * Raw error object (for debugging)
     * Only available in development mode
     */
    rawError?: any;
}

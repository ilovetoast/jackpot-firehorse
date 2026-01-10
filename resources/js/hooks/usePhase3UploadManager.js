/**
 * Phase 3.1 Upload Manager Hook
 * 
 * State management layer for upload batches with metadata support.
 * This hook manages Phase 3 state (metadata, category, items) and integrates
 * with Phase 2 UploadManager for actual upload operations.
 * 
 * This is a STATE LAYER ONLY - no upload logic, no UI components.
 * 
 * @module usePhase3UploadManager
 */

import { useState, useCallback, useMemo } from 'react';
import {
    applyGlobalMetadata,
    overrideItemMetadata as overrideItemMetadataHelper,
    resolveEffectiveMetadata,
    markFieldAsOverridden,
    unmarkFieldAsOverridden,
    handleCategoryChange,
    validateRequiredMetadataFields
} from '../utils/upload';

/**
 * Generate a unique client ID (UUID v4)
 * @returns {string} UUID v4 string
 */
function generateClientId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    // Fallback UUID v4 generation
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

/**
 * Extract file extension from filename
 * @param {string} filename - Filename with extension
 * @returns {string} Extension (without dot) or empty string
 */
function getFileExtension(filename) {
    const lastDot = filename.lastIndexOf('.');
    if (lastDot === -1 || lastDot === filename.length - 1) {
        return '';
    }
    return filename.substring(lastDot + 1).toLowerCase();
}

/**
 * Get filename without extension
 * @param {string} filename - Filename with extension
 * @returns {string} Filename without extension
 */
function getFilenameWithoutExtension(filename) {
    const lastDot = filename.lastIndexOf('.');
    if (lastDot === -1) {
        return filename;
    }
    return filename.substring(0, lastDot);
}

/**
 * Normalize asset title for display and storage
 * 
 * Applies best-practice normalization rules:
 * - Trim leading/trailing whitespace
 * - Collapse multiple spaces into one
 * - Remove special characters except letters, numbers, and spaces
 * - Convert to Title Case (each word capitalized)
 * - Preserve numbers
 * 
 * Result is human-readable, search-friendly, and grid-friendly.
 * 
 * Example:
 *   " upload-foobar__topSHOT!!.jpg " → "Upload Foobar Topshot"
 * 
 * TODO (Phase 4):
 *   Make title normalization configurable per tenant / brand.
 *   Possible options:
 *   - Case rules (Title Case vs Sentence Case vs Preserve Case)
 *   - Allowed characters (whitelist/blacklist)
 *   - Separator rules (spaces vs hyphens)
 *   - Localization / language-specific rules
 *   - Custom normalization functions per tenant/brand
 * 
 * @param {string} title - Raw title (may contain special chars, wrong case, etc.)
 * @returns {string} Normalized title (Title Case, cleaned, human-readable)
 */
function normalizeTitle(title) {
    if (!title || typeof title !== 'string') {
        return '';
    }
    
    // Step 1: Trim leading/trailing whitespace
    let normalized = title.trim();
    
    // Step 2: Replace hyphens and underscores with spaces (before special char removal)
    // This allows "my-file_name" → "my file name" → "My File Name"
    normalized = normalized.replace(/[-_]/g, ' ');
    
    // Step 3: Collapse multiple spaces into one
    normalized = normalized.replace(/\s+/g, ' ');
    
    // Step 4: Remove special characters except letters, numbers, and spaces
    // Keep: a-z, A-Z, 0-9, spaces
    // This removes: !@#$%^&*()[]{}, etc.
    normalized = normalized.replace(/[^a-zA-Z0-9\s]/g, '');
    
    // Step 5: Collapse spaces again (in case removal created double spaces)
    normalized = normalized.replace(/\s+/g, ' ').trim();
    
    // Step 6: Convert to Title Case (capitalize first letter of each word)
    // Split by spaces, capitalize first letter of each word, lowercase the rest
    normalized = normalized
        .split(' ')
        .map(word => {
            if (!word) return '';
            // Preserve numbers but capitalize first letter if it's a letter
            // Example: "123abc" → "123Abc", "abc123" → "Abc123"
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        })
        .filter(word => word.length > 0) // Remove empty strings
        .join(' ');
    
    // If empty after normalization, return empty string (fallback to 'Untitled' handled by caller)
    return normalized || '';
}

/**
 * Slugify a string for use in filenames
 * Converts to lowercase, replaces spaces/special chars with hyphens, removes invalid chars
 * Used for deriving resolvedFilename from normalized title
 * 
 * @param {string} str - String to slugify (should already be normalized)
 * @returns {string} Slugified string (lowercase, hyphenated)
 */
function slugify(str) {
    if (!str || typeof str !== 'string') {
        return 'untitled';
    }
    
    return str
        .toLowerCase()
        .trim()
        .replace(/[^\w\s-]/g, '') // Remove special characters
        .replace(/[\s_-]+/g, '-') // Replace spaces/underscores with hyphens
        .replace(/^-+|-+$/g, ''); // Remove leading/trailing hyphens
}

/**
 * Derive resolvedFilename from title and extension
 * @param {string} title - Asset title (no extension)
 * @param {string} extension - File extension (without dot)
 * @returns {string} Resolved filename: slugifiedTitle.extension
 */
function deriveResolvedFilename(title, extension) {
    const slugified = slugify(title || 'untitled');
    if (!extension) {
        return slugified;
    }
    return `${slugified}.${extension}`;
}

/**
 * Create initial UploadManagerState
 * @param {UploadContext} context - Upload context
 * @returns {UploadManagerState} Initial state
 */
function createInitialState(context) {
    return {
        context,
        items: [],
        globalMetadataDraft: {},
        availableMetadataFields: [],
        warnings: []
    };
}

/**
 * Phase 3.1 Upload Manager Hook
 * 
 * Manages upload batch state including:
 * - Upload items (files)
 * - Global and per-file metadata
 * - Category selection
 * - Validation and warnings
 * 
 * @param {UploadContext} context - Initial upload context (companyId, brandId, categoryId)
 * @returns {Object} Upload manager API
 */
export function usePhase3UploadManager(context) {
    // Initialize state
    const [state, setState] = useState(() => createInitialState(context));

    /**
     * Add files to the upload batch
     * 
     * Creates UploadItem entries for each file in 'queued' status.
     * Files are not uploaded automatically - upload must be initiated separately.
     * 
     * @param {File[]} files - Array of File objects to add
     * @returns {string[]} Array of clientIds for the added files
     */
    const addFiles = useCallback((files) => {
        const newItems = Array.from(files).map(file => {
            const clientId = generateClientId();
            // Extension always from originalFilename, lowercase (immutable)
            const extension = getFileExtension(file.name);
            // Extract raw title and normalize it (single source of truth)
            const rawTitle = getFilenameWithoutExtension(file.name);
            let title = normalizeTitle(rawTitle);
            // If normalization resulted in empty string, use fallback
            if (!title) {
                title = 'Untitled';
            }
            // resolvedFilename is ALWAYS derived from normalized title + extension (never directly editable)
            const resolvedFilename = deriveResolvedFilename(title, extension);
            
            return {
                clientId,
                file,
                uploadSessionId: null,
                originalFilename: file.name,
                title, // Normalized title (Title Case, cleaned, human-readable)
                resolvedFilename, // Derived from normalized title + extension (slugified, never editable)
                uploadStatus: 'queued',
                progress: 0,
                error: null,
                metadataDraft: {},
                isMetadataOverridden: false
            };
        });

        setState(prevState => {
            const updatedItems = [...prevState.items, ...newItems];
            
            // Apply global metadata to new items
            const itemsWithMetadata = applyGlobalMetadata(updatedItems, prevState.globalMetadataDraft);
            
            return {
                ...prevState,
                items: itemsWithMetadata
            };
        });

        return newItems.map(item => item.clientId);
    }, []);

    /**
     * Update upload progress for a specific item
     * 
     * Updates the progress percentage for an item. This should be called
     * by the Phase 2 UploadManager integration layer as upload progresses.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {number} progress - Progress percentage (0-100)
     */
    const updateUploadProgress = useCallback((clientId, progress) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? { ...item, progress: Math.max(0, Math.min(100, progress)) }
                    : item
            )
        }));
    }, []);

    /**
     * Mark an upload as complete
     * 
     * Updates the item status to 'complete' and sets progress to 100.
     * Should be called when Phase 2 UploadManager reports completion.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} [uploadSessionId] - Optional upload session ID from backend
     */
    const markUploadComplete = useCallback((clientId, uploadSessionId = null) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? {
                          ...item,
                          uploadStatus: 'complete',
                          progress: 100,
                          error: null,
                          uploadSessionId: uploadSessionId || item.uploadSessionId
                      }
                    : item
            )
        }));
    }, []);

    /**
     * Mark an upload as failed
     * 
     * Updates the item status to 'failed' and stores structured error information.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {UploadError | string} error - Error object or error message string
     */
    const markUploadFailed = useCallback((clientId, error) => {
        const errorObj = typeof error === 'string'
            ? {
                  message: error,
                  type: 'unknown'
              }
            : error;

        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? {
                          ...item,
                          uploadStatus: 'failed',
                          error: errorObj
                      }
                    : item
            )
        }));
    }, []);

    /**
     * Set upload session ID for an item
     * 
     * Called when Phase 2 UploadManager initiates an upload session.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} uploadSessionId - Upload session ID from backend
     */
    const setUploadSessionId = useCallback((clientId, uploadSessionId) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? { ...item, uploadSessionId, uploadStatus: 'uploading' }
                    : item
            )
        }));
    }, []);

    /**
     * Update upload status to 'uploading'
     * 
     * Called when Phase 2 UploadManager starts uploading (before session ID is set).
     * 
     * @param {string} clientId - Client ID of the upload item
     */
    const markUploadStarted = useCallback((clientId) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId && item.uploadStatus === 'queued'
                    ? { ...item, uploadStatus: 'uploading' }
                    : item
            )
        }));
    }, []);

    /**
     * Set a global metadata field value
     * 
     * Updates global metadata and applies it to all items that don't have overrides.
     * Respects existing per-file overrides.
     * 
     * @param {string} key - Metadata field key
     * @param {any} value - Metadata value (type depends on field)
     */
    const setGlobalMetadata = useCallback((key, value) => {
        setState(prevState => {
            const newGlobalMetadata = {
                ...prevState.globalMetadataDraft,
                [key]: value
            };

            // Apply to items (respecting overrides)
            const updatedItems = applyGlobalMetadata(prevState.items, newGlobalMetadata);

            return {
                ...prevState,
                globalMetadataDraft: newGlobalMetadata,
                items: updatedItems
            };
        });
    }, []);

    /**
     * Override metadata for a specific item
     * 
     * Sets per-file metadata override and marks the field as overridden.
     * This prevents future global metadata changes from overwriting this value.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} key - Metadata field key
     * @param {any} value - Metadata value
     */
    const overrideItemMetadata = useCallback((clientId, key, value) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? overrideItemMetadataHelper(item, { [key]: value })
                    : item
            )
        }));
    }, []);

    /**
     * Clear a metadata override for a specific item
     * 
     * Removes the override flag for a field, allowing it to be controlled
     * by global metadata again. The field value remains but will be overwritten
     * by future global changes.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} key - Metadata field key to clear
     */
    const clearItemOverride = useCallback((clientId, key) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item =>
                item.clientId === clientId
                    ? unmarkFieldAsOverridden(item, key)
                    : item
            )
        }));
    }, []);

    /**
     * Set title for an upload item
     * 
     * SINGLE SOURCE OF TRUTH: Normalizes title and automatically derives resolvedFilename.
     * 
     * Normalization rules (applied automatically):
     * - Trim whitespace
     * - Collapse multiple spaces
     * - Remove special characters (keep letters, numbers, spaces)
     * - Convert to Title Case
     * - Preserve numbers
     * 
     * Filename derivation:
     * - Extension always comes from originalFilename (lowercase, immutable)
     * - resolvedFilename = slugify(normalizedTitle) + '.' + extension
     * - resolvedFilename is NEVER directly editable - always derived
     * 
     * CRITICAL: resolvedFilename is ALWAYS derived, never set directly.
     * Users can only edit title - filename updates automatically.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} rawTitle - Raw title input from user (may contain special chars, wrong case, etc.)
     */
    const setTitle = useCallback((clientId, rawTitle) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item => {
                if (item.clientId === clientId) {
                    // Normalize title at single source of truth
                    // Fallback to original filename without extension if normalization results in empty string
                    const fallbackTitle = getFilenameWithoutExtension(item.originalFilename);
                    let normalizedTitle = normalizeTitle(rawTitle || fallbackTitle);
                    
                    // If normalization resulted in empty string (e.g., only special chars), use fallback
                    if (!normalizedTitle) {
                        normalizedTitle = normalizeTitle(fallbackTitle) || 'Untitled';
                    }
                    
                    // Extension always from originalFilename (lowercase, immutable)
                    const extension = getFileExtension(item.originalFilename);
                    
                    // resolvedFilename is ALWAYS derived from normalized title + extension
                    // NEVER directly editable by user
                    const resolvedFilename = deriveResolvedFilename(normalizedTitle, extension);
                    
                    return { ...item, title: normalizedTitle, resolvedFilename };
                }
                return item;
            })
        }));
    }, []);

    /**
     * Set resolved filename for an upload item (legacy method - DEPRECATED)
     * 
     * WARNING: This method is kept for backwards compatibility only.
     * It extracts title from filename and normalizes it, then derives resolvedFilename.
     * 
     * DO NOT USE: Use setTitle() instead, which is the single source of truth.
     * 
     * This method should not be called from UI components.
     * resolvedFilename is ALWAYS derived from title + extension, never directly set.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @param {string} filename - Filename (from which title will be extracted and normalized)
     * @deprecated Use setTitle() instead
     */
    const setResolvedFilename = useCallback((clientId, filename) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.map(item => {
                if (item.clientId === clientId) {
                    // Extract raw title from filename (without extension)
                    const rawTitle = getFilenameWithoutExtension(filename);
                    
                    // Normalize title (same as setTitle)
                    const normalizedTitle = normalizeTitle(rawTitle || getFilenameWithoutExtension(item.originalFilename));
                    
                    // Extension always from originalFilename (lowercase, immutable)
                    const extension = getFileExtension(item.originalFilename);
                    
                    // resolvedFilename is ALWAYS derived from normalized title + extension
                    // This method still respects the rule: filename is always derived, never directly set
                    const resolvedFilename = deriveResolvedFilename(normalizedTitle, extension);
                    
                    return { ...item, title: normalizedTitle, resolvedFilename };
                }
                return item;
            })
        }));
    }, []);

    /**
     * Change the category
     * 
     * Updates the category and recomputes available metadata fields.
     * Invalid metadata is automatically cleaned up, and warnings are generated.
     * Upload sessions are preserved - uploads continue uninterrupted.
     * 
     * @param {string | number | null} categoryId - New category ID (null to clear)
     * @param {MetadataField[]} metadataFields - Metadata fields for the new category
     */
    const changeCategory = useCallback((categoryId, metadataFields = []) => {
        setState(prevState => {
            return handleCategoryChange(prevState, categoryId, metadataFields);
        });
    }, []);

    /**
     * Set available metadata fields
     * 
     * Updates the list of available metadata fields (typically when category changes).
     * This is usually called automatically by changeCategory, but can be called
     * separately if fields need to be updated without changing category.
     * 
     * @param {MetadataField[]} metadataFields - Available metadata fields
     */
    const setAvailableMetadataFields = useCallback((metadataFields) => {
        setState(prevState => ({
            ...prevState,
            availableMetadataFields: metadataFields
        }));
    }, []);

    /**
     * Remove an item from the batch
     * 
     * Removes an upload item from the batch. This does not cancel the upload
     * if it's in progress - cancellation should be handled separately.
     * 
     * @param {string} clientId - Client ID of the item to remove
     */
    const removeItem = useCallback((clientId) => {
        setState(prevState => ({
            ...prevState,
            items: prevState.items.filter(item => item.clientId !== clientId)
        }));
    }, []);

    /**
     * Clear all warnings
     * 
     * Removes all warning messages from state.
     */
    const clearWarnings = useCallback(() => {
        setState(prevState => ({
            ...prevState,
            warnings: []
        }));
    }, []);

    /**
     * Validate metadata
     * 
     * Validates that all required metadata fields have values.
     * Returns validation warnings (does not throw).
     * 
     * @returns {UploadWarning[]} Array of validation warnings
     */
    const validateMetadata = useCallback(() => {
        const warnings = validateRequiredMetadataFields(state);
        
        // Update state with validation warnings
        setState(prevState => {
            // Remove existing validation warnings
            const otherWarnings = prevState.warnings.filter(
                w => w.type !== 'missing_required_field'
            );
            
            return {
                ...prevState,
                warnings: [...otherWarnings, ...warnings]
            };
        });

        return warnings;
    }, [state]);

    /**
     * Get effective metadata for an item
     * 
     * Returns the final metadata that will be applied to an asset,
     * combining global metadata with per-file overrides.
     * 
     * @param {string} clientId - Client ID of the upload item
     * @returns {Record<string, any>} Effective metadata
     */
    const getEffectiveMetadata = useCallback((clientId) => {
        const item = state.items.find(i => i.clientId === clientId);
        if (!item) {
            return {};
        }
        return resolveEffectiveMetadata(item, state.globalMetadataDraft);
    }, [state]);

    /**
     * Get upload item by client ID
     * 
     * @param {string} clientId - Client ID
     * @returns {UploadItem | undefined} Upload item or undefined
     */
    const getItem = useCallback((clientId) => {
        return state.items.find(item => item.clientId === clientId);
    }, [state.items]);

    // Computed values
    const queuedItems = useMemo(() => 
        state.items.filter(item => item.uploadStatus === 'queued'),
        [state.items]
    );

    const uploadingItems = useMemo(() => 
        state.items.filter(item => item.uploadStatus === 'uploading'),
        [state.items]
    );

    const completedItems = useMemo(() => 
        state.items.filter(item => item.uploadStatus === 'complete'),
        [state.items]
    );

    const failedItems = useMemo(() => 
        state.items.filter(item => item.uploadStatus === 'failed'),
        [state.items]
    );

    const hasItems = useMemo(() => state.items.length > 0, [state.items.length]);

    const canFinalize = useMemo(() => {
        // Can finalize if:
        // 1. Category is selected
        // 2. All items are complete or failed
        // 3. At least one item is complete
        // 4. No critical validation errors
        const hasCategory = state.context.categoryId !== null;
        const allTerminal = state.items.every(
            item => item.uploadStatus === 'complete' || item.uploadStatus === 'failed'
        );
        const hasCompleted = completedItems.length > 0;
        const hasCriticalErrors = state.warnings.some(
            w => w.type === 'missing_required_field' && w.severity === 'error'
        );

        return hasCategory && allTerminal && hasCompleted && !hasCriticalErrors;
    }, [state.context.categoryId, state.items, completedItems.length, state.warnings]);

    return {
        // State
        state,
        context: state.context,
        items: state.items,
        globalMetadataDraft: state.globalMetadataDraft,
        availableMetadataFields: state.availableMetadataFields,
        warnings: state.warnings,

        // Computed state
        queuedItems,
        uploadingItems,
        completedItems,
        failedItems,
        hasItems,
        canFinalize,

        // Mutation methods
        addFiles,
        updateUploadProgress,
        markUploadComplete,
        markUploadFailed,
        markUploadStarted,
        setUploadSessionId,
        setGlobalMetadata,
        overrideItemMetadata,
        clearItemOverride,
        setTitle,
        setResolvedFilename,
        changeCategory,
        setAvailableMetadataFields,
        removeItem,
        clearWarnings,
        validateMetadata,

        // Query methods
        getEffectiveMetadata,
        getItem
    };
}

export default usePhase3UploadManager;

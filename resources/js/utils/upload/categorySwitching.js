/**
 * Phase 3 Category Switching Utilities
 * 
 * Pure functions for handling category changes and metadata field updates.
 * These functions ensure metadata validity when categories change.
 * 
 * @module categorySwitching
 */

import { applyGlobalMetadata } from './metadataResolution';

/**
 * Handle category change in upload manager state
 * 
 * When category changes:
 * 1. Recomputes available metadata fields from new category
 * 2. Removes invalid metadata keys (fields that don't exist in new category)
 * 3. Generates warnings describing what changed
 * 4. Preserves upload sessions and upload progress
 * 
 * Category changes are allowed until finalization.
 * 
 * @param {UploadManagerState} state - Current upload manager state
 * @param {string | number | null} newCategoryId - New category ID (null to clear category)
 * @param {MetadataField[]} newMetadataFields - Metadata fields for the new category
 * @returns {UploadManagerState} New state with updated category and cleaned metadata
 * 
 * @example
 * const state = {
 *   context: { categoryId: '1', ... },
 *   items: [...],
 *   globalMetadataDraft: { color: 'blue', oldField: 'value' },
 *   availableMetadataFields: [...old fields...]
 * };
 * const newFields = [{ key: 'color', ... }, { key: 'tags', ... }];
 * const updated = handleCategoryChange(state, '2', newFields);
 * // Result: categoryId = '2', oldField removed, warnings added
 */
export function handleCategoryChange(state, newCategoryId, newMetadataFields) {
    const oldCategoryId = state.context.categoryId;
    const oldFieldKeys = new Set(state.availableMetadataFields.map(f => f.key));
    const newFieldKeys = new Set(newMetadataFields.map(f => f.key));

    // Find fields that were removed
    const removedFields = Array.from(oldFieldKeys).filter(key => !newFieldKeys.has(key));
    
    // Find fields that were added
    const addedFields = Array.from(newFieldKeys).filter(key => !oldFieldKeys.has(key));

    // Clean global metadata draft - remove invalid keys
    const cleanedGlobalMetadata = { ...state.globalMetadataDraft };
    removedFields.forEach(key => {
        delete cleanedGlobalMetadata[key];
    });

    // Clean per-file metadata drafts and update override flags
    const cleanedItems = state.items.map(item => {
        const cleanedDraft = { ...item.metadataDraft };
        const cleanedOverrides = typeof item.isMetadataOverridden === 'object' 
            ? { ...item.isMetadataOverridden }
            : item.isMetadataOverridden;

        // Remove invalid fields from draft
        removedFields.forEach(key => {
            delete cleanedDraft[key];
        });

        // Remove invalid fields from override flags
        if (typeof cleanedOverrides === 'object') {
            removedFields.forEach(key => {
                delete cleanedOverrides[key];
            });
            
            // Convert to false if no overrides remain
            if (Object.keys(cleanedOverrides).length === 0) {
                return {
                    ...item,
                    metadataDraft: cleanedDraft,
                    isMetadataOverridden: false
                };
            }
        }

        return {
            ...item,
            metadataDraft: cleanedDraft,
            isMetadataOverridden: cleanedOverrides
        };
    });

    // Apply cleaned global metadata to items
    const updatedItems = applyGlobalMetadata(cleanedItems, cleanedGlobalMetadata);

    // Generate warnings
    const warnings = generateCategoryChangeWarnings(
        oldCategoryId,
        newCategoryId,
        removedFields,
        addedFields,
        state.items
    );

    // Merge with existing warnings (preserve non-category-change warnings)
    const existingWarnings = state.warnings.filter(w => w.type !== 'category_change' && w.type !== 'metadata_invalidation');
    const allWarnings = [...existingWarnings, ...warnings];

    return {
        ...state,
        context: {
            ...state.context,
            categoryId: newCategoryId
        },
        items: updatedItems,
        globalMetadataDraft: cleanedGlobalMetadata,
        availableMetadataFields: newMetadataFields,
        warnings: allWarnings
    };
}

/**
 * Generate warnings for category change
 * 
 * Creates user-friendly warning messages describing what changed
 * when the category is switched.
 * 
 * @param {string | number | null} oldCategoryId - Previous category ID
 * @param {string | number | null} newCategoryId - New category ID
 * @param {string[]} removedFields - Field keys that were removed
 * @param {string[]} addedFields - Field keys that were added
 * @param {UploadItem[]} items - Upload items to check for affected overrides
 * @returns {UploadWarning[]} Array of warning messages
 */
function generateCategoryChangeWarnings(oldCategoryId, newCategoryId, removedFields, addedFields, items) {
    const warnings = [];

    // Main category change warning
    if (oldCategoryId !== newCategoryId) {
        warnings.push({
            type: 'category_change',
            message: newCategoryId 
                ? `Category changed. Metadata fields have been updated.`
                : `Category cleared. Metadata fields have been reset.`,
            severity: 'info'
        });
    }

    // Metadata invalidation warning
    if (removedFields.length > 0) {
        // Check if any items had overrides for removed fields
        const itemsWithRemovedOverrides = items.filter(item => {
            if (!item.isMetadataOverridden) return false;
            if (typeof item.isMetadataOverridden === 'boolean') {
                // If boolean true, we can't tell which fields, so assume some might be affected
                return removedFields.some(key => item.metadataDraft[key] !== undefined);
            }
            // Check if any removed field was overridden
            return removedFields.some(key => item.isMetadataOverridden[key]);
        });

        if (itemsWithRemovedOverrides.length > 0) {
            warnings.push({
                type: 'metadata_invalidation',
                message: `${removedFields.length} metadata field(s) removed from category: ${removedFields.join(', ')}. ` +
                         `Values for these fields have been cleared from ${itemsWithRemovedOverrides.length} file(s).`,
                severity: 'warning',
                affectedFields: removedFields,
                affectedFileIds: itemsWithRemovedOverrides.map(item => item.clientId)
            });
        } else {
            warnings.push({
                type: 'metadata_invalidation',
                message: `${removedFields.length} metadata field(s) removed from category: ${removedFields.join(', ')}.`,
                severity: 'info',
                affectedFields: removedFields
            });
        }
    }

    // New fields added (informational)
    if (addedFields.length > 0) {
        warnings.push({
            type: 'category_change',
            message: `${addedFields.length} new metadata field(s) available: ${addedFields.join(', ')}.`,
            severity: 'info',
            affectedFields: addedFields
        });
    }

    return warnings;
}

/**
 * Validate that all required metadata fields have values
 * 
 * Checks global metadata and per-file overrides to ensure
 * all required fields (as defined by MetadataField.required)
 * have non-empty values.
 * 
 * @param {UploadManagerState} state - Upload manager state
 * @returns {UploadWarning[]} Warnings for missing required fields
 */
export function validateRequiredMetadataFields(state) {
    const warnings = [];
    const requiredFields = state.availableMetadataFields.filter(f => f.required);

    if (requiredFields.length === 0) {
        return warnings;
    }

    // Check global metadata
    const missingGlobalFields = requiredFields.filter(field => {
        const value = state.globalMetadataDraft[field.key];
        return value === undefined || value === null || value === '' || 
               (Array.isArray(value) && value.length === 0);
    });

    if (missingGlobalFields.length > 0) {
        warnings.push({
            type: 'missing_required_field',
            message: `Missing required metadata fields: ${missingGlobalFields.map(f => f.label).join(', ')}`,
            severity: 'error',
            affectedFields: missingGlobalFields.map(f => f.key)
        });
    }

    // Check per-file overrides
    state.items.forEach(item => {
        const missingFields = requiredFields.filter(field => {
            // Check if field is overridden for this item
            const isOverridden = typeof item.isMetadataOverridden === 'object' 
                ? item.isMetadataOverridden[field.key]
                : (typeof item.isMetadataOverridden === 'boolean' && item.isMetadataOverridden 
                    ? item.metadataDraft[field.key] !== undefined
                    : false);

            if (isOverridden) {
                // Check override value
                const value = item.metadataDraft[field.key];
                return value === undefined || value === null || value === '' ||
                       (Array.isArray(value) && value.length === 0);
            } else {
                // Check global value
                const value = state.globalMetadataDraft[field.key];
                return value === undefined || value === null || value === '' ||
                       (Array.isArray(value) && value.length === 0);
            }
        });

        if (missingFields.length > 0) {
            warnings.push({
                type: 'missing_required_field',
                message: `File "${item.originalFilename}" missing required fields: ${missingFields.map(f => f.label).join(', ')}`,
                severity: 'error',
                affectedFields: missingFields.map(f => f.key),
                affectedFileIds: [item.clientId]
            });
        }
    });

    return warnings;
}

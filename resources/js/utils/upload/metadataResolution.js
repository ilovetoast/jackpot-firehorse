/**
 * Phase 3 Metadata Resolution Utilities
 * 
 * Pure functions for managing metadata drafts and resolution.
 * These functions have no side effects and are safe to use in any context.
 * 
 * @module metadataResolution
 */


/**
 * Apply global metadata to all items that don't have overrides
 * 
 * This function updates items in-place but does not mutate the state object itself.
 * It respects existing per-file overrides and only applies global values to fields
 * that haven't been overridden.
 * 
 * @param {UploadItem[]} items - Array of upload items to update
 * @param {Record<string, any>} globalMetadataDraft - Global metadata to apply
 * @returns {UploadItem[]} New array with updated items (items themselves are mutated for efficiency)
 * 
 * @example
 * const items = [
 *   { clientId: '1', metadataDraft: {}, isMetadataOverridden: false },
 *   { clientId: '2', metadataDraft: { color: 'red' }, isMetadataOverridden: { color: true } }
 * ];
 * const global = { color: 'blue', tags: ['photo'] };
 * const updated = applyGlobalMetadata(items, global);
 * // Item 1: metadataDraft = { color: 'blue', tags: ['photo'] }
 * // Item 2: metadataDraft = { color: 'red', tags: ['photo'] } (color preserved)
 */
export function applyGlobalMetadata(items, globalMetadataDraft) {
    return items.map(item => {
        // If item has no overrides, apply all global metadata
        if (!item.isMetadataOverridden || 
            (typeof item.isMetadataOverridden === 'boolean' && !item.isMetadataOverridden)) {
            return {
                ...item,
                metadataDraft: { ...globalMetadataDraft }
            };
        }

        // If item has per-field overrides, only apply non-overridden fields
        if (typeof item.isMetadataOverridden === 'object') {
            const newDraft = { ...item.metadataDraft };
            
            // Apply global values only for fields that aren't overridden
            Object.keys(globalMetadataDraft).forEach(key => {
                if (!item.isMetadataOverridden[key]) {
                    newDraft[key] = globalMetadataDraft[key];
                }
            });

            return {
                ...item,
                metadataDraft: newDraft
            };
        }

        // If boolean true, preserve existing overrides
        return item;
    });
}

/**
 * Override metadata for a specific item
 * 
 * Sets per-file metadata values and marks the specified fields as overridden.
 * This prevents future global metadata changes from overwriting these values.
 * 
 * @param {UploadItem} item - The upload item to update
 * @param {Record<string, any>} overrides - Metadata field overrides (key-value pairs)
 * @returns {UploadItem} New item with updated metadata and override flags
 * 
 * @example
 * const item = {
 *   clientId: '1',
 *   metadataDraft: { color: 'blue' },
 *   isMetadataOverridden: false
 * };
 * const updated = overrideItemMetadata(item, { color: 'red', tags: ['custom'] });
 * // Result: metadataDraft = { color: 'red', tags: ['custom'] }, isMetadataOverridden = { color: true, tags: true }
 */
export function overrideItemMetadata(item, overrides) {
    const newDraft = {
        ...item.metadataDraft,
        ...overrides
    };

    // Update override flags
    let newOverrideFlags;
    if (typeof item.isMetadataOverridden === 'boolean') {
        // Convert boolean to object if we're adding overrides
        newOverrideFlags = {};
        Object.keys(overrides).forEach(key => {
            newOverrideFlags[key] = true;
        });
    } else if (typeof item.isMetadataOverridden === 'object') {
        // Merge with existing override flags
        newOverrideFlags = {
            ...item.isMetadataOverridden
        };
        Object.keys(overrides).forEach(key => {
            newOverrideFlags[key] = true;
        });
    } else {
        // Initialize as object
        newOverrideFlags = {};
        Object.keys(overrides).forEach(key => {
            newOverrideFlags[key] = true;
        });
    }

    return {
        ...item,
        metadataDraft: newDraft,
        isMetadataOverridden: newOverrideFlags
    };
}

/**
 * Resolve effective metadata for an item
 * 
 * Returns the final metadata that will be applied to an asset.
 * Combines global metadata with per-file overrides.
 * 
 * @param {UploadItem} item - The upload item
 * @param {Record<string, any>} globalMetadataDraft - Global metadata
 * @returns {Record<string, any>} Effective metadata (global + overrides)
 * 
 * @example
 * const item = {
 *   metadataDraft: { color: 'red' },
 *   isMetadataOverridden: { color: true }
 * };
 * const global = { color: 'blue', tags: ['photo'] };
 * const effective = resolveEffectiveMetadata(item, global);
 * // Result: { color: 'red', tags: ['photo'] }
 */
export function resolveEffectiveMetadata(item, globalMetadataDraft) {
    // Start with global metadata
    const effective = { ...globalMetadataDraft };

    // Apply per-file overrides
    if (item.isMetadataOverridden) {
        if (typeof item.isMetadataOverridden === 'boolean' && item.isMetadataOverridden) {
            // All fields in metadataDraft are overrides
            Object.assign(effective, item.metadataDraft);
        } else if (typeof item.isMetadataOverridden === 'object') {
            // Only apply fields marked as overridden
            Object.keys(item.metadataDraft).forEach(key => {
                if (item.isMetadataOverridden[key]) {
                    effective[key] = item.metadataDraft[key];
                }
            });
        }
    }

    return effective;
}

/**
 * Mark a specific field as overridden for an item
 * 
 * Marks a field as overridden without changing its value.
 * Useful when you want to "lock" a field to prevent global changes.
 * 
 * @param {UploadItem} item - The upload item
 * @param {string} fieldKey - The metadata field key to mark as overridden
 * @returns {UploadItem} New item with updated override flags
 * 
 * @example
 * const item = {
 *   metadataDraft: { color: 'blue' },
 *   isMetadataOverridden: false
 * };
 * const updated = markFieldAsOverridden(item, 'color');
 * // Result: isMetadataOverridden = { color: true }
 */
export function markFieldAsOverridden(item, fieldKey) {
    let newOverrideFlags;

    if (typeof item.isMetadataOverridden === 'boolean') {
        if (item.isMetadataOverridden) {
            // Already true, convert to object
            newOverrideFlags = { [fieldKey]: true };
        } else {
            // Convert false to object with this field
            newOverrideFlags = { [fieldKey]: true };
        }
    } else if (typeof item.isMetadataOverridden === 'object') {
        // Merge with existing
        newOverrideFlags = {
            ...item.isMetadataOverridden,
            [fieldKey]: true
        };
    } else {
        // Initialize
        newOverrideFlags = { [fieldKey]: true };
    }

    return {
        ...item,
        isMetadataOverridden: newOverrideFlags
    };
}

/**
 * Remove override flag for a specific field
 * 
 * Allows a field to be controlled by global metadata again.
 * The field value remains in metadataDraft but will be overwritten by global changes.
 * 
 * @param {UploadItem} item - The upload item
 * @param {string} fieldKey - The metadata field key to unmark
 * @returns {UploadItem} New item with updated override flags
 */
export function unmarkFieldAsOverridden(item, fieldKey) {
    if (typeof item.isMetadataOverridden === 'boolean') {
        if (!item.isMetadataOverridden) {
            // Already false, no change needed
            return item;
        }
        // Boolean true means all fields in metadataDraft are overrides
        // Convert to object format, marking all current fields as overridden
        // except the one we're unmarking
        const newOverrideFlags = {};
        Object.keys(item.metadataDraft).forEach(key => {
            if (key !== fieldKey) {
                newOverrideFlags[key] = true;
            }
        });

        // If no overrides remain, convert to false
        if (Object.keys(newOverrideFlags).length === 0) {
            return {
                ...item,
                isMetadataOverridden: false
            };
        }

        return {
            ...item,
            isMetadataOverridden: newOverrideFlags
        };
    } else if (typeof item.isMetadataOverridden === 'object') {
        const newOverrideFlags = { ...item.isMetadataOverridden };
        delete newOverrideFlags[fieldKey];

        // If no overrides remain, convert to false
        if (Object.keys(newOverrideFlags).length === 0) {
            return {
                ...item,
                isMetadataOverridden: false
            };
        }

        return {
            ...item,
            isMetadataOverridden: newOverrideFlags
        };
    }

    return item;
}

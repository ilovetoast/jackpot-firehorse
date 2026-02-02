/**
 * Phase 3.3 Global Metadata Panel Component
 * 
 * UI component for editing global metadata that applies to all upload items.
 * Allows category selection and metadata field editing.
 * 
 * This is a PRESENTATIONAL component - uses uploadManager public API only.
 * 
 * @module GlobalMetadataPanel
 */

import { useState, useEffect } from 'react';
import { ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/react/24/outline';
import { filterActiveCategories } from '../utils/categoryUtils';

/**
 * GlobalMetadataPanel - Global metadata editing panel
 * 
 * @param {Object} props
 * @param {Object} props.uploadManager - Phase 3 upload manager instance
 * @param {Array} props.categories - Available categories array
 * @param {Function} [props.onCategoryChange] - Callback when category changes (optional)
 * @param {string} [props.className] - Additional CSS classes
 */
export default function GlobalMetadataPanel({ 
    uploadManager, 
    categories = [],
    onCategoryChange = null,
    className = '',
    disabled = false,
    inline = false
}) {
    const { 
        hasItems, 
        context, 
        availableMetadataFields, 
        globalMetadataDraft,
        warnings,
        changeCategory,
        setGlobalMetadata,
        validateMetadata
    } = uploadManager;

    // Filter categories using the reusable utility function
    // This ensures consistency across all components (sidebar, dropdown, etc.)
    const filteredCategories = filterActiveCategories(categories);

    // Get category change and metadata invalidation warnings
    const categoryWarnings = warnings.filter(
        w => w.type === 'category_change' || w.type === 'metadata_invalidation'
    );

    // Get validation warnings
    const validationWarnings = warnings.filter(
        w => w.type === 'missing_required_field'
    );

    // Don't render if no items
    if (!hasItems) {
        return null;
    }

    /**
     * Handle category change
     */
    const handleCategoryChange = (categoryId) => {
        // Find category to get metadata fields (if available)
        // Note: In real implementation, metadata fields might come from category config
        // For now, we'll pass empty array and let the caller provide fields via onCategoryChange
        const categoryIdValue = categoryId === '' ? null : categoryId;
        
        // Call upload manager's changeCategory
        // Metadata fields should be provided by parent component or fetched
        changeCategory(categoryIdValue, []); // Empty array - parent should provide fields
        
        // Call optional callback
        if (onCategoryChange) {
            onCategoryChange(categoryIdValue);
        }

        // Validate after category change
        setTimeout(() => {
            validateMetadata();
        }, 100);
    };

    /**
     * Handle metadata field change
     */
    const handleFieldChange = (fieldKey, value) => {
        setGlobalMetadata(fieldKey, value);
        
        // Validate after change
        setTimeout(() => {
            validateMetadata();
        }, 100);
    };

    if (inline) {
        return (
            <div className={className}>
                <div className="flex items-center gap-2 min-w-0">
                    <label htmlFor="category-select" className="flex-shrink-0 w-24 text-sm font-medium text-gray-700 whitespace-nowrap">
                        Category: <span className="text-red-500">*</span>
                    </label>
                    <select
                        id="category-select"
                        value={context.categoryId || ''}
                        onChange={(e) => handleCategoryChange(e.target.value)}
                        disabled={disabled}
                        className={`flex-1 min-w-0 rounded-md border border-gray-300 bg-white py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    >
                        <option value="">Select</option>
                        {filteredCategories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>
                {categoryWarnings.length > 0 && (
                    <div className="rounded bg-yellow-50 border border-yellow-200 px-2 py-1 mt-1">
                        {categoryWarnings.map((warning, index) => (
                            <p key={index} className="text-xs text-yellow-800">{warning.message}</p>
                        ))}
                    </div>
                )}
                {validationWarnings.length > 0 && (
                    <div className="rounded bg-red-50 border border-red-200 px-2 py-1 mt-1">
                        {validationWarnings.map((warning, index) => (
                            <p key={index} className="text-xs text-red-700">{warning.message}</p>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className={`bg-white border border-gray-200 rounded-lg shadow-sm ${className}`}>
            {/* Header */}
            <div className="px-4 py-3 border-b border-gray-200">
                <h3 className="text-sm font-medium text-gray-900">
                    Global Metadata
                </h3>
                <p className="text-xs text-gray-500 mt-1">
                    Applies to all files in this batch
                </p>
            </div>

            <div className="px-4 py-4 space-y-6">
                {/* Category Selector */}
                <div>
                    <label htmlFor="category-select" className="block text-sm font-medium text-gray-700 mb-1">
                        Category <span className="text-red-500">*</span>
                    </label>
                    <select
                        id="category-select"
                        value={context.categoryId || ''}
                        onChange={(e) => handleCategoryChange(e.target.value)}
                        disabled={disabled}
                        className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm ${
                            disabled ? 'bg-gray-50 text-gray-500 cursor-not-allowed opacity-60' : ''
                        }`}
                    >
                        <option value="">Select a category</option>
                        {filteredCategories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                    {!context.categoryId && (
                        <p className="mt-1 text-xs text-gray-500">
                            Category is required to assign metadata fields
                        </p>
                    )}
                </div>

                {/* Category Change Warnings */}
                {categoryWarnings.length > 0 && (
                    <div className="rounded-md bg-yellow-50 border border-yellow-200 p-3">
                        <div className="flex">
                            <ExclamationTriangleIcon className="h-5 w-5 text-yellow-600 flex-shrink-0" />
                            <div className="ml-3 flex-1">
                                <h4 className="text-sm font-medium text-yellow-800">
                                    Category Change Notice
                                </h4>
                                <div className="mt-2 text-sm text-yellow-700 space-y-1">
                                    {categoryWarnings.map((warning, index) => (
                                        <p key={index}>{warning.message}</p>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Metadata Fields - Removed: Now handled by MetadataGroups component (dynamic async) */}

                {/* Validation Warnings */}
                {validationWarnings.length > 0 && (
                    <div className="rounded-md bg-red-50 border border-red-200 p-3">
                        <div className="flex">
                            <ExclamationTriangleIcon className="h-5 w-5 text-red-600 flex-shrink-0" />
                            <div className="ml-3 flex-1">
                                <h4 className="text-sm font-medium text-red-800">
                                    Validation Errors
                                </h4>
                                <div className="mt-2 text-sm text-red-700 space-y-1">
                                    {validationWarnings.map((warning, index) => (
                                        <p key={index}>{warning.message}</p>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

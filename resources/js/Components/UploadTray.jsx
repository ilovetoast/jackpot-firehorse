/**
 * Phase 3.2 Upload Tray Component
 * 
 * Read-only UI component that displays upload state from Phase 3 Upload Manager.
 * Shows upload progress, status, errors, and expandable metadata view.
 * 
 * This is a PRESENTATIONAL component only - no business logic, no side effects.
 * 
 * @module UploadTray
 */

import { useState } from 'react';
import UploadItemRow from './UploadItemRow';

/**
 * UploadTray - Read-only upload state visualization
 * 
 * Displays a tray of upload items with progress, status, and expandable details.
 * Only renders if there are items in the batch.
 * 
 * @param {Object} props
 * @param {Object} props.uploadManager - Phase 3 upload manager instance (from usePhase3UploadManager)
 * @param {Function} [props.onRemoveItem] - Callback when an item should be removed
 * @param {string} [props.className] - Additional CSS classes
 */
export default function UploadTray({ uploadManager, onRemoveItem, className = '', disabled = false }) {
    const { hasItems, items } = uploadManager;

    // Don't render if no items
    if (!hasItems) {
        return null;
    }

    /**
     * Phase 3.0B: Prop stability requirements
     * 
     * WHY PROP STABILITY MATTERS:
     * - onRemoveItem must be wrapped in useCallback in parent (already done in UploadAssetDialog)
     * - uploadManager reference must be stable (it is - comes from useMemo)
     * - Inline objects/functions would break memoization (cause unnecessary re-renders)
     * 
     * The custom comparator in UploadItemRow doesn't compare callbacks (uploadManager, onRemove)
     * because these are stable references. Only item data fields are compared.
     */

    return (
        <div className={`bg-white border border-gray-200 rounded-lg shadow-sm ${className}`}>
            <div className="px-4 py-3 border-b border-gray-200">
                <h3 className="text-sm font-medium text-gray-900">
                    Uploads ({items.length})
                </h3>
            </div>
            
            <div className="divide-y divide-gray-200">
                {items.map((item) => (
                    <UploadItemRow
                        key={item.clientId}
                        item={item}
                        uploadManager={uploadManager}
                        onRemove={onRemoveItem}
                        disabled={disabled}
                    />
                ))}
            </div>
        </div>
    );
}

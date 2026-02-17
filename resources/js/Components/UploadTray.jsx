/**
 * Phase 3.2 Upload Tray Component
 *
 * Read-only UI component that displays upload state from Phase 3 Upload Manager.
 * Shows upload progress, status, errors, and expandable metadata view.
 *
 * When many files are uploading (> COLLAPSE_THRESHOLD), defaults to a collapsed
 * summary view to avoid lag from rendering dozens of progress bars. User can
 * expand to see and edit individual files.
 *
 * @module UploadTray
 */

import { useState, useMemo } from 'react';
import { ChevronDownIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import UploadItemRow from './UploadItemRow';

/** When item count exceeds this, show collapsed summary by default (avoids lag) */
const COLLAPSE_THRESHOLD = 12;

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
    const [isExpanded, setIsExpanded] = useState(false);

    // Don't render if no items
    if (!hasItems) {
        return null;
    }

    const shouldCollapse = items.length > COLLAPSE_THRESHOLD;
    const showCollapsed = shouldCollapse && !isExpanded;

    // Aggregate progress for collapsed view (avoids rendering N progress bars)
    const aggregate = useMemo(() => {
        let complete = 0;
        let failed = 0;
        let progressSum = 0;
        for (const item of items) {
            if (item.uploadStatus === 'complete') {
                complete++;
                progressSum += 100;
            } else if (item.uploadStatus === 'failed') {
                failed++;
            } else {
                progressSum += item.progress ?? 0;
            }
        }
        const total = items.length;
        const pct = total > 0 ? Math.round(progressSum / total) : 0;
        return { complete, failed, total, pct };
    }, [items]);

    return (
        <div className={`bg-white border border-gray-200 rounded-lg shadow-sm ${className}`}>
            <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between gap-2">
                <h3 className="text-sm font-medium text-gray-900">
                    Uploads ({items.length})
                </h3>
                {shouldCollapse && (
                    <button
                        type="button"
                        onClick={() => setIsExpanded((e) => !e)}
                        className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        {showCollapsed ? (
                            <>
                                <ChevronRightIcon className="h-4 w-4" />
                                Show all {items.length} files
                            </>
                        ) : (
                            <>
                                <ChevronDownIcon className="h-4 w-4" />
                                Collapse
                            </>
                        )}
                    </button>
                )}
            </div>

            {showCollapsed ? (
                <div className="px-4 py-4">
                    <div className="flex items-center gap-3">
                        <div className="flex-1 min-w-0">
                            <div className="h-2 rounded-full bg-gray-200 overflow-hidden">
                                <div
                                    className="h-full bg-indigo-600 transition-[width] duration-300"
                                    style={{ width: `${aggregate.pct}%` }}
                                />
                            </div>
                        </div>
                        <span className="text-xs font-medium text-gray-600 tabular-nums shrink-0">
                            {aggregate.pct}%
                        </span>
                    </div>
                    <p className="mt-2 text-xs text-gray-500">
                        {aggregate.complete} of {aggregate.total} complete
                        {aggregate.failed > 0 && (
                            <span className="text-red-600 ml-2">{aggregate.failed} failed</span>
                        )}
                    </p>
                    <p className="mt-1 text-xs text-gray-400">
                        Expand to view or edit individual files
                    </p>
                </div>
            ) : (
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
            )}
        </div>
    );
}

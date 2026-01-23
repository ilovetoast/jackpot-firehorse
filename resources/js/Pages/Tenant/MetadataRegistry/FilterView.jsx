/**
 * Filter View Component
 * 
 * ⚠️ READ-ONLY EXPLAINER (No Controls)
 * 
 * This tab is intentionally read-only and must never gain interactive controls.
 * Filter visibility and primary placement are configured per category in the By Category tab.
 * 
 * Purpose:
 * - Explain what asset grid filters are
 * - Describe primary vs secondary filter behavior
 * - Provide guidance on how to configure filters
 * - Link to By Category tab for actual configuration
 * 
 * What this tab CANNOT do:
 * - Toggle filter visibility
 * - Configure primary/secondary placement
 * - Display interactive tables or toggles
 * - Persist any filter-related state
 * 
 * ARCHITECTURAL RULE: Primary vs secondary filter placement MUST be category-scoped.
 * A field may be primary in Photography but secondary in Logos.
 */

import {
    FunnelIcon,
    InformationCircleIcon,
    ArrowRightIcon,
} from '@heroicons/react/24/outline'

export default function FilterView({ 
    onSwitchToByCategory 
}) {
    return (
        <div className="px-6 py-4 space-y-6">
            {/* Header */}
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
                <div className="flex items-start gap-3">
                    <FunnelIcon className="w-6 h-6 text-blue-600 mt-0.5 flex-shrink-0" />
                    <div className="flex-1">
                        <h3 className="text-lg font-semibold text-blue-900 mb-2">
                            Asset Grid Filters
                        </h3>
                        <p className="text-sm text-blue-800 mb-4">
                            Metadata fields can appear as filters in the asset grid, helping users find assets by their metadata values.
                        </p>
                        <button
                            onClick={onSwitchToByCategory}
                            className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                        >
                            Configure in By Category
                            <ArrowRightIcon className="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            {/* Explainer Content */}
            <div className="bg-white border border-gray-200 rounded-lg p-6 space-y-6">
                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-2">
                        What are Asset Grid Filters?
                    </h4>
                    <p className="text-sm text-gray-700">
                        Filters appear in the asset grid interface, allowing users to narrow down assets by metadata values. 
                        For example, if you have a "Photo Type" field, users can filter assets to show only "Action" photos.
                    </p>
                </div>

                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-2">
                        Primary vs Secondary Filters
                    </h4>
                    <div className="space-y-2 text-sm text-gray-700">
                        <p>
                            <strong>Primary filters</strong> appear inline in the asset grid filter bar (always visible). 
                            These are the most important filters for a category and are immediately accessible.
                        </p>
                        <p>
                            <strong>Secondary filters</strong> appear in the "More filters" expandable section. 
                            These are still accessible but require one click to reveal.
                        </p>
                        <p className="text-xs text-gray-500 italic mt-2">
                            Note: Filter placement is category-scoped. A field can be primary in one category and secondary in another.
                        </p>
                    </div>
                </div>

                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-2">
                        How to Configure Filters
                    </h4>
                    <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <div className="flex items-start gap-2">
                            <InformationCircleIcon className="w-5 h-5 text-gray-600 mt-0.5 flex-shrink-0" />
                            <div className="text-sm text-gray-700 space-y-2">
                                <p>
                                    Filter visibility and primary placement are configured per category in the <strong>By Category</strong> tab:
                                </p>
                                <ol className="list-decimal list-inside space-y-1 ml-2">
                                    <li>Select a category from the list</li>
                                    <li>Enable the field for that category (toggle switch)</li>
                                    <li>Check the "Filter" checkbox to make it available as a filter</li>
                                    <li>Optionally check "Primary (for this category)" to show it inline in the filter bar</li>
                                </ol>
                                <p className="text-xs text-gray-500 italic mt-2">
                                    This category-scoped approach allows different filter configurations for different categories.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 className="text-sm font-semibold text-gray-900 mb-2">
                        What Filters Don't Control
                    </h4>
                    <ul className="list-disc list-inside space-y-1 text-sm text-gray-700 ml-2">
                        <li>How metadata is populated (automated fields remain automated)</li>
                        <li>Upload and edit forms (controlled separately in the same interface)</li>
                        <li>Asset detail displays</li>
                        <li>Category enablement (whether a field is available for a category)</li>
                    </ul>
                </div>
            </div>
        </div>
    )
}

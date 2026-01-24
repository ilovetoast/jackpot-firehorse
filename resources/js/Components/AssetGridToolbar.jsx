/**
 * AssetGridToolbar Component
 * 
 * Professional toolbar for asset grid display controls.
 * This component handles UI-only grid presentation settings.
 * 
 * Features:
 * - Search input (coming soon - Phase 6.1)
 * - Primary metadata filters (between search and controls)
 * - "Show Info" toggle (controls asset card metadata visibility)
 * - Grid size controls (card size control)
 * - Bulk selection toggle (if applicable)
 * - More filters section (optional)
 * - Responsive layout (mobile-first)
 * 
 * @param {Object} props
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {Function} props.onToggleInfo - Callback to toggle info visibility
 * @param {number} props.cardSize - Current card size in pixels (160-360, default 220)
 * @param {Function} props.onCardSizeChange - Callback when card size changes
 * @param {string} props.primaryColor - Brand primary color for slider styling
 * @param {Array} props.filterable_schema - Filterable metadata schema from backend
 * @param {number|null} props.selectedCategoryId - Currently selected category ID
 * @param {Object} props.available_values - Map of field_key to available values
 * @param {React.ReactNode} props.moreFiltersContent - Optional more filters section content
 * @param {boolean} props.showMoreFilters - Whether to show the more filters section
 */
import { useState } from 'react'
import AssetGridMetadataPrimaryFilters from './AssetGridMetadataPrimaryFilters'
import { InformationCircleIcon } from '@heroicons/react/24/outline'

export default function AssetGridToolbar({
    showInfo = true,
    onToggleInfo = () => {},
    cardSize = 220,
    onCardSizeChange = () => {},
    primaryColor = '#6366f1', // Default indigo-600
    bulkSelectedCount = 0, // Phase 2 – Step 7
    onBulkEdit = null, // Phase 2 – Step 7
    onToggleBulkMode = null, // Phase 2 – Step 7
    isBulkMode = false, // Phase 2 – Step 7
    filterable_schema = [], // Primary metadata filters
    selectedCategoryId = null, // Current category
    available_values = {}, // Available filter values
    moreFiltersContent = null, // More filters section content
    showMoreFilters = false, // Whether to show more filters section
}) {
    // Static filter chip placeholders (non-functional)
    const filterChips = ['Nature', 'Space', 'Color-grading', 'Amsterdam', 'Summer']
    const [isSearchFocused, setIsSearchFocused] = useState(false)
    
    // Grid size button group - 4 discrete settings
    const SIZE_PRESETS = [160, 220, 280, 360] // 4 discrete size options
    const DEFAULT_PRESET_INDEX = 1 // 220px is the default (index 1)
    
    // Snap cardSize to nearest preset
    const snapToPreset = (value) => {
        return SIZE_PRESETS.reduce((prev, curr) => 
            Math.abs(curr - value) < Math.abs(prev - value) ? curr : prev
        )
    }
    
    // Get current preset index (0-3)
    const currentPresetIndex = SIZE_PRESETS.indexOf(snapToPreset(cardSize))
    
    // Grid size icons - representing different grid densities
    const SizeIcon = ({ size, className = "h-4 w-4" }) => {
        // Different grid patterns for each size
        const gridPatterns = {
            small: (
                <svg className={className} fill="none" viewBox="0 0 16 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="6" height="6" rx="0.5" />
                    <rect x="9" y="1" width="6" height="6" rx="0.5" />
                    <rect x="1" y="9" width="6" height="6" rx="0.5" />
                    <rect x="9" y="9" width="6" height="6" rx="0.5" />
                </svg>
            ),
            medium: (
                <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="5" height="6" rx="0.5" />
                    <rect x="7.5" y="1" width="5" height="6" rx="0.5" />
                    <rect x="14" y="1" width="5" height="6" rx="0.5" />
                    <rect x="1" y="9" width="5" height="6" rx="0.5" />
                    <rect x="7.5" y="9" width="5" height="6" rx="0.5" />
                    <rect x="14" y="9" width="5" height="6" rx="0.5" />
                </svg>
            ),
            large: (
                <svg className={className} fill="none" viewBox="0 0 24 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="6.5" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="12" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="17.5" y="1" width="4.5" height="6" rx="0.5" />
                    <rect x="1" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="6.5" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="12" y="9" width="4.5" height="6" rx="0.5" />
                    <rect x="17.5" y="9" width="4.5" height="6" rx="0.5" />
                </svg>
            ),
            xlarge: (
                <svg className={className} fill="none" viewBox="0 0 28 16" stroke="currentColor" strokeWidth={1.5}>
                    <rect x="1" y="1" width="4" height="6" rx="0.5" />
                    <rect x="6" y="1" width="4" height="6" rx="0.5" />
                    <rect x="11" y="1" width="4" height="6" rx="0.5" />
                    <rect x="16" y="1" width="4" height="6" rx="0.5" />
                    <rect x="21" y="1" width="4" height="6" rx="0.5" />
                    <rect x="1" y="9" width="4" height="6" rx="0.5" />
                    <rect x="6" y="9" width="4" height="6" rx="0.5" />
                    <rect x="11" y="9" width="4" height="6" rx="0.5" />
                    <rect x="16" y="9" width="4" height="6" rx="0.5" />
                    <rect x="21" y="9" width="4" height="6" rx="0.5" />
                </svg>
            ),
        }
        
        return gridPatterns[size] || gridPatterns.medium
    }

    return (
        <div className={`bg-white ${showMoreFilters ? 'border-b border-gray-200' : 'border-b border-gray-200'}`}>
            {/* Primary Toolbar Row */}
            <div className="px-4 py-4 sm:px-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    {/* Search Input - Coming Soon */}
                    <div className="flex-1 flex items-center gap-3">
                        <div className={`relative transition-all duration-200 ${isSearchFocused ? 'flex-1 min-w-[200px]' : 'w-48 sm:w-56'}`}>
                            <input
                                type="text"
                                placeholder="Search assets… (Coming soon)"
                                className="block w-full px-3 py-2 text-sm bg-gray-50 rounded-lg border border-gray-200 text-gray-400 placeholder-gray-400 focus:outline-none transition-colors cursor-not-allowed"
                                onFocus={() => setIsSearchFocused(true)}
                                onBlur={() => setIsSearchFocused(false)}
                                readOnly
                                disabled
                                title="Search functionality coming in Phase 6.1"
                            />
                            {/* TODO: Phase 6.1 — Wire search input to backend filtering */}
                        </div>
                        
                        {/* Primary Metadata Filters - Between search and controls */}
                        <div className="flex items-center gap-2 min-w-[100px]">
                            <AssetGridMetadataPrimaryFilters
                                filterable_schema={filterable_schema}
                                selectedCategoryId={selectedCategoryId}
                                available_values={available_values}
                                assetType="image"
                                compact={true}
                            />
                        </div>
                    </div>

                    {/* Controls - Right Side on Desktop */}
                    <div className="flex items-center gap-4 flex-shrink-0">
                        {/* Phase 2 – Step 7: Bulk Actions */}
                        {onToggleBulkMode && (
                            <>
                                <button
                                    type="button"
                                    onClick={onToggleBulkMode}
                                    className={`px-3 py-1.5 text-sm font-medium rounded-md transition-colors ${
                                        isBulkMode
                                            ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200'
                                            : 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    {isBulkMode ? 'Cancel Selection' : 'Select Multiple'}
                                </button>
                                {isBulkMode && bulkSelectedCount > 0 && onBulkEdit && (
                                    <button
                                        type="button"
                                        onClick={onBulkEdit}
                                        className="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                    >
                                        Edit Metadata ({bulkSelectedCount})
                                    </button>
                                )}
                            </>
                        )}

                        {/* Show Info Toggle */}
                        <label className="flex items-center gap-2 cursor-pointer">                        
                            <InformationCircleIcon className="h-4 w-4 text-gray-700" title="Show info" />

                            <button
                                type="button"
                                role="switch"
                                aria-checked={showInfo}
                                onClick={onToggleInfo}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2`}
                                style={{
                                    backgroundColor: showInfo ? primaryColor : '#d1d5db',
                                }}
                                onFocus={(e) => {
                                    e.currentTarget.style.setProperty('--tw-ring-color', primaryColor)
                                }}
                            >
                                <span
                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition-transform duration-200 ease-in-out ${
                                        showInfo ? 'translate-x-5' : 'translate-x-0'
                                    }`}
                                />
                            </button>                        
                        </label>

                        {/* Grid Size Button Group */}
                        <div className="flex items-center gap-2.5">                        
                            <div className="inline-flex rounded-md shadow-sm" role="group" aria-label="Grid size">
                                {SIZE_PRESETS.map((size, index) => {
                                    const isSelected = currentPresetIndex === index
                                    const iconSizes = ['small', 'medium', 'large', 'xlarge']
                                    
                                    return (
                                        <button
                                            key={size}
                                            type="button"
                                            onClick={() => onCardSizeChange(size)}
                                            className={`
                                                px-3 py-1.5 text-sm font-medium transition-all
                                                flex items-center justify-center
                                                ${index === 0 ? 'rounded-l-md' : ''}
                                                ${index === SIZE_PRESETS.length - 1 ? 'rounded-r-md' : ''}
                                                ${index > 0 ? '-ml-px' : ''}
                                                ${isSelected 
                                                    ? 'bg-white text-gray-900 shadow-sm z-10' 
                                                    : 'bg-white text-gray-500 hover:bg-gray-50 hover:text-gray-700'
                                                }
                                                border border-gray-300
                                                focus:z-10 focus:outline-none focus:ring-2 focus:ring-offset-0
                                            `}
                                            style={isSelected ? {
                                                borderColor: primaryColor,
                                                '--tw-ring-color': primaryColor,
                                            } : {}}
                                            aria-pressed={isSelected}
                                            aria-label={`${iconSizes[index]} size`}
                                            title={`${iconSizes[index].charAt(0).toUpperCase() + iconSizes[index].slice(1)} size`}
                                        >
                                            <SizeIcon size={iconSizes[index]} />
                                        </button>
                                    )
                                })}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* More Filters Section - Integrated into toolbar */}
            {showMoreFilters && moreFiltersContent && (
                <div className="border-t border-gray-200">
                    {moreFiltersContent}
                </div>
            )}
        </div>
    )
}
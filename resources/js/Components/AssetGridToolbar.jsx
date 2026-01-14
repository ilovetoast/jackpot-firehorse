/**
 * AssetGridToolbar Component
 * 
 * Professional toolbar for asset grid controls.
 * This is a UI-only component — no backend filtering or logic.
 * 
 * Features:
 * - Search input (placeholder, non-functional)
 * - Filter chips (static placeholders)
 * - "Show Info" toggle (controls asset card metadata visibility)
 * - Grid scale slider (card size control)
 * - Optional "Refresh thumbnails" button (triggers Inertia reload)
 * - Responsive layout (mobile-first)
 * 
 * @param {Object} props
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {Function} props.onToggleInfo - Callback to toggle info visibility
 * @param {number} props.cardSize - Current card size in pixels (160-360, default 220)
 * @param {Function} props.onCardSizeChange - Callback when card size changes
 * @param {string} props.primaryColor - Brand primary color for slider styling
 * @param {Function} props.onRefreshThumbnails - Optional callback to refresh thumbnails (triggers router.reload)
 */
import { useState } from 'react'
import { ArrowPathIcon } from '@heroicons/react/24/outline'

export default function AssetGridToolbar({
    showInfo = true,
    onToggleInfo = () => {},
    cardSize = 220,
    onCardSizeChange = () => {},
    primaryColor = '#6366f1', // Default indigo-600
    onRefreshThumbnails = null, // Optional: callback to refresh thumbnails
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
        <div className="bg-white border-b border-gray-200 px-4 py-4 sm:px-6">
            {/* Toolbar Row - Stacks on Mobile, Single Row on Desktop */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                {/* Search + Filter Chips - Scrollable on Mobile */}
                <div className="flex-1 flex items-center gap-3 overflow-x-auto">
                    {/* Search Input - Expandable */}
                    <div className={`relative transition-all duration-200 ${isSearchFocused ? 'flex-1 min-w-[200px]' : 'w-48 sm:w-56'}`}>
                        <input
                            type="text"
                            placeholder="Search assets…"
                            className="block w-full px-4 py-2 bg-white rounded-xl border border-gray-200 text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-300 transition-colors cursor-not-allowed"
                            onFocus={() => setIsSearchFocused(true)}
                            onBlur={() => setIsSearchFocused(false)}
                            readOnly
                        />
                        {/* TODO: Phase 6.1 — Wire search input to backend filtering */}
                    </div>
                    
                    {/* Filter Chips - Scrollable on Mobile */}
                    <div className="flex gap-2 min-w-max sm:min-w-0">
                        {filterChips.map((chip) => (
                            <button
                                key={chip}
                                type="button"
                                className="inline-flex items-center px-3 py-1.5 rounded-full bg-gray-100 text-sm font-medium text-gray-700 hover:bg-gray-200 transition-colors whitespace-nowrap cursor-default"
                                onClick={(e) => e.preventDefault()} // Prevent any action
                            >
                                {chip}
                                {/* TODO: Phase 6.2 — Wire filter chips to backend query params */}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Controls - Right Side on Desktop */}
                <div className="flex items-center gap-4 flex-shrink-0">
                    {/* Optional: Refresh Thumbnails Button */}
                    {onRefreshThumbnails && (
                        <button
                            type="button"
                            onClick={onRefreshThumbnails}
                            className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                            title="Refresh thumbnails (reloads asset data)"
                        >
                            <ArrowPathIcon className="h-4 w-4" />
                            <span className="hidden sm:inline">Refresh</span>
                        </button>
                    )}

                    {/* Show Info Toggle */}
                    <label className="flex items-center gap-2 cursor-pointer">
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
                        <span className="text-sm font-medium text-gray-700">Show info</span>
                    </label>

                    {/* Grid Size Button Group */}
                    <div className="flex items-center gap-2.5">
                        <span className="text-sm font-medium text-gray-700">Size:</span>
                        <div className="inline-flex rounded-md shadow-sm" role="group">
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
    )
}
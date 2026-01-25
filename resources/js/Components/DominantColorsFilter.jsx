/**
 * Dominant Colors Filter Component
 *
 * Renders color tiles for filtering by dominant colors.
 * Each tile represents a hex color that can be selected/deselected.
 * Filters assets where ANY dominant color matches selected tiles.
 *
 * Features:
 * - Multi-select color tiles
 * - Tiles sourced dynamically from available_values
 * - Tooltip shows hex value
 * - Selected tiles have visual indication
 */

import { useMemo } from 'react'

export default function DominantColorsFilter({ value, onChange, availableValues = [], compact = false }) {
    // Extract unique hex colors from available_values
    // For multiselect fields, backend extracts individual items from arrays
    // So availableValues will be an array of color objects: [{hex, rgb, coverage}, ...]
    // OR it could be an array of color arrays: [[{hex, ...}, ...], ...]
    const uniqueColors = useMemo(() => {
        const colorSet = new Set()
        
        if (!availableValues || !Array.isArray(availableValues) || availableValues.length === 0) {
            return []
        }
        
        availableValues.forEach(item => {
            if (Array.isArray(item)) {
                // If item is an array, it's either a color array or array of color objects
                item.forEach(color => {
                    if (color && typeof color === 'object' && color.hex) {
                        colorSet.add(color.hex)
                    } else if (typeof color === 'string' && color.startsWith('#')) {
                        // Handle case where hex strings are directly in array
                        colorSet.add(color)
                    }
                })
            } else if (item && typeof item === 'object' && item.hex) {
                // Item is a color object directly (most common case from backend)
                colorSet.add(item.hex)
            } else if (typeof item === 'string' && item.startsWith('#')) {
                // Item is a hex string directly
                colorSet.add(item)
            }
        })
        
        // Convert to sorted array for consistent display
        return Array.from(colorSet).sort()
    }, [availableValues])
    
    // Current selected values (array of hex strings)
    const selectedColors = Array.isArray(value) ? value : (value ? [value] : [])
    
    const handleColorToggle = (hex) => {
        if (selectedColors.includes(hex)) {
            // Deselect
            const newSelection = selectedColors.filter(c => c !== hex)
            onChange(newSelection.length > 0 ? newSelection : null)
        } else {
            // Select
            onChange([...selectedColors, hex])
        }
    }
    
    // If no colors found, return null (filter won't show if no values)
    // This is correct behavior - filters only show when they have available values
    if (uniqueColors.length === 0) {
        return null
    }
    
    return (
        <div className={`flex flex-wrap items-center ${compact ? 'gap-1' : 'gap-1.5'} ${compact ? '' : 'p-2 bg-gray-50 rounded-lg border border-gray-200'}`}>
            {uniqueColors.map((hex) => {
                const isSelected = selectedColors.includes(hex)
                
                return (
                    <button
                        key={hex}
                        type="button"
                        onClick={() => handleColorToggle(hex)}
                        className={`
                            ${compact ? 'w-4 h-4' : 'w-6 h-6'} rounded-sm border-2 flex-shrink-0
                            transition-all hover:scale-110 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1
                            ${isSelected 
                                ? 'border-indigo-600 ring-2 ring-indigo-200 shadow-md' 
                                : 'border-gray-300 hover:border-gray-400'
                            }
                        `}
                        style={{ backgroundColor: hex }}
                        title={hex}
                        aria-label={`${isSelected ? 'Deselect' : 'Select'} color ${hex}`}
                    >
                        {isSelected && (
                            <svg 
                                className="w-full h-full text-white drop-shadow-md" 
                                fill="none" 
                                viewBox="0 0 24 24" 
                                stroke="currentColor"
                                strokeWidth={compact ? 2.5 : 3}
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        )}
                    </button>
                )
            })}
        </div>
    )
}
